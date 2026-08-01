<?php
/**
 * Nexora Engine — Setup Wizard Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Wizard {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Run pre-flight checks.
	 *
	 * @return array{status: string, checks: array}
	 */
	public function get_preflight_data(): array {
		$checks = [
			'php_version' => [
				'label'   => 'PHP Version (8.0+)',
				'pass'    => version_compare( PHP_VERSION, '8.0.0', '>=' ),
				'current' => PHP_VERSION,
				'desc'    => 'Nexora requires PHP 8.0 for modern performance features.'
			],
			'wp_version' => [
				'label'   => 'WordPress Version (6.0+)',
				'pass'    => version_compare( $GLOBALS['wp_version'], '6.0.0', '>=' ),
				'current' => $GLOBALS['wp_version'],
				'desc'    => 'Optimised for block-editor and modern hook support.'
			],
			'multisite' => [
				'label'   => 'Multisite Support',
				'pass'    => true,
				'current' => is_multisite() ? 'Network (' . get_blog_count() . ' sites)' : 'Single Site',
				'desc'    => 'Multisite networks use the network drop-in and fleet dashboard (Pro).'
			],
			'uploads_writable' => [
				'label'   => 'Uploads Directory Writable',
				'pass'    => wp_is_writable( wp_upload_dir()['basedir'] ), // phpcs:ignore WordPress.WP.AlternativeFunctions
				'current' => wp_is_writable( wp_upload_dir()['basedir'] ) ? 'Writable' : 'Locked', // phpcs:ignore WordPress.WP.AlternativeFunctions
				'desc'    => 'Needed to store captured static HTML files.'
			],
			'config_writable' => [
				'label'   => 'wp-config.php Writable',
				'pass'    => $this->is_wp_config_writable(),
				'current' => $this->is_wp_config_writable() ? 'Writable' : 'Read-only',
				'desc'    => 'Recommended for auto-enabling the drop-in cache.'
			]
		];

		$all_pass = true;
		foreach ( $checks as $check ) {
			if ( ! $check['pass'] ) {
				$all_pass = false;
				break;
			}
		}

		return [
			'status' => $all_pass ? 'pass' : 'fail',
			'checks' => $checks
		];
	}

	/**
	 * Detect active conflicts.
	 *
	 * @return array List of conflicting plugins and their status.
	 */
	public function get_active_conflicts(): array {
		$detector = new NEXENG_Conflict_Detector();
		$conflicts = $detector->get_conflicts( true );
		
		// Add drop-in check specifically
		$dropin_status = NEXENG_Dropin::status();
		if ( $dropin_status === 'foreign' ) {
			$owner = NEXENG_Dropin::detect_conflict() ?: 'Unknown Caching Plugin';
			$conflicts[] = [
				'slug'     => 'foreign-dropin',
				'name'     => $owner,
				'category' => 'caching',
				'severity' => 'high',
				'reason'   => 'Another plugin owns advanced-cache.php. This prevents Nexora from serving static files.',
				'fix'      => 'Disable the page-caching feature in ' . $owner . ' or remove advanced-cache.php manually.'
			];
		}

		return $conflicts;
	}

	/**
	 * Can this conflict be auto-fixed from the wizard?
	 */
	public function conflict_can_auto_fix( array $conflict ): bool {
		return stripos( $conflict['slug'], 'hummingbird' ) !== false
			|| stripos( $conflict['name'], 'hummingbird' ) !== false;
	}

	/**
	 * Attempt to resolve a known conflict automatically.
	 *
	 * @param string $slug
	 * @return true|WP_Error
	 */
	public function disable_conflict_plugin( string $slug ) {
		$slug = sanitize_text_field( $slug );
		if ( stripos( $slug, 'hummingbird' ) !== false ) {
			return $this->disable_hummingbird_page_cache();
		}

		return new WP_Error( 'nexeng_wizard_conflict_no_fix',
			'Automatic conflict resolution is not available for this plugin yet. Please disable the conflicting cache plugin manually or remove its advanced-cache.php file.'
		);
	}

	/**
	 * Best-effort disable of Hummingbird page caching.
	 *
	 * @return true|WP_Error
	 */
	private function disable_hummingbird_page_cache() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( 'hummingbird-performance/wp-hummingbird.php' )
			&& ! class_exists( 'WPHB\\Core\\Performance\\PageCache\\PageCache' )
			&& ! class_exists( 'Hummingbird_Performance' )
		) {
			return new WP_Error( 'nexeng_wizard_hummingbird_missing',
				'Hummingbird does not appear to be active. The automatic fix requires the Hummingbird plugin to be installed and active.'
			);
		}

		$updated = false;
		$settings = [
			'wphb_options' => [
				['settings','page_cache','enabled'],
				['modules','page_cache','enabled'],
				['settings','page_cache_status'],
			],
			'wphb_settings' => [
				['modules','page_cache','enabled'],
				['settings','page_cache','enabled'],
			],
			'wphb_cache_settings' => [
				['page_cache','enabled'],
				['enabled'],
			],
			'wphb_page_cache_settings' => [
				['enabled'],
			],
		];

		foreach ( $settings as $option_name => $paths ) {
			$option = get_option( $option_name );
			if ( ! is_array( $option ) ) {
				continue;
			}

			$before = $option;
			foreach ( $paths as $path ) {
				$this->set_array_path( $option, $path, in_array( 'status', $path, true ) ? 'disabled' : false );
			}

			if ( $option !== $before ) {
				update_option( $option_name, $option );
				$updated = true;
			}
		}

		if ( $updated ) {
			if ( function_exists( 'wphb_flush_cache' ) ) {
				try {
					wphb_flush_cache();
				} catch ( \Throwable $e ) {
				}
			}
			return true;
		}

		return new WP_Error( 'nexeng_wizard_hummingbird_nofix',
			'Could not locate Hummingbird page cache settings automatically. Please disable page caching inside Hummingbird and try again.'
		);
	}

	private function set_array_path( array &$array, array $path, $value ): bool {
		$node = &$array;
		foreach ( $path as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return false;
			}
			$node = &$node[ $key ];
		}
		if ( $node === $value ) {
			return false;
		}
		$node = $value;
		return true;
	}

	/**
	 * Check if wp-config.php is writable.
	 */
	private function is_wp_config_writable(): bool {
		$abspath = ABSPATH;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe (no write performed here); WP_Filesystem offers no lighter equivalent for a pre-write capability check.
		if ( file_exists( $abspath . 'wp-config.php' ) && is_writable( $abspath . 'wp-config.php' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe (no write performed here); WP_Filesystem offers no lighter equivalent for a pre-write capability check.
		if ( file_exists( dirname( $abspath ) . '/wp-config.php' ) && ! file_exists( dirname( $abspath ) . '/wp-settings.php' ) && is_writable( dirname( $abspath ) . '/wp-config.php' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Mark wizard as completed.
	 */
	public function complete_wizard(): void {
		update_option( 'nexeng_wizard_completed', time() );
	}

	/**
	 * Is the wizard completed?
	 */
	public function is_completed(): bool {
		return (bool) get_option( 'nexeng_wizard_completed' );
	}

	/**
	 * Reset wizard completion (for testing/debugging).
	 */
	public function reset_completion(): void {
		delete_option( 'nexeng_wizard_completed' );
	}

	/**
	 * Admin URL to open the setup wizard.
	 *
	 * When the wizard was already completed, returns a nonce-protected reset URL
	 * so "Setup Wizard" always opens a runnable flow instead of a blank redirect.
	 */
	public static function get_admin_url( bool $force_rerun = false ): string {
		$base = admin_url( 'admin.php?page=ncx-wizard' );
		if ( $force_rerun || self::get_instance()->is_completed() ) {
			return wp_nonce_url(
				add_query_arg( 'nexeng_reset_wizard', '1', $base ),
				'nexeng_reset_wizard'
			);
		}
		return $base;
	}

	/**
	 * Detect server type and expected performance tier before activation.
	 */
	public function get_server_info(): array {
		$sw        = strtolower( NEXENG_Request::server( 'SERVER_SOFTWARE' ) );
		$is_apache = str_contains( $sw, 'apache' );
		$is_nginx  = str_contains( $sw, 'nginx' );
		$is_ls     = str_contains( $sw, 'litespeed' );
		$server    = $is_nginx ? 'Nginx' : ( $is_ls ? 'LiteSpeed' : ( $is_apache ? 'Apache' : 'Unknown' ) );

		$abspath     = trailingslashit( ABSPATH );
		$htaccess    = $abspath . '.htaccess';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe (no write performed here); WP_Filesystem offers no lighter equivalent for a pre-write capability check.
		$htaccess_ok = ( file_exists( $htaccess ) && is_writable( $htaccess ) )
		            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe (no write performed here); WP_Filesystem offers no lighter equivalent for a pre-write capability check.
		            || ( ! file_exists( $htaccess ) && is_writable( $abspath ) );
		$config_ok   = $this->is_wp_config_writable();

		if ( ( $is_apache || $is_ls ) && $htaccess_ok ) {
			return [
				'server'      => $server,
				'is_nginx'    => false,
				'htaccess_ok' => $htaccess_ok,
				'config_ok'   => $config_ok,
				'tier'        => 1,
				'tier_label'  => 'Full Speed',
				'tier_desc'   => 'Pages load instantly — your server delivers them directly without running PHP.',
				'tier_ttfb'   => '~15ms',
			];
		} elseif ( $config_ok ) {
			return [
				'server'      => $server,
				'is_nginx'    => $is_nginx,
				'htaccess_ok' => $htaccess_ok,
				'config_ok'   => $config_ok,
				'tier'        => 2,
				'tier_label'  => 'Speed Active',
				'tier_desc'   => 'Smart cache running — visitors get your pages without hitting the database.',
				'tier_ttfb'   => '~45ms',
			];
		} else {
			return [
				'server'      => $server,
				'is_nginx'    => $is_nginx,
				'htaccess_ok' => $htaccess_ok,
				'config_ok'   => $config_ok,
				'tier'        => 3,
				'tier_label'  => 'Pages Built',
				'tier_desc'   => 'Static pages are ready. One server step will unlock full speed.',
				'tier_ttfb'   => '~80ms',
			];
		}
	}
}
