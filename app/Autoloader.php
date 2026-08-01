<?php
/**
 * Nexora Engine — PSR-4 Autoloader
 *
 * @package NexoraEngine
 */

namespace NexoraEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-4 Autoloader for NexoraEngine namespace
 */
class Autoloader {

	/**
	 * Base path for PSR-4 classes
	 *
	 * @var string
	 */
	private static $base_path = '';

	/**
	 * Initialize autoloader
	 *
	 * @param string $base_path Base directory for app classes.
	 */
	public static function init( $base_path = '' ) {
		self::$base_path = $base_path;
		spl_autoload_register( [ self::class, 'load' ] );
	}

	/**
	 * Load class file based on PSR-4 namespace
	 *
	 * @param string $class Full class name including namespace.
	 */
	public static function load( $class ) {
		// Only handle NexoraEngine namespace
		if ( strpos( $class, 'NexoraEngine\\' ) !== 0 ) {
			return;
		}

		// Remove NexoraEngine\ prefix
		$relative_class = substr( $class, strlen( 'NexoraEngine\\' ) );

		// Convert namespace to file path
		$file_path = self::$base_path . DIRECTORY_SEPARATOR . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

		// Load if file exists
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
}
