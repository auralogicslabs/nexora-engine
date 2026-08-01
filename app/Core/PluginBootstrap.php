<?php
/**
 * Nexora Engine — Plugin Bootstrap
 * Enterprise-grade plugin initialization
 *
 * @package NexoraEngine\Core
 */

namespace NexoraEngine\Core;

use NexoraEngine\Licensing\LicenseManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Bootstrap — Initializes Nexora Engine with enterprise architecture
 * 
 * Responsibilities:
 * - Plugin lifecycle management (activation, deactivation, uninstall)
 * - Component initialization
 * - Hook registration
 * - Compatibility checks
 * - Graceful degradation on conflicts
 */
class PluginBootstrap {

	/**
	 * Plugin slug
	 *
	 * @var string
	 */
	const PLUGIN_SLUG = 'nexora-engine';

	/**
	 * Plugin version
	 *
	 * @var string
	 */
	const PLUGIN_VERSION = '1.0.0';

	/**
	 * Plugin namespace
	 *
	 * @var string
	 */
	const PLUGIN_NAMESPACE = 'NexoraEngine';

	/**
	 * Singleton instance
	 *
	 * @var self
	 */
	private static $instance = null;

	/**
	 * Whether plugin is fully initialized
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Initialization errors
	 *
	 * @var array
	 */
	private static $init_errors = [];

	/**
	 * Get singleton instance
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for singleton
	 */
	private function __construct() {
		// Setup initialization hooks
		register_activation_hook( NEXORA_ENGINE_FILE, [ $this, 'on_activation' ] );
		register_deactivation_hook( NEXORA_ENGINE_FILE, [ $this, 'on_deactivation' ] );
		register_uninstall_hook( NEXORA_ENGINE_FILE, [ self::class, 'on_uninstall' ] );

		// Initialize on WordPress load
		add_action( 'plugins_loaded', [ $this, 'initialize' ], 0 );
	}

	/**
	 * Plugin activation hook
	 */
	public function on_activation() {
		// Verify WordPress version compatibility
		if ( version_compare( $GLOBALS['wp_version'], '5.9', '<' ) ) {
			wp_die( 'Nexora Engine requires WordPress 5.9 or higher.' );
		}

		// Verify PHP version compatibility
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			wp_die( 'Nexora Engine requires PHP 7.4 or higher.' );
		}

		// Load legacy includes NOW — activation fires before plugins_loaded,
		// so load_legacy_includes() hasn't run yet via initialize().
		// Without this, NEXENG_Activator (and its deps) don't exist and
		// create_plugin_tables() silently skips, leaving all DB tables uncreated.
		// The require_once calls are idempotent — safe to call twice.
		$this->load_legacy_includes();

		// Create necessary database tables
		$this->create_plugin_tables();

		// Set activation timestamp
		update_option( 'nexora_engine_activated_at', current_time( 'mysql' ) );

