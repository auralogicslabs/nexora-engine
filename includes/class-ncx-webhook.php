<?php
/**
 * Nexora Engine — Webhook Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NEXENG_Webhook {

    private static ?NEXENG_Webhook $instance = null;

    public static function get_instance(): NEXENG_Webhook {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_after_insert_post', [ $this, 'trigger_revalidate' ], 10, 4 );
    }

    public function trigger_revalidate( $post_id, $post, $update, $post_before ): void {
        if ( 'publish' !== $post->post_status ) return;

        $url = get_option( 'nexeng_revalidate_url' );
        $secret = get_option( 'nexeng_revalidate_secret' );

        if ( ! $url ) return;

        wp_remote_post( $url, [
            'blocking' => false,
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => json_encode( [
                'event'   => 'post_update',
                'id'      => $post_id,
                'slug'    => $post->post_name,
                'secret'  => $secret,
                'timestamp'=> time()
            ] )
        ] );
    }
}
