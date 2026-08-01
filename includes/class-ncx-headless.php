<?php
/**
 * Nexora Engine — Headless Engine (V1.2.0)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NEXENG_Headless {

    private static ?NEXENG_Headless $instance = null;
    private NEXENG_Normalizer $normalizer;

    public static function get_instance(): NEXENG_Headless {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->normalizer = new NEXENG_Normalizer();
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( 'nexeng/v1', '/public/page', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_page' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'nexeng/v1', '/public/page/(?P<path>.+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_page' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Standard REST API Callback
     */
    public function get_page( WP_REST_Request $request ): WP_REST_Response {
        $path = $request->get_param( 'path' );
        $data = $this->get_page_data_directly($path);

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * Bulletproof Direct Data Fetcher
     * Can be called without a WP_REST_Request object.
     */
    public function get_page_data_directly( ?string $path ): array {
        // Handle empty path or root as Front Page
        if ( empty( $path ) || $path === '/' ) {
            $post_id = get_option( 'page_on_front' );
            if ( ! $post_id ) {
                $posts = get_posts( [ 'numberposts' => 1 ] );
                $post = ! empty( $posts ) ? $posts[0] : null;
            } else {
                $post = get_post( $post_id );
            }

            if ( ! $post ) {
                $post = (object) [
                    'ID'           => 0,
                    'post_title'   => get_bloginfo( 'name' ),
                    'post_content' => '<div class="ncx-welcome"><h1>Welcome to ' . esc_html( get_bloginfo( 'name' ) ) . '</h1><p>Start building your headless experience.</p></div>',
                    'post_type'    => 'page'
                ];
            }
        } else {
            $post = get_page_by_path( $path, OBJECT, [ 'post', 'page' ] );
        }

        // get_page_by_path() resolves a slug regardless of status, so without
        // this check the endpoint handed drafts, pending, private, scheduled and
        // trashed content to anyone who guessed the slug. Same answer as a real
        // miss, so the endpoint does not confirm that a hidden post exists.
        if ( ! $post || ! self::is_publicly_readable( $post ) ) {
            return [ 'success' => false, 'error' => 'Not found', 'path' => $path ];
        }

        return [
            'success' => true,
            'data'    => $this->normalizer->normalize( $post->ID )
        ];
    }

    /**
     * May this post be handed to an unauthenticated caller?
     *
     * The /public/page routes are intentionally public — they exist to feed a
     * headless front end — so permission_callback stays __return_true and the
     * check belongs on the content instead. Anything a logged-out visitor could
     * not read on the site itself must not be readable here either.
     *
     * @param \WP_Post|object $post Resolved post.
     * @return bool
     */
    private static function is_publicly_readable( $post ): bool {
        // The synthetic "welcome" placeholder has ID 0 and no stored row; it
        // contains no site content, so it is safe to return.
        if ( empty( $post->ID ) ) {
            return true;
        }

        // Covers status (publish only, plus 'inherit' for attachments) and
        // whether the post type is publicly queryable at all.
        if ( ! is_post_publicly_viewable( $post ) ) {
            return false;
        }

        // Password-protected posts are viewable, but their content is not — and
        // this endpoint returns normalized content, not the password form.
        if ( ! empty( $post->post_password ) ) {
            return false;
        }

        return true;
    }
}