		// Flush rewrite rules to ensure SSG serve rules work
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation hook
	 */
	public function on_deactivation() {
		// Clean up transients
		delete_transient( 'nexora_engine_diagnostic_cache' );

		// Optionally disable SSG delivery (can be retained if user prefers)
		// This is configurable via settings

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Plugin uninstall hook (static, called after plugin is disabled)
	 */
	public static function on_uninstall() {
		// Clean up plugin options
		delete_option( 'nexora_engine_settings' );
		delete_option( 'nexora_engine_activated_at' );
		delete_option( 'nexora_engine_license_cache' );

		// Clean up user meta
		delete_metadata( 'user', 0, 'nexora_engine_wizard_completed', '', true );

		// Note: Don't delete static files or database tables by default
		// Users should have explicit backup/restore options
	}

	/**
	 * Main plugin initialization
	 */
	public function initialize() {
		// Prevent double initialization
		if ( self::$initialized ) {
			return;
		}

		// Check for critical conflicts
		if ( ! $this->check_conflicts() ) {
			add_action( 'admin_notices', [ $this, 'show_conflict_notice' ] );
			return;
		}

		// Initialize license manager
		LicenseManager::instance();

		// Load legacy includes for backward compatibility
		$this->load_legacy_includes();

		// Initialize legacy singleton instances
		$this->initialize_legacy_singletons();

		// NOTE: Admin menu and asset enqueue are handled by NEXENG_Admin (initialized
		// via initialize_legacy_singletons). PluginBootstrap does not register a
		// second menu to avoid duplicate entries in wp-admin.

		// Self-heal: ensure DB tables exist after any version update or missed activation.
		if ( is_admin() && class_exists( 'NEXENG_Activator' ) ) {
			add_action( 'admin_init', [ 'NEXENG_Activator', 'maybe_create_tables' ] );
		}

		// Mark as initialized
		self::$initialized = true;

		do_action( 'nexora_engine_initialized' );
	}

	/**
	 * Check for critical plugin conflicts
	 *
	 * @return bool True if safe to continue, false if conflicts detected
	 */
	private function check_conflicts() {
		// Reserved for future incompatible-plugin detection.
		// Add entries here if a specific third-party plugin is known to conflict:
		// $incompatible = [ 'bad-plugin/bad-plugin.php' => 'Description of conflict' ];
		return true;
	}

	/**
	 * Show conflict notice in admin
	 */
	public function show_conflict_notice() {
		if ( empty( self::$init_errors ) ) {
			return;
		}

		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<strong>Nexora Engine — Initialization Error:</strong><br>
				<?php echo implode( '<br>', array_map( 'esc_html', self::$init_errors ) ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Load legacy includes for backward compatibility
	 * 
	 * This maintains compatibility with existing plugin structure
	 * while gradual migration to new namespaced architecture occurs.
	 */
	private function load_legacy_includes() {
		$legacy_dir = dirname( NEXORA_ENGINE_FILE ) . '/includes';

		// Load in dependency order
		$files = [
			// First: everything below reads request data and queues assets through these.
			'class-ncx-request.php',
			'class-ncx-inline-assets.php',
			'class-ncx-database.php',
			'class-ncx-cache.php',
			'class-ncx-licence.php',
			'class-ncx-white-label__premium_only.php',
			'class-ncx-headless.php',
			'class-ncx-normalizer.php',
			'class-ncx-ssg.php',
			'class-ncx-ssg-auto__premium_only.php',
			'class-ncx-cdn__premium_only.php',
			'class-ncx-dropin.php',
			'class-ncx-multisite__premium_only.php',
			'class-ncx-network-admin__premium_only.php',
			'class-ncx-conflict-detector.php',
			'class-ncx-issue-engine.php',
			'class-ncx-wizard.php',
			'class-ncx-logging.php',
			'class-ncx-hardening.php',
			'class-ncx-hardening-pro__premium_only.php',
			'class-ncx-stealth-audit.php',
			'class-ncx-analytics.php',
			'class-ncx-dashboard.php',
			'class-ncx-seo.php',
			'class-ncx-seo-pro__premium_only.php',
			'class-ncx-admin.php',
			'class-ncx-rest.php',
			'class-ncx-ghost-pro__premium_only.php',
			'class-ncx-init.php',
			'class-ncx-elementor.php',
			'class-ncx-builder-sync.php',
			'class-ncx-redirect-manager__premium_only.php',
			'class-ncx-portal-api__premium_only.php',
			// Pro-only feature classes (Freemius strips these from the FREE build).
			// Loaded via file_exists() below, so their absence in the free build is
			// a no-op — every instantiation is class_exists()-guarded.
			'class-ncx-pdf-report__premium_only.php',
			'class-ncx-nextjs-export__premium_only.php',
			'class-ncx-gsc__premium_only.php',
			'class-ncx-scheduler__premium_only.php',
		];

		foreach ( $files as $file ) {
			$path = $legacy_dir . '/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Initialize legacy singleton classes
	 * 
	 * These are called after all includes are loaded to ensure
	 * they're available for instantiation.
	 */
	private function initialize_legacy_singletons() {
		// Only initialize if classes exist
		if ( class_exists( 'NEXENG_Init' ) ) {
			\NEXENG_Init::get_instance();
		}

		if ( class_exists( 'NEXENG_Hardening' ) ) {
			\NEXENG_Hardening::get_instance();
		}

		// Pro guards. The file is stripped from the free build, so the class is
		// simply absent there — nothing is shipped disabled, and there is no
		// licence check to make: presence of the file IS the entitlement.
		if ( class_exists( 'NEXENG_Hardening_Pro' ) ) {
			\NEXENG_Hardening_Pro::get_instance();
		}

		// Automatic mirror rebuild. Hooks nexeng_auto_rebuild_active; with the
		// file absent nothing answers that filter and NEXENG_SSG stays manual.
		if ( class_exists( 'NEXENG_SSG_Auto' ) ) {
			\NEXENG_SSG_Auto::get_instance();
		}

		if ( class_exists( 'NEXENG_Analytics' ) ) {
			\NEXENG_Analytics::get_instance();
		}

		if ( class_exists( 'NEXENG_SEO' ) ) {
			\NEXENG_SEO::get_instance();
		}

		// Per-post SEO (meta box, OG tags, JSON-LD). Stripped from the free
		// build, so absent there rather than present and switched off.
		if ( class_exists( 'NEXENG_SEO_Pro' ) ) {
			\NEXENG_SEO_Pro::get_instance();
		}

		if ( class_exists( 'NEXENG_Elementor' ) ) {
			\NEXENG_Elementor::get_instance();
		}

		if ( class_exists( 'NEXENG_Builder_Sync' ) ) {
			\NEXENG_Builder_Sync::get_instance();
		}

		// NEXENG_Admin registers the menu and asset enqueue hooks.
		if ( class_exists( 'NEXENG_Admin' ) ) {
			\NEXENG_Admin::get_instance();
		}

		// Redirect Manager — must init on every front-end request so redirect
		// rules fire before WordPress renders the page.
		if ( class_exists( 'NEXENG_Redirect_Manager' ) ) {
			( new \NEXENG_Redirect_Manager() )->init();
		}

		if ( class_exists( 'NEXENG_Portal_API' ) ) {
			( new \NEXENG_Portal_API() )->init();
		}

		// Network admin controller — multisite installations only.
		// Registers the "Nexora Fleet" menu in the WP network admin and wires
		// up all fleet AJAX handlers. Safe to call on every page load because
		// NEXENG_Network_Admin checks is_multisite() in its constructor.
		if ( is_multisite() && class_exists( 'NEXENG_Network_Admin' ) ) {
			\NEXENG_Network_Admin::get_instance();
		}
	}

	/**
	 * Create plugin database tables.
	 *
	 * NEXENG_Activator is loaded by load_legacy_includes(). When called from
	 * on_activation() that method is invoked explicitly first. When called
	 * from self-heal on admin_init the includes are already loaded.
	 */
	private function create_plugin_tables() {
		if ( class_exists( 'NEXENG_Activator' ) ) {
			\NEXENG_Activator::run();
		} else {
			// Fallback: directly require the activator and its hard deps so
			// tables are always created even if the load order is unexpected.
			$dir = dirname( NEXORA_ENGINE_FILE ) . '/includes/';
			foreach ( [
				'class-ncx-database.php',
				'class-ncx-cache.php',
				'class-ncx-licence.php',
				'class-ncx-activator.php',
			] as $file ) {
				$path = $dir . $file;
				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}
			if ( class_exists( 'NEXENG_Activator' ) ) {
				\NEXENG_Activator::run();
			}
		}
	}

	/**
	 * Get plugin info
	 *
	 * @return array
	 */
	public static function get_info() {
		return [
			'name'       => 'Nexora Engine',
			'version'    => self::PLUGIN_VERSION,
			'slug'       => self::PLUGIN_SLUG,
			'namespace'  => self::PLUGIN_NAMESPACE,
			'initialized' => self::$initialized,
		];
	}
}
