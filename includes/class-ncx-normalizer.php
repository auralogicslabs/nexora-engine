<?php
/**
 * Nexora Engine — Normalizer (V1.5.0 - Global Variable Bridge)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NEXENG_Normalizer {

    public function normalize( int $post_id ): array {
        // Cache the normalized payload. This method is called by the public REST
        // endpoint /nexeng/v1/public/page/{slug} on every SPA click — without this
        // cache, every navigation pays the full HTTP loopback cost (~2s on live
        // HTTPS, ~50ms on localhost — which is why production "feels" slow even
        // when localhost is snappy). Invalidated on save_post (see nexora-engine.php).
        $cache_key = 'nexeng_norm_' . get_current_blog_id() . '_' . $post_id;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['content'] ) ) {
            return $cached;
        }

        try {
            $full_html = $this->capture_fidelity_html( $post_id );
            if ( empty( $full_html ) ) return $this->emergency_fallback($post_id);

            $full_html = $this->neutralize_conflicts( $full_html );
            $body_data = $this->extract_fidelity_content( $full_html );

            // GLOBAL BRIDGE: Extract full variable DNA (Colors, Weights, Fonts)
            $global_dna = $this->extract_global_dna();

            $payload = [
                'id'      => $post_id,
                'title'   => get_the_title( $post_id ),
                'content' => $body_data['content'],
                'body_class' => $body_data['class'],
                'assets'  => $this->get_assets_directly($full_html),
                'config'  => $this->extract_script_data( $full_html, 'elementorFrontendConfig' ),
                'global_style' => $global_dna['css'],
                'variables' => $global_dna['variables'],
                'fonts'   => $global_dna['fonts'],
                'meta'    => [ 'title' => get_the_title( $post_id ) ]
            ];

            // 24h TTL — earlier invalidation is wired in nexora-engine.php.
            set_transient( $cache_key, $payload, DAY_IN_SECONDS );

            return $payload;
        } catch ( Throwable $e ) {
            return $this->emergency_fallback($post_id);
        }
    }

    private function extract_global_dna(): array {
        $dna = [ 'css' => '', 'fonts' => [], 'variables' => [] ];
        if ( ! did_action( 'elementor/loaded' ) ) return $dna;

        $kit_id = get_option( 'elementor_active_kit' );
        if ( ! $kit_id ) return $dna;

        // Extract Global Kit CSS
        $upload_dir = wp_get_upload_dir();
        $kit_file = $upload_dir['basedir'] . '/elementor/css/post-' . $kit_id . '.css';
        if ( file_exists( $kit_file ) ) {
            $dna['css'] = file_get_contents( $kit_file );
            // Surgically extract variables from the CSS if any
            if ( preg_match( '/:root\s*{(.*?)}/s', $dna['css'], $m ) ) {
                $vars = explode( ';', $m[1] );
                foreach ( $vars as $var ) {
                    if ( trim($var) ) $dna['variables'][] = trim($var);
                }
            }
        }

        // Extract Typography & Color DNA from Kit Settings
        $kit_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
        if ( is_array($kit_settings) ) {
            foreach ( $kit_settings as $key => $val ) {
                // Find Global Colors and Typography
                if ( strpos($key, 'system_color') !== false || strpos($key, 'system_typography') !== false || strpos($key, 'global_color') !== false ) {
                    if ( is_string($val) ) $dna['variables'][] = "--e-global-" . str_replace('_', '-', $key) . ": " . $val;
                }
                // Find Font Families
                if ( strpos($key, 'font_family') !== false && ! empty($val) ) {
                    if ( ! in_array($val, $dna['fonts']) ) $dna['fonts'][] = $val;
                }
            }
        }
        return $dna;
    }

    private function neutralize_conflicts( string $html ): string {
        // preg_replace_callback — NOT preg_replace — is required when the
        // replacement is a callable. Using preg_replace with a closure silently
        // produces wrong output (PHP stringifies the closure), destroying all JS.
        $html = preg_replace_callback( '/<script([^>]*)>(.*?)<\/script>/is', function ( $m ) {
            // Leave external scripts (src="…") untouched.
            if ( preg_match( '/\bsrc\s*=/i', $m[1] ) ) {
                return $m[0];
            }
            $script = $m[2];
            if ( strpos( $script, 'lazyloadRunObserver' ) !== false || strpos( $script, 'events' ) !== false ) {
                $script = str_replace( [ 'const ', 'let ' ], 'window.', $script );
            }
            return '<script' . $m[1] . '>' . $script . '</script>';
        }, $html );
        return $html;
    }

    private function capture_fidelity_html( int $post_id ): string {
        $url = add_query_arg( 'nexeng_render_master', '1', get_permalink($post_id) );
        $response = wp_remote_get( $url, [ 
            'timeout'   => 15, 'sslverify' => false, 'user-agent' => 'NexoraMasterCapture/1.5'
        ] );
        if ( is_wp_error( $response ) ) return '';
        return wp_remote_retrieve_body( $response );
    }

    private function extract_fidelity_content( string $html ): array {
        $data = [ 'content' => '', 'class' => '' ];
        if ( preg_match( '/<body[^>]*class=["\']([^"\']+)["\'][^>]*>/is', $html, $matches ) ) $data['class'] = $matches[1];
        if ( preg_match( '/<div id="ncx-v"[^>]*>(.*?)<\/div>/is', $html, $matches ) ) {
            $data['content'] = $matches[1];
        } else if ( preg_match( '/<main[^>]*>(.*?)<\/main>/is', $html, $matches ) ) {
            $data['content'] = $matches[1];
        } else if ( preg_match( '/<body[^>]*>(.*?)<\/body>/is', $html, $matches ) ) {
            $data['content'] = $matches[1];
        }
        return $data;
    }

    private function get_assets_directly( string $html ): array {
        $assets = [ 'styles' => [], 'scripts' => [] ];
        preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $styles );
        if ( ! empty( $styles[1] ) ) {
            foreach ( $styles[1] as $url ) {
                if ( strpos( $url, 'admin-bar' ) === false ) $assets['styles'][] = $url;
            }
        }
        preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $scripts );
        if ( ! empty( $scripts[1] ) ) {
            foreach ( $scripts[1] as $url ) {
                if ( strpos( $url, 'admin-bar' ) === false && strpos( $url, 'wp-emoji' ) === false ) $assets['scripts'][] = $url;
            }
        }
        return $assets;
    }

    private function extract_script_data( string $html, string $var_name ): array {
        preg_match( '/var ' . preg_quote( $var_name ) . ' = ({.*?});/s', $html, $matches );
        $config = ! empty( $matches[1] ) ? json_decode( $matches[1], true ) : [];
        $config['breakpoints'] = [ 'xs' => 0, 'sm' => 480, 'md' => 768, 'lg' => 1025, 'xl' => 1440, 'xxl' => 1600 ];
        $config['activeBreakpoints'] = ["viewport_mobile", "viewport_tablet"];
        return $config;
    }

    private function emergency_fallback( int $post_id ): array {
        return [ 'id' => $post_id, 'title' => get_the_title($post_id), 'content' => '', 'assets' => [] ];
    }
}
