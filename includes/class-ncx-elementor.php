<?php
/**
 * Nexora Engine — Elementor Integration
 *
 * Adds Nexora SEO controls directly into the Elementor Page Settings panel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Elementor {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'elementor/documents/register_controls', [ $this, 'register_seo_controls' ] );
		add_action( 'elementor/document/after_save', [ $this, 'sync_seo_data' ], 10, 2 );
	}

	/**
	 * Register Nexora SEO section in Elementor Page Settings
	 */
	public function register_seo_controls( $document ) {
		// Only show on Pages and Posts
		if ( ! $document instanceof \Elementor\Core\DocumentTypes\PageBase && ! $document instanceof \Elementor\Modules\Library\Documents\Page ) {
			return;
		}

		$post_id = $document->get_main_id();
		$seo_data = get_post_meta( $post_id, '_nexeng_seo_data', true ) ?: [];

		$document->start_controls_section(
			'nexeng_seo_section',
			[
				'label' => 'Nexora Neural SEO',
				'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
			]
		);

		$document->add_control(
			'nexeng_seo_notice',
			[
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw'  => '<div style="background:#FFF8EB; padding:10px; border-radius:6px; border-left:4px solid #F39A09; font-size:12px; color:#A96A06;">Neural SEO is active. These settings sync with the Nexora Engine.</div>',
			]
		);

		$document->add_control(
			'nexeng_og_title',
			[
				'label'       => 'OG Title',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $seo_data['og_title'] ?? '',
				'placeholder' => 'Leave empty for post title',
				'label_block' => true,
			]
		);

		$document->add_control(
			'nexeng_og_desc',
			[
				'label'       => 'OG Description',
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'default'     => $seo_data['og_desc'] ?? '',
				'placeholder' => 'Leave empty for excerpt',
				'rows'        => 3,
			]
		);

		$document->add_control(
			'nexeng_og_image',
			[
				'label'   => 'OG Image',
				'type'    => \Elementor\Controls_Manager::MEDIA,
				'default' => [
					'url' => $seo_data['og_image'] ?? '',
				],
			]
		);

		$document->add_control(
			'nexeng_schema_type',
			[
				'label'   => 'Schema Type',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => $seo_data['schema_type'] ?? 'Article',
				'options' => [
					'Article' => 'Article',
					'Product' => 'Product',
					'FAQPage' => 'FAQ Page',
					'Recipe'  => 'Recipe',
					'Event'   => 'Event',
				],
			]
		);

		$document->add_control(
			'nexeng_schema_custom',
			[
				'label'       => 'Custom JSON-LD',
				'type'        => \Elementor\Controls_Manager::CODE,
				'language'    => 'json',
				'default'     => $seo_data['schema_custom'] ?? '',
				'rows'        => 10,
			]
		);

		$document->end_controls_section();
	}

	/**
	 * Sync Elementor settings back to Nexora SEO meta
	 */
	public function sync_seo_data( $document, $data ) {
		$settings = $document->get_settings();
		
		if ( ! isset( $settings['nexeng_og_title'] ) ) {
			return;
		}

		$post_id = $document->get_main_id();
		$seo_data = [
			'og_title'      => sanitize_text_field( $settings['nexeng_og_title'] ),
			'og_desc'       => sanitize_textarea_field( $settings['nexeng_og_desc'] ),
			'og_image'      => $settings['nexeng_og_image']['url'] ?? '',
			'schema_type'   => sanitize_text_field( $settings['nexeng_schema_type'] ),
			'schema_custom' => $settings['nexeng_schema_custom'], // Raw JSON
		];

		update_post_meta( $post_id, '_nexeng_seo_data', $seo_data );
		
		// Flush shell cache for this post
		if ( function_exists( 'nexeng_flush_shell_body_cache' ) ) {
			nexeng_flush_shell_body_cache( $post_id );
		}
	}
}
