<?php
/**
 * Nexora Engine — Performance Analyser
 *
 * Analyses performance issues from normalised content:
 * large images, missing dimensions, render-blocking scripts,
 * heavy inline styles, and excessive DOM size.
 * Accounts for 25% of overall page score.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Performance {

	private NEXENG_Cache        $cache;
	private NEXENG_Issue_Engine $issues;
	private NEXENG_Normalizer   $normalizer;

	// Thresholds.
	private const LARGE_IMAGE_BYTES   = 204800; // 200 KB
	private const INLINE_STYLE_BYTES  = 51200;  // 50 KB
	private const MAX_DOM_NODES       = 1500;

	public function __construct() {
		$this->cache      = NEXENG_Cache::get_instance();
		$this->issues     = NEXENG_Issue_Engine::get_instance();
		$this->normalizer = new NEXENG_Normalizer();
	}

	// ─── Public API ───────────────────────────────────────────────────────────

	/**
	 * Runs all performance checks and returns the performance score (0-100).
	 */
	public function analyse( int $post_id, bool $force = false ): int {
		$blog_id   = get_current_blog_id();
		$cache_key = NEXENG_Cache::make_key( 'performance', $blog_id, $post_id );

		if ( ! $force ) {
			$cached = $this->cache->get( $cache_key );
			if ( false !== $cached && is_int( $cached ) ) {
				return $cached;
			}
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		$content       = $this->normalizer->normalize( $post_id );
		$detected_keys = $this->run_checks( $blog_id, $post_id, $content );

		$this->issues->auto_resolve_cleared( $blog_id, $post_id, $detected_keys );

		$score = NEXENG_Scorer::calculate_module_score( $blog_id, $post_id, $detected_keys );

		NEXENG_Database::get_instance()->insert_scan_result(
			$blog_id,
			$post_id,
			'performance',
			[ 'checked_keys' => $detected_keys ],
			$score
		);

		$this->cache->set( $cache_key, $score, NEXENG_CACHE_PERFORMANCE );

		return $score;
	}

	// ─── Checks ───────────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $content
	 * @return string[]
	 */
	private function run_checks( int $blog_id, int $post_id, array $content ): array {
		$detected = [];
		$html     = $content['raw_html'] ?? '';

		$detected = array_merge( $detected, $this->check_large_images( $blog_id, $post_id, $content ) );
		$detected = array_merge( $detected, $this->check_missing_img_dimensions( $blog_id, $post_id, $content ) );
		$detected = array_merge( $detected, $this->check_render_blocking_scripts( $blog_id, $post_id, $html ) );
		$detected = array_merge( $detected, $this->check_heavy_inline_styles( $blog_id, $post_id, $html ) );
		$detected = array_merge( $detected, $this->check_excessive_dom( $blog_id, $post_id, $html ) );

		return $detected;
	}

	// ─── Large Images ─────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $content
	 * @return string[]
	 */
	private function check_large_images( int $blog_id, int $post_id, array $content ): array {
		$detected      = [];
		$images        = $content['images'] ?? [];
		$large_images  = [];

		foreach ( $images as $image ) {
			$size = (int) ( $image['file_size'] ?? 0 );
			if ( $size > self::LARGE_IMAGE_BYTES ) {
				$large_images[] = [
					'src'  => $image['src'],
					'size' => $size,
				];
			}
		}

		if ( ! empty( $large_images ) ) {
			$file_list = implode( ', ', array_map( function ( $img ) {
				return basename( $img['src'] ) . ' (' . round( $img['size'] / 1024 ) . ' KB)';
			}, $large_images ) );

			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_large_image', [
				'title'       => __( 'Large Images Found', 'nexora-engine' ),
				'severity'    => 'high',
				/* translators: 1: count of large images, 2: list of filenames and sizes */
				'explanation' => sprintf(
					/* translators: %1 / %2 etc.: counts and value(s) inserted into the message. */
					__( '%1$d image(s) exceed 200 KB: %2$s. Oversized images are the most common cause of slow page loads, directly hurting Core Web Vitals (LCP).', 'nexora-engine' ),
					count( $large_images ),
					$file_list
				),
				'fix'         => __( 'Compress images to under 200 KB using a tool like Squoosh, TinyPNG, or ShortPixel. Use WebP format where possible for the best size-to-quality ratio.', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_large_image';
		}

		return $detected;
	}

	// ─── Missing Image Dimensions ─────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $content
	 * @return string[]
	 */
	private function check_missing_img_dimensions( int $blog_id, int $post_id, array $content ): array {
		$detected = [];
		$images   = $content['images'] ?? [];
		$missing  = 0;

		foreach ( $images as $image ) {
			if ( empty( $image['width'] ) || empty( $image['height'] ) ) {
				$missing++;
			}
		}

		if ( $missing > 0 ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_missing_img_dimensions', [
				'title'       => __( 'Images Missing Width/Height Attributes', 'nexora-engine' ),
				'severity'    => 'medium',
				/* translators: %d: number of images missing dimensions */
				'explanation' => sprintf(
					/* translators: %1 / %2 etc.: counts and value(s) inserted into the message. */
					__( '%d image(s) are missing explicit width and height attributes. Without these, the browser cannot reserve space before the image loads, causing Cumulative Layout Shift (CLS) — a Core Web Vitals failure.', 'nexora-engine' ),
					$missing
				),
				'fix'         => __( 'Add width and height attributes to every img tag matching the image\'s natural dimensions. WordPress adds these automatically for images inserted via the media library — check for images added via HTML or page builders.', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_missing_img_dimensions';
		}

		return $detected;
	}

	// ─── Render-Blocking Scripts ──────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function check_render_blocking_scripts( int $blog_id, int $post_id, string $html ): array {
		$detected = [];

		if ( empty( $html ) ) {
			return $detected;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$head          = $dom->getElementsByTagName( 'head' )->item( 0 );
		$blocking_srcs = [];

		if ( $head ) {
			foreach ( $head->getElementsByTagName( 'script' ) as $script ) {
				/** @var DOMElement $script */
				$src   = $script->getAttribute( 'src' );
				$defer = $script->hasAttribute( 'defer' );
				$async = $script->hasAttribute( 'async' );
				$type  = $script->getAttribute( 'type' );

				// Module scripts are deferred by default; skip them.
				if ( 'module' === $type ) {
					continue;
				}

				// External src without defer or async — render-blocking.
				if ( ! empty( $src ) && ! $defer && ! $async ) {
					$blocking_srcs[] = basename( $src );
				}
			}
		}

		if ( ! empty( $blocking_srcs ) ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_render_blocking_script', [
				'title'       => __( 'Render-Blocking Scripts in <head>', 'nexora-engine' ),
				'severity'    => 'high',
				/* translators: 1: count, 2: list of script filenames */
				'explanation' => sprintf(
					/* translators: %1 / %2 etc.: counts and value(s) inserted into the message. */
					__( '%1$d script(s) in the page <head> are missing defer or async attributes: %2$s. These block the browser from rendering the page until the scripts download and execute, directly increasing Time to First Byte (TTFB) and LCP.', 'nexora-engine' ),
					count( $blocking_srcs ),
					implode( ', ', $blocking_srcs )
				),
				'fix'         => __( 'Add the "defer" attribute to scripts that do not need to run before the page renders. Use "async" for independent scripts like analytics. In WordPress, use wp_script_add_data() or a plugin like WP Rocket to add defer/async.', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_render_blocking_script';
		}

		return $detected;
	}

	// ─── Heavy Inline Styles ──────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function check_heavy_inline_styles( int $blog_id, int $post_id, string $html ): array {
		$detected    = [];
		$total_bytes = 0;

		if ( empty( $html ) ) {
			return $detected;
		}

		preg_match_all( '/<style[^>]*>(.*?)<\/style>/si', $html, $matches );

		foreach ( $matches[1] ?? [] as $style_content ) {
			$total_bytes += strlen( $style_content );
		}

		if ( $total_bytes > self::INLINE_STYLE_BYTES ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_heavy_inline_style', [
				'title'       => __( 'Excessive Inline CSS', 'nexora-engine' ),
				'severity'    => 'medium',
				/* translators: %s: size in KB */
				'explanation' => sprintf(
					/* translators: %1 / %2 etc.: counts and value(s) inserted into the message. */
					__( 'This page contains %s KB of inline CSS in <style> blocks. Large inline styles increase page weight and prevent the browser from caching styles separately, slowing repeat visits.', 'nexora-engine' ),
					round( $total_bytes / 1024, 1 )
				),
				'fix'         => __( 'Move inline CSS to external stylesheets so the browser can cache them. If using a page builder, check for "Critical CSS" or "Remove Unused CSS" settings that may be inlining too much.', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_heavy_inline_style';
		}

		return $detected;
	}

	// ─── Excessive DOM ────────────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function check_excessive_dom( int $blog_id, int $post_id, string $html ): array {
		$detected = [];

		if ( empty( $html ) ) {
			return $detected;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		// Count all element nodes (not text/comment nodes).
		$xpath     = new DOMXPath( $dom );
		$node_list = $xpath->query( '//*' );
		$count     = $node_list ? $node_list->length : 0;

		if ( $count > self::MAX_DOM_NODES ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_excessive_dom', [
				'title'       => __( 'Excessive DOM Size', 'nexora-engine' ),
				'severity'    => 'medium',
				/* translators: 1: actual node count, 2: recommended maximum */
				'explanation' => sprintf(
					/* translators: %1 / %2 etc.: counts and value(s) inserted into the message. */
					__( 'This page has %1$d DOM nodes — above the recommended maximum of %2$d. Large DOM trees increase memory usage, slow style calculations, and cause layout reflows that degrade page performance.', 'nexora-engine' ),
					$count,
					self::MAX_DOM_NODES
				),
				'fix'         => __( 'Reduce DOM complexity by removing unnecessary wrapper elements, lazy-loading off-screen content, and using CSS for visual effects instead of extra elements. Review your page builder output for redundant nesting.', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_excessive_dom';
		}

		return $detected;
	}
}
