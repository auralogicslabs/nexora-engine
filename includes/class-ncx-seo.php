<?php
/**
 * Nexora Engine — SEO Engine (XML sitemap)
 *
 * The free half of the SEO module. Everything here runs on every plan and
 * contains no licence check.
 *
 * The per-post SEO meta box, its save handler, and the OpenGraph / JSON-LD
 * output live in class-ncx-seo-pro__premium_only.php, which Freemius removes
 * from the WordPress.org build. They are absent on the free tier rather than
 * shipped behind a licence check.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_SEO {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Sitemap — available on all plans.
		add_action( 'init',              [ $this, 'add_sitemap_rewrite' ] );
		add_filter( 'query_vars',        [ $this, 'add_sitemap_query_var' ] );
		add_action( 'template_redirect', [ $this, 'serve_sitemap' ] );
	}

	public function add_sitemap_rewrite() {
		add_rewrite_rule( 'sitemap\.xml$', 'index.php?nexeng_sitemap=1', 'top' );
	}

	public function add_sitemap_query_var( $vars ) {
		$vars[] = 'nexeng_sitemap';
		return $vars;
	}

	public function serve_sitemap() {
		if ( ! get_query_var( 'nexeng_sitemap' ) ) {
			return;
		}

		header( 'Content-Type: application/xml; charset=utf-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>';
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

		$posts = get_posts( [
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		] );

		foreach ( $posts as $p ) {
			echo '<url>';
			echo '<loc>' . esc_url( get_permalink( $p->ID ) ) . '</loc>';
			echo '<lastmod>' . esc_html( get_the_modified_date( 'c', $p->ID ) ) . '</lastmod>';
			echo '<changefreq>weekly</changefreq>';
			echo '<priority>0.8</priority>';
			echo '</url>';
		}

		echo '</urlset>';
		exit;
	}
}
