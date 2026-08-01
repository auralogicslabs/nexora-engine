<?php
/**
 * Nexora Engine — Page Builder & Theme Template Sync
 *
 * Per-page saves (posts/pages) are handled by NEXENG_SSG::on_save_post().
 * Theme-wide templates (Elementor headers/footers/kits, block theme parts, etc.)
 * are internal CPTs with no public URL — this class marks the whole site pending
 * and notifies the admin when a rebuild is needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Builder_Sync {

	private static $instance = null;

	/** @var bool Guard against duplicate invalidation in one request. */
	private bool $invalidated_this_request = false;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Elementor Theme Builder, kits, library templates.
		add_action( 'elementor/document/after_save', [ $this, 'on_elementor_document_save' ], 25, 2 );
		add_action( 'save_post_elementor_library', [ $this, 'on_elementor_library_save' ], 25, 3 );
		add_action( 'update_option_elementor_active_kit', [ $this, 'on_elementor_active_kit' ], 10, 2 );

		// WordPress block / FSE templates.
		foreach ( [ 'wp_template', 'wp_template_part', 'wp_global_styles' ] as $post_type ) {
			add_action( "save_post_{$post_type}", [ $this, 'on_block_theme_save' ], 25, 3 );
		}

		// Beaver Builder layout library (when present).
		add_action( 'save_post_fl-builder-template', [ $this, 'on_beaver_template_save' ], 25, 3 );

		// Divi Theme Builder library layouts.
		add_action( 'save_post_et_pb_layout', [ $this, 'on_divi_layout_save' ], 25, 3 );
		add_action( 'et_save_post', [ $this, 'on_divi_save_post' ], 25, 3 );

		add_action( 'admin_notices', [ $this, 'maybe_render_invalidate_notice' ] );
	}

	/**
	 * Elementor document save — headers, footers, kits, popups, etc.
	 *
	 * @param \Elementor\Core\Base\Document $document Document instance.
	 * @param array                         $data     Saved data.
	 */
	public function on_elementor_document_save( $document, $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->should_run() || ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
			return;
		}

		$post_id = (int) $document->get_main_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) ) {
			return;
		}

		$template_type = (string) get_post_meta( $post_id, '_elementor_template_type', true );
		if ( $template_type === '' && method_exists( $document, 'get_name' ) ) {
			$template_type = (string) $document->get_name();
		}

		if ( class_exists( '\Elementor\Core\Kits\Documents\Kit' ) && $document instanceof \Elementor\Core\Kits\Documents\Kit ) {
			$template_type = 'kit';
		}

		// Regular pages/posts are queued per-page by NEXENG_SSG::on_save_post().
		if ( in_array( $post->post_type, [ 'page', 'post' ], true ) && $template_type === '' ) {
			return;
		}

		if ( $post->post_type === 'elementor_library' || $template_type !== '' ) {
			$this->invalidate(
				'elementor_' . sanitize_key( $template_type ?: 'library' ),
				$this->elementor_label( $template_type, $post )
			);
		}
	}

	/**
	 * Fallback when Elementor saves library CPT without firing document hook.
	 */
	public function on_elementor_library_save( int $post_id, $post, $update ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->should_run() || $this->invalidated_this_request ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
			return;
		}

		$template_type = (string) get_post_meta( $post_id, '_elementor_template_type', true );
		$this->invalidate(
			'elementor_' . sanitize_key( $template_type ?: 'library' ),
			$this->elementor_label( $template_type, $post )
		);
	}

	public function on_elementor_active_kit( $old_value, $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->should_run() || (int) $value <= 0 ) {
			return;
		}
		$this->invalidate( 'elementor_kit', __( 'Elementor Site Settings (Kit)', 'nexora-engine' ) );
	}

	public function on_block_theme_save( int $post_id, $post, $update ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->should_run() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
			return;
		}

		$labels = [
			'wp_template'       => __( 'Block theme template', 'nexora-engine' ),
			'wp_template_part'  => __( 'Block theme template part', 'nexora-engine' ),
			'wp_global_styles'  => __( 'Block theme global styles', 'nexora-engine' ),
		];
		$label  = $labels[ $post->post_type ] ?? __( 'Block theme layout', 'nexora-engine' );
		if ( $post->post_title ) {
			$label = sprintf(
				/* translators: 1: template type, 2: template title */
				__( '%1$s: %2$s', 'nexora-engine' ),
				$label,
				$post->post_title
			);
		}

		$this->invalidate( 'block_' . sanitize_key( $post->post_type ), $label );
	}

	public function on_beaver_template_save( int $post_id, $post, $update ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->should_run() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
			return;
		}
		$title = $post->post_title ? $post->post_title : __( 'Saved layout', 'nexora-engine' );
		$this->invalidate(
			'beaver_template',
			sprintf(
				/* translators: %s: Beaver Builder template title */
				__( 'Beaver Builder template: %s', 'nexora-engine' ),
				$title
			)
		);
	}

	public function on_divi_layout_save( int $post_id, $post, $update ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->should_run() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
			return;
		}
		$title = $post->post_title ? $post->post_title : __( 'Theme Builder layout', 'nexora-engine' );
		$this->invalidate(
			'divi_layout',
			sprintf(
				/* translators: %s: Divi layout title */
				__( 'Divi layout: %s', 'nexora-engine' ),
				$title
			)
		);
	}

	/**
	 * Divi theme-builder global save hook.
	 */
	public function on_divi_save_post( int $post_id ): void {
		if ( ! $this->should_run() || get_post_type( $post_id ) !== 'et_pb_layout' ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			return;
		}
		$this->on_divi_layout_save( $post_id, $post, true );
	}

	public function maybe_render_invalidate_notice(): void {
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) || ! class_exists( 'NEXENG_SSG' ) || ! NEXENG_SSG::is_enabled() ) {
			return;
		}

		$notice = get_transient( 'nexeng_ssg_invalidate_notice' );
		if ( ! is_array( $notice ) || empty( $notice['label'] ) ) {
			return;
		}

		$ssg     = NEXENG_SSG::get_instance();
		$pending = $ssg->pending_count();
		$is_pro  = class_exists( 'NEXENG_Licence' ) && NEXENG_Licence::is_pro();
		$auto    = $is_pro && get_option( 'nexeng_auto_rebuild', 'on' ) === 'on';
		$build_url = admin_url( 'admin.php?page=ncx-headless' );
		$label   = (string) $notice['label'];
		?>
		<div class="notice notice-info is-dismissible ncx-builder-sync-notice" style="border-left-color:#0252FA;padding:12px 16px;">
			<p>
				<strong><?php esc_html_e( 'Static mirror needs a site-wide refresh', 'nexora-engine' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: builder item label e.g. Elementor Header */
						__( '%1$s was saved. This change can appear on many pages — not just one post.', 'nexora-engine' ),
						$label
					)
				);
				?>
			</p>
			<p style="margin:6px 0 0;">
				<?php if ( $pending > 0 ) : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of queued pages */
							_n(
								'%d page is queued for recapture.',
								'%d pages are queued for recapture.',
								$pending,
								'nexora-engine'
							),
							$pending
						)
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Pages will be queued for recapture shortly.', 'nexora-engine' ); ?>
				<?php endif; ?>
				<?php if ( $auto ) : ?>
					<?php esc_html_e( 'Auto-Build (Pro) will refresh them in the background.', 'nexora-engine' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Open Mirror Build Control and run Refresh Changed Pages when you are ready.', 'nexora-engine' ); ?>
				<?php endif; ?>
			</p>
			<p style="margin:10px 0 0;">
				<a class="button button-primary" href="<?php echo esc_url( $build_url ); ?>">
					<?php esc_html_e( 'Open Mirror Build Control', 'nexora-engine' ); ?>
				</a>
			</p>
		</div>
		<?php
		delete_transient( 'nexeng_ssg_invalidate_notice' );
	}

	private function should_run(): bool {
		return class_exists( 'NEXENG_SSG' )
			&& NEXENG_SSG::is_enabled()
			&& ! defined( 'NEXORA_CAPTURE' );
	}

	private function invalidate( string $source, string $label ): void {
		if ( $this->invalidated_this_request ) {
			NEXENG_SSG::get_instance()->schedule_global_invalidate();
			return;
		}
		$this->invalidated_this_request = true;
		NEXENG_SSG::get_instance()->invalidate_site_wide( $source, $label );
	}

	private function elementor_label( string $template_type, WP_Post $post ): string {
		$map = [
			'header'          => __( 'Elementor Header', 'nexora-engine' ),
			'footer'          => __( 'Elementor Footer', 'nexora-engine' ),
			'single'          => __( 'Elementor Single template', 'nexora-engine' ),
			'archive'         => __( 'Elementor Archive template', 'nexora-engine' ),
			'search-results'  => __( 'Elementor Search Results template', 'nexora-engine' ),
			'error-404'       => __( 'Elementor 404 template', 'nexora-engine' ),
			'kit'             => __( 'Elementor Site Settings', 'nexora-engine' ),
			'popup'           => __( 'Elementor Popup', 'nexora-engine' ),
			'section'         => __( 'Elementor Section template', 'nexora-engine' ),
			'page'            => __( 'Elementor Page template', 'nexora-engine' ),
			'product'         => __( 'Elementor Product template', 'nexora-engine' ),
			'product-archive' => __( 'Elementor Product Archive template', 'nexora-engine' ),
		];

		$base = $map[ $template_type ] ?? __( 'Elementor template', 'nexora-engine' );
		if ( $post->post_title ) {
			return sprintf(
				/* translators: 1: template type label, 2: template title */
				__( '%1$s: %2$s', 'nexora-engine' ),
				$base,
				$post->post_title
			);
		}
		return $base;
	}
}
