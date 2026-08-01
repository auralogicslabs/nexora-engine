<?php
/**
 * Nexora Engine — Drop-In Cache Manager
 *
 * Installs / removes a WordPress advanced-cache.php drop-in that serves
 * captured SSG static files BEFORE WP boots its plugins, theme, and DB
 * queries. Universal — works on Apache, Nginx, LiteSpeed, IIS — without
 * any server config from the user.
 *
 * This is the canonical pattern used by WP Rocket, W3 Total Cache,
 * WP Super Cache, and LiteSpeed Cache. We use a unique signature in our
 * file header so we never overwrite a competing plugin's drop-in.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class NEXENG_Dropin {

    private const SIGNATURE          = '@nexora-dropin';
    private const TEMPLATE           = '/templates/advanced-cache.php';
    private const TEMPLATE_NETWORK   = '/templates/advanced-cache-network.php';
    private const WPCFG_MARKER       = "// Added by Nexora Engine (SSG drop-in cache)";

    // ─── Status Inspectors ───────────────────────────────────────────────────

    public static function dropin_path(): string {
        return WP_CONTENT_DIR . '/advanced-cache.php';
    }

    public static function template_path(): string {
        return NEXENG_PLUGIN_DIR . self::TEMPLATE;
    }

    /** Returns 'ours' | 'foreign' | 'absent'. */
    public static function status(): string {
        $p = self::dropin_path();
        if ( ! file_exists( $p ) ) {
            return 'absent';
        }
        $head = @file_get_contents( $p, false, null, 0, 2048 );
        if ( $head === false ) {
            return 'foreign'; // unreadable → safer to treat as foreign
        }
        return ( strpos( $head, self::SIGNATURE ) !== false ) ? 'ours' : 'foreign';
    }

    /**
     * Absolute path to the marker that tells the drop-in to stand down.
     *
     * @return string Empty when the uploads dir cannot be resolved.
     */
    public static function disabled_flag_path(): string {
        $dir = self::private_dir();
        return $dir === '' ? '' : $dir . '/ssg-disabled.flag';
    }

    /**
     * Switches the drop-in on or off without uninstalling it.
     *
     * The drop-in runs before WordPress, so it cannot read the
     * nexeng_ssg_enabled option; a file is the only signal it can act on.
     * Toggling used to leave the drop-in serving the mirror after Static
     * Delivery had been switched off in wp-admin.
     *
     * Writing a marker is deliberately preferred over removing the drop-in:
     * uninstalling rewrites wp-config.php on every toggle, which is slow, can
     * fail outright on a read-only config, and would leave the feature
     * un-re-enablable on exactly the hosts where that matters.
     *
     * @param bool $enabled Desired state.
     * @return bool True when the filesystem now reflects $enabled.
     */
    public static function set_serving_enabled( bool $enabled ): bool {
        $flag = self::disabled_flag_path();
        if ( '' === $flag ) {
            return false;
        }

        if ( $enabled ) {
            if ( ! file_exists( $flag ) ) {
                return true;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Pairs with the drop-in's pre-WordPress is_file() check; WP_Filesystem is not loaded on every admin request and adds nothing here.
            return @unlink( $flag ) || ! file_exists( $flag );
        }

        $dir = dirname( $flag );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }
        // Content is never read — the drop-in only tests for existence — but a
        // timestamp makes the file self-explanatory to anyone who finds it.
        $written = @file_put_contents(
            $flag,
            'Static Delivery was disabled in wp-admin at ' . gmdate( 'c' ) . ".\n" .
            "Delete this file only if you want the cache drop-in to start serving again.\n"
        );
        return false !== $written;
    }

    /** True only if WP_CACHE constant evaluates to true. */
    public static function wp_cache_active(): bool {
        return defined( 'WP_CACHE' ) && WP_CACHE === true;
    }

    /** Detects known caching plugins that own advanced-cache.php. */
    public static function detect_conflict(): ?string {
        $p = self::dropin_path();
        if ( ! file_exists( $p ) ) {
            return null;
        }
        $head = @file_get_contents( $p, false, null, 0, 2048 );
        if ( $head === false ) {
            return 'unknown drop-in';
        }
        if ( strpos( $head, self::SIGNATURE ) !== false ) {
            return null; // ours
        }
        $known = [
            'WP Rocket'        => [ 'WP_ROCKET', 'wp-rocket' ],
            'W3 Total Cache'   => [ 'W3 Total Cache', 'w3-total-cache' ],
            'WP Super Cache'   => [ 'WP Super Cache', 'wp-cache-config' ],
            'LiteSpeed Cache'  => [ 'LiteSpeed Cache', 'litespeed-cache' ],
            'WP Fastest Cache' => [ 'WP Fastest Cache' ],
            'Cache Enabler'    => [ 'Cache Enabler' ],
            'Hummingbird'      => [ 'Hummingbird' ],
        ];
        foreach ( $known as $name => $needles ) {
            foreach ( $needles as $n ) {
                if ( stripos( $head, $n ) !== false ) {
                    return $name;
                }
            }
        }
        return 'unknown drop-in';
    }

    // ─── Install / Uninstall ─────────────────────────────────────────────────

    /**
     * Writes our advanced-cache.php and (best-effort) ensures WP_CACHE.
     *
     * @return true|WP_Error
     */
    public static function install() {
        // On multisite: use the network-aware template and rebuild the site map.
        if ( is_multisite() ) {
            return self::install_network();
        }

        if ( self::status() === 'foreign' ) {
            $owner = self::detect_conflict() ?: 'another plugin';
            return new WP_Error( 'nexeng_dropin_conflict', sprintf(
                'Refusing to overwrite wp-content/advanced-cache.php — it appears to be owned by %s. ' .
                'Disable that plugin first, or remove the file manually before enabling Nexora SSG.',
                $owner
            ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe (no write performed here); WP_Filesystem offers no lighter equivalent for a pre-write capability check.
        if ( ! is_writable( WP_CONTENT_DIR ) ) {
            return new WP_Error( 'nexeng_dropin_unwritable',
                'wp-content/ is not writable — the host must allow PHP to create files there.' );
        }

        $template = @file_get_contents( self::template_path() );
        if ( $template === false ) {
            return new WP_Error( 'nexeng_dropin_template',
                'Could not read drop-in template at ' . self::template_path() );
        }

        $upload      = wp_get_upload_dir();
        $static_root = trailingslashit( $upload['basedir'] ) . 'nexora-static';
        $home_prefix = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        $version     = defined( 'NEXENG_VERSION' ) ? NEXENG_VERSION : '1.0';

        // Baked settings for ultra-fast asset proxying without DB queries.
        $asset_mode   = get_option( 'nexeng_asset_mode', 'direct' );
        $proxy_mode   = get_option( 'nexeng_proxy_mode', 'compat' );
        $proxy_prefix = ( $proxy_mode === 'clean' ) ? '' : '/index.php';

        // Resolve the admin-ajax path here rather than assuming /wp-admin/ in
        // the drop-in: wp-admin can be relocated, and the drop-in runs before
        // admin_url() is available so it cannot work this out for itself.
        $ajax_path = wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH );
        if ( ! is_string( $ajax_path ) || '' === $ajax_path ) {
            $ajax_path = '/wp-admin/admin-ajax.php';
        }

        // Same reasoning for the private log directory: resolved from
        // wp_upload_dir() here, because the drop-in cannot call it.
        $private_dir = self::private_dir();

        // ...and for wp-content / wp-includes. The drop-in maps cloaked asset
        // URLs back onto real files, and it used to spell those two directories
        // out literally. Both are relocatable — WP_CONTENT_DIR can point
        // anywhere and WPINC is a constant precisely because it is not fixed —
        // so a hardcoded segment silently fails to resolve any asset on a site
        // that moved them. Resolved here and baked in, like every other path.
        $content_rel  = self::relative_to_abspath( WP_CONTENT_DIR );
        $includes_rel = trim( WPINC, '/' );

        $contents = strtr( $template, [
            '__NEXENG_STATIC_ROOT__'  => self::php_string( $static_root ),
            '__NEXENG_HOME_PREFIX__'  => self::php_string( $home_prefix ),
            '__NEXENG_VERSION__'      => preg_replace( '/[^0-9.\-a-zA-Z]/', '', $version ),
            '__NEXENG_ASSET_MODE__'   => self::php_string( $asset_mode ),
            '__NEXENG_PROXY_PREFIX__' => self::php_string( $proxy_prefix ),
            '__NEXENG_AJAX_PATH__'    => self::php_string( $ajax_path ),
            '__NEXENG_PRIVATE_DIR__'  => self::php_string( $private_dir ),
            '__NEXENG_CONTENT_REL__'  => self::php_string( $content_rel ),
            '__NEXENG_INC_REL__'      => self::php_string( $includes_rel ),
        ] );

        // Atomic write.
        $dest = self::dropin_path();
        $tmp  = $dest . '.tmp.' . wp_generate_password( 12, false );
        if ( @file_put_contents( $tmp, $contents, LOCK_EX ) === false ) {
            return new WP_Error( 'nexeng_dropin_write', 'Could not write ' . $dest );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic drop-in swap of advanced-cache.php; rename() is required for atomicity (WP_Filesystem::move is not guaranteed atomic).
        if ( ! @rename( $tmp, $dest ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Atomic drop-in install/cleanup; uses native unlink alongside rename for an atomic swap of advanced-cache.php.
            @unlink( $tmp );
            return new WP_Error( 'nexeng_dropin_rename', 'Could not move drop-in into place.' );
        }

        // Ensure WP_CACHE — non-fatal if it fails (admin notice covers it).
        $wp_cache_result = self::set_wp_cache( true );
        update_option( 'nexeng_dropin_wpcache_writable', $wp_cache_result === true ? 'yes' : 'no', false );

        // Install the Stealth Asset router when proxy mode is active.
        // Handles auto-upgrade paths (headless.php) and explicit mode switches
        // in one place — any caller sets the option first, then calls install().
        if ( get_option( 'nexeng_asset_mode', 'direct' ) === 'proxy'
             && class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled() ) {
            NEXENG_SSG::get_instance()->install_stealth_asset_rule();
        }

        return true;
    }

    public static function template_network_path(): string {
        return NEXENG_PLUGIN_DIR . self::TEMPLATE_NETWORK;
    }

    /**
     * Installs the multisite-aware drop-in for WordPress network installations.
     *
     * Steps:
     *  1. Rebuild the network map (host+path → static_root per enrolled site).
     *  2. Write the network drop-in template with the map file path baked in.
     *  3. Ensure WP_CACHE is true in wp-config.php.
     *
     * @return true|WP_Error
     */
    public static function install_network() {
        if ( self::status() === 'foreign' ) {
            $owner = self::detect_conflict() ?: 'another plugin';
            return new WP_Error( 'nexeng_dropin_conflict', sprintf(
                'Refusing to overwrite wp-content/advanced-cache.php — it appears to be owned by %s. ' .
                'Disable that plugin first, or remove the file manually.',
                $owner
            ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe (no write performed here); WP_Filesystem offers no lighter equivalent for a pre-write capability check.
        if ( ! is_writable( WP_CONTENT_DIR ) ) {
            return new WP_Error( 'nexeng_dropin_unwritable',
                'wp-content/ is not writable — PHP cannot create files there.' );
        }

        $template_path = NEXENG_PLUGIN_DIR . self::TEMPLATE_NETWORK;
        $template = @file_get_contents( $template_path );
        if ( $template === false ) {
            return new WP_Error( 'nexeng_dropin_template',
                'Could not read network drop-in template at ' . $template_path );
        }

        // Step 1: Build/rebuild the network map before baking in its path.
        if ( class_exists( 'NEXENG_Multisite' ) ) {
            NEXENG_Multisite::rebuild_network_map();
            $map_file = NEXENG_Multisite::map_file_path();
        } else {
            $map_file = '';
        }

        // Step 2: Bake the map file path (and single-site fallback values) into the template.
        $upload      = wp_upload_dir();
        $static_root = untrailingslashit( $upload['basedir'] ) . '/nexora-static';
        $home_prefix = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        $version     = defined( 'NEXENG_VERSION' ) ? NEXENG_VERSION : '2.0';

        $contents = strtr( $template, [
            '__NEXENG_MAP_FILE__'    => self::php_string( $map_file ),
            '__NEXENG_STATIC_ROOT__' => self::php_string( $static_root ),
            '__NEXENG_HOME_PREFIX__' => self::php_string( $home_prefix ),
            '__NEXENG_VERSION__'     => preg_replace( '/[^0-9.\-a-zA-Z]/', '', $version ),
            '__NEXENG_PRIVATE_DIR__' => self::php_string( self::private_dir() ),
            '__NEXENG_CONTENT_REL__' => self::php_string( self::relative_to_abspath( WP_CONTENT_DIR ) ),
            '__NEXENG_INC_REL__'     => self::php_string( trim( WPINC, '/' ) ),
        ] );

        // Step 3: Atomic write.
        $dest = self::dropin_path();
        $tmp  = $dest . '.tmp.' . wp_generate_password( 12, false );
        if ( @file_put_contents( $tmp, $contents, LOCK_EX ) === false ) {
            return new WP_Error( 'nexeng_dropin_write', 'Could not write drop-in to ' . $dest );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic drop-in swap of advanced-cache.php; rename() is required for atomicity (WP_Filesystem::move is not guaranteed atomic).
        if ( ! @rename( $tmp, $dest ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Atomic drop-in install/cleanup; uses native unlink alongside rename for an atomic swap of advanced-cache.php.
            @unlink( $tmp );
            return new WP_Error( 'nexeng_dropin_rename', 'Could not move drop-in into place.' );
        }

        // Step 4: Ensure WP_CACHE (non-fatal if config is read-only).
        $wp_cache_result = self::set_wp_cache( true );
        update_option( 'nexeng_dropin_wpcache_writable', $wp_cache_result === true ? 'yes' : 'no', false );

        return true;
    }

    /**
     * Removes the drop-in (only if the signature is ours) and unsets WP_CACHE.
     */
    public static function uninstall(): bool {
        if ( self::status() === 'ours' ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Atomic drop-in install/cleanup; uses native unlink alongside rename for an atomic swap of advanced-cache.php.
            @unlink( self::dropin_path() );
        }
        self::set_wp_cache( false );

        // Remove the Stealth Asset router directory/htaccess if it exists.
        if ( class_exists( 'NEXENG_SSG' ) ) {
            NEXENG_SSG::get_instance()->uninstall_stealth_asset_rule();
        }

        return true;
    }

    // ─── wp-config.php WP_CACHE Writer ───────────────────────────────────────

    /**
     * Adds, updates, or removes the WP_CACHE constant in wp-config.php.
     *
     * @return true|WP_Error true if the desired state is achieved, WP_Error otherwise.
     */
    public static function set_wp_cache( bool $on ) {
        $config_path = self::wp_config_path();
        if ( ! $config_path ) {
            return new WP_Error( 'nexeng_wpcfg_missing', 'wp-config.php not found.' );
        }
        if ( ! is_readable( $config_path ) ) {
            return new WP_Error( 'nexeng_wpcfg_unreadable', 'wp-config.php is not readable.' );
        }

        $contents = file_get_contents( $config_path );
        if ( $contents === false ) {
            return new WP_Error( 'nexeng_wpcfg_readfail', 'Could not read wp-config.php.' );
        }

        $has_define = (bool) preg_match( "/^\s*define\s*\(\s*['\"]WP_CACHE['\"]\s*,/im", $contents );

        if ( $on ) {
            if ( $has_define ) {
                $new = preg_replace(
                    "/^(\s*)define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*[^)]*\)\s*;.*$/im",
                    "$1define( 'WP_CACHE', true ); " . self::WPCFG_MARKER,
                    $contents,
                    1
                );
            } else {
                // Insert before "stop editing" line, or before wp-settings require.
                $insertion = "define( 'WP_CACHE', true ); " . self::WPCFG_MARKER . "\n";
                if ( ( $pos = strpos( $contents, "/* That's all, stop editing!" ) ) !== false
                     || ( $pos = strpos( $contents, "/* Add any custom values" ) ) !== false ) {
                    $new = substr( $contents, 0, $pos ) . $insertion . substr( $contents, $pos );
                } elseif ( preg_match( "/^require_once[^\n]*wp-settings\.php/m", $contents, $m, PREG_OFFSET_CAPTURE )
                       || preg_match( "/^require[^\n]*wp-settings\.php/m",      $contents, $m, PREG_OFFSET_CAPTURE ) ) {
                    $new = substr( $contents, 0, $m[0][1] ) . $insertion . substr( $contents, $m[0][1] );
                } else {
                    return new WP_Error( 'nexeng_wpcfg_anchor', 'Could not find a safe insertion point in wp-config.php.' );
                }
            }
        } else {
            if ( ! $has_define ) {
                return true;
            }
            // Only remove the line if it carries our marker — never touch
            // foreign WP_CACHE definitions (other caching plugins, custom code).
            if ( strpos( $contents, self::WPCFG_MARKER ) !== false ) {
                $new = preg_replace(
                    "/^\s*define\s*\(\s*['\"]WP_CACHE['\"]\s*,[^;]*;[^\n]*" . preg_quote( self::WPCFG_MARKER, '/' ) . "[^\n]*\n?/im",
                    '',
                    $contents,
                    1
                );
            } else {
                return true; // Foreign WP_CACHE — leave as-is.
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe (no write performed here); WP_Filesystem offers no lighter equivalent for a pre-write capability check.
        if ( ! is_writable( $config_path ) ) {
            return new WP_Error( 'nexeng_wpcfg_unwritable',
                'wp-config.php exists but is not writable. Add this line manually: ' .
                "define( 'WP_CACHE', true );"
            );
        }

        // Remove any .ncx-bak left by an earlier version. Those files contain
        // live database credentials, so clearing them is a fix in its own right
        // rather than tidiness — a site that ever ran the old code still has one.
        $legacy_bak = $config_path . '.ncx-bak';
        if ( file_exists( $legacy_bak ) ) {
            wp_delete_file( $legacy_bak );
        }

        // No on-disk backup. We used to copy wp-config.php to a .ncx-bak file
        // beside it, which left the database credentials sitting in a second
        // file — inside the web root, never cleaned up, and only chmod 0600 on
        // hosts that honour it. $contents already holds the original, so a
        // failed write can be rolled back from memory instead, which protects
        // the same failure case without ever putting the secrets on disk twice.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- wp-config.php sits outside WP_Filesystem's remit and must be written before WP is fully loaded.
        if ( @file_put_contents( $config_path, $new, LOCK_EX ) === false ) {
            return new WP_Error( 'nexeng_wpcfg_writefail', 'Failed to write wp-config.php.' );
        }

        // Verify what landed. A truncated or partial write here would take the
        // whole site down, so confirm and restore rather than trust the result.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- see above.
        $verify = @file_get_contents( $config_path );
        if ( false === $verify || strlen( $verify ) < strlen( $new ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- restoring the original we read moments ago.
            @file_put_contents( $config_path, $contents, LOCK_EX );
            return new WP_Error( 'nexeng_wpcfg_verifyfail',
                'wp-config.php was written but did not verify; the original has been restored.'
            );
        }

        return true;
    }

    private static function wp_config_path(): ?string {
        // Standard location.
        if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
            return ABSPATH . 'wp-config.php';
        }
        // One level up — only if the parent dir doesn't itself have wp-settings.php.
        $parent = dirname( ABSPATH );
        if ( file_exists( $parent . '/wp-config.php' ) && ! file_exists( $parent . '/wp-settings.php' ) ) {
            return $parent . '/wp-config.php';
        }
        return null;
    }

    /** Safely emits a PHP single-quoted string literal (escapes \\ and '). */
    /**
     * Absolute path to the plugin's private uploads directory.
     *
     * Resolved from wp_upload_dir() so it follows a moved WP_CONTENT_DIR, a
     * custom uploads path, or a per-site multisite layout. The drop-ins cannot
     * call wp_upload_dir() themselves — they run before WordPress loads — so
     * the value is baked into them at install time.
     *
     * @return string Empty when uploads cannot be resolved.
     */
    private static function private_dir(): string {
        $uploads = wp_upload_dir();
        if ( empty( $uploads['basedir'] ) ) {
            return '';
        }
        return rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' ) . '/nexora-private';
    }

    private static function php_string( string $s ): string {
        return str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $s );
    }

    /**
     * Expresses an absolute path as a slash-terminated path relative to ABSPATH.
     *
     * Used to bake wp-content's real location into the drop-in. Falls back to
     * the directory's own basename when it sits outside ABSPATH entirely (some
     * hosts place wp-content beside WordPress rather than inside it) — the
     * drop-in only ever joins this onto ABSPATH, so an unresolvable layout
     * degrades to "asset not found" rather than reading somewhere unintended.
     *
     * @param string $abs Absolute directory path.
     * @return string e.g. "wp-content/"
     */
    private static function relative_to_abspath( string $abs ): string {
        $abs  = rtrim( str_replace( '\\', '/', $abs ), '/' );
        $root = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );

        if ( '' !== $root && 0 === strpos( $abs, $root . '/' ) ) {
            return ltrim( substr( $abs, strlen( $root ) ), '/' );
        }
        return basename( $abs );
    }
}
