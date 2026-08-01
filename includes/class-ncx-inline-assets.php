<?php
/**
 * Nexora Engine — Inline CSS/JS routed through the WordPress asset API.
 *
 * The admin views are rendered inside the page body, long after wp_head has
 * printed. Calling wp_add_inline_style() there would attach the CSS to a handle
 * that has already gone out, so nothing would render. That is why these blocks
 * were written as raw <style> and <script> tags in the first place.
 *
 * So the content is collected while the view renders and flushed in the footer,
 * through wp_add_inline_style()/wp_add_inline_script() on a registered handle.
 * The output still lands inline — that is the point of a per-screen block — but
 * it now goes through the API, which means it is deduplicated, ordered against
 * its dependencies, filterable, and visible to anything inspecting the queue.
 *
 * Usage from a view file:
 *
 *     <?php ob_start(); ?>
 *     .my-rule { color: red; }
 *     <?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>
 *
 * Output buffering rather than a string literal keeps the CSS and JS readable
 * in place, keeps IDE syntax highlighting working, and lets a block interpolate
 * PHP exactly as it did before.
 *
 * @package NexoraEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEXENG_Inline_Assets — collect inline CSS/JS, emit it via the asset API.
 */
class NEXENG_Inline_Assets {

	private const HANDLE = 'nexeng-inline';

	/** @var string[] */
	private static $css = [];

	/** @var string[] */
	private static $js = [];

	/** @var bool */
	private static $hooked = false;

	/**
	 * Register the footer flush once, on both admin and front end.
	 *
	 * Priority 5 so this runs before core prints the footer queue at 20.
	 */
	private static function hook(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'admin_print_footer_scripts', [ __CLASS__, 'flush' ], 5 );
		add_action( 'wp_print_footer_scripts', [ __CLASS__, 'flush' ], 5 );
	}

	/**
	 * Queue a block of CSS.
	 *
	 * @param string $css Raw CSS, without <style> tags.
	 */
	public static function style( $css ): void {
		$css = trim( (string) $css );
		if ( '' === $css ) {
			return;
		}
		self::hook();
		self::$css[] = $css;
	}

	/**
	 * Queue a block of JavaScript.
	 *
	 * @param string $js Raw JS, without <script> tags.
	 */
	public static function script( $js ): void {
		$js = trim( (string) $js );
		if ( '' === $js ) {
			return;
		}
		self::hook();
		self::$js[] = $js;
	}

	/**
	 * Emit everything collected so far.
	 *
	 * Registered with src=false: a handle that carries only inline content,
	 * which is the documented way to attach inline assets that have no file.
	 * Printing explicitly here rather than leaving it to the queue means the
	 * output does not depend on how late in the footer this ran.
	 */
	public static function flush(): void {
		$version = defined( 'NEXENG_VERSION' ) ? NEXENG_VERSION : false;

		if ( ! empty( self::$css ) ) {
			$css = implode( "\n", self::$css );
			self::$css = [];
			if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
				wp_register_style( self::HANDLE, false, [], $version );
			}
			wp_enqueue_style( self::HANDLE );
			wp_add_inline_style( self::HANDLE, $css );
			wp_print_styles( self::HANDLE );
		}

		if ( ! empty( self::$js ) ) {
			$js = implode( "\n", self::$js );
			self::$js = [];
			if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
				wp_register_script( self::HANDLE, false, [], $version, true );
			}
			wp_enqueue_script( self::HANDLE );
			wp_add_inline_script( self::HANDLE, $js );
			wp_print_scripts( self::HANDLE );
		}
	}
}
