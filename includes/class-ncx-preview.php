<?php
/**
 * Nexora Engine — Preview Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NEXENG_Preview {

    private static ?NEXENG_Preview $instance = null;

    public static function get_instance(): NEXENG_Preview {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'preview_post_link', [ $this, 'override_preview_link' ], 10, 2 );
    }

    public function override_preview_link( $link, $post ) {
        if ( get_option( 'nexeng_headless_mode' ) !== 'on' ) {
            return $link;
        }

        $token = wp_generate_password( 24, false );
        set_transient( 'nexeng_preview_' . $token, $post->ID, HOUR_IN_SECONDS );

        return add_query_arg( [
            'nexeng_preview' => $token,
            'id'          => $post->ID
        ], home_url( '/' . $post->post_name ) );
    }
}
