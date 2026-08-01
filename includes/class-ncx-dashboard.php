<?php
/**
 * Nexora Engine — Dashboard Class
 *
 * Handles dashboard data and actions.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXENG_Dashboard {

    private static $instance = null;
    private $logging;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logging = NEXENG_Logging::get_instance();
        add_action( 'wp_ajax_nexeng_ttfb_beacon', array( $this->logging, 'handle_ttfb_beacon' ) );
        add_action( 'wp_ajax_nopriv_nexeng_ttfb_beacon', array( $this->logging, 'handle_ttfb_beacon' ) );
    }

    /**
     * Get dashboard stats.
     */
    public function get_stats() {
        $analytics = NEXENG_Analytics::get_instance();
        
        $ssg = NEXENG_SSG::get_instance();
        $ssg_stats = $ssg->stats();
        $bulk_status = method_exists( $ssg, 'bulk_status' ) ? $ssg->bulk_status() : [];
        $pending_count = method_exists( $ssg, 'pending_count' ) ? (int) $ssg->pending_count() : 0;
        $analytics_stats = $analytics->get_stats();

        // Hardening toggles, split by tier so the dashboard score is scored
        // against only what the current plan can actually enable. Scoring a
        // free site against the Pro guards too would cap it at ~45% forever and
        // read as "insecure" when the user has done everything available to
        // them. Must stay in sync with the pro flags in Security.tsx.
        $free_rules = [
            'nexeng_secure_users_api',
            'nexeng_secure_author_enum',
            'nexeng_secure_xmlrpc',
            'nexeng_secure_remove_version',
            'nexeng_secure_login_errors',
        ];
        $pro_rules = [
            'nexeng_secure_rest_tighten',
            'nexeng_secure_rate_limit',
            'nexeng_secure_strong_pass',
            'nexeng_secure_login_rename',
            'nexeng_secure_disable_file_edit',
            'nexeng_secure_headers',
        ];

        $is_pro = class_exists( 'NEXENG_Licence' ) && NEXENG_Licence::is_pro();
        // Free installs are scored only on the guards they can turn on; Pro is
        // scored on the complete set.
        $hardening_rules = $is_pro ? array_merge( $free_rules, $pro_rules ) : $free_rules;

        $active_rules = 0;
        foreach ( $hardening_rules as $rule ) {
            $v = get_option( $rule );
            if ( $v === 'on' || $v === '1' || $v === true ) {
                $active_rules++;
            }
        }
        $total_rules = count( $hardening_rules );

        return array(
            'hit_ratio'          => $analytics_stats['hit_ratio'],
            'traffic_total_24h'  => $analytics_stats['traffic_total_24h'] ?? 0,
            'last_hit_at'        => $analytics_stats['last_hit_at'] ?? null,
            'static_files_count' => $ssg_stats['total_files'] ?? 0,
            'static_total_bytes' => $ssg_stats['total_bytes'] ?? 0,
            'last_regen'         => $ssg_stats['last_write'] ? gmdate('Y-m-d H:i:s', $ssg_stats['last_write']) : 'Never',
            'pending_count'      => $pending_count,
            'build_running'      => ! empty( $bulk_status['running'] ) && empty( $bulk_status['done'] ),
            'build_processed'    => (int) ( $bulk_status['processed'] ?? 0 ),
            'build_total'        => (int) ( $bulk_status['total'] ?? 0 ),
            'ttfb_p50'           => $analytics_stats['ttfb_p50'],
            'ttfb_p95'           => $analytics_stats['ttfb_p95'],
            'ttfb_samples'       => $analytics_stats['ttfb_samples'] ?? 0,
            'top_pages'          => $analytics_stats['top_pages'],
            'chart'              => $analytics_stats['chart'],
            'vitals'             => $analytics_stats['vitals'],
            'vitals_samples'     => $analytics_stats['vitals_samples'] ?? [ 'LCP' => 0, 'INP' => 0, 'CLS' => 0 ],
            'vitals_method'      => $analytics_stats['vitals_method'] ?? 'p75',
            'hardening_active'   => $active_rules,
            'hardening_total'    => $total_rules,
            'security_score'     => $total_rules > 0
                ? (int) round( ( $active_rules / $total_rules ) * 100 )
                : 0,
            'stuck_warning'      => $this->get_stuck_warning( $ssg_stats['last_write'] ?? null ),
        );
    }

    /**
     * Get stuck cache warning.
     */
    private function get_stuck_warning( $last_regen ) {
        if ( ! $last_regen || $last_regen === 'Never' ) {
            return '';
        }
        $last_regen_time = $last_regen;
        $days_since = ( time() - $last_regen_time ) / 86400;
        if ( $days_since > 6 ) {
            return "Homepage hasn't regenerated in " . round( $days_since ) . " days";
        }
        return '';
    }

    /**
     * Handle regenerate all action.
     */
    public function regenerate_all() {
        check_ajax_referer( 'nexeng_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        $ssg = NEXENG_SSG::get_instance();
        if ( ! NEXENG_SSG::is_enabled() ) {
            wp_send_json_error( [ 'message' => 'SSG is not enabled. Please enable it in the settings.' ] );
            return;
        }
        $result = $ssg->bulk_start();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        } else {
            wp_send_json_success( [
                'started'   => true,
                'total'     => (int) $result,
                'processed' => 0,
                'errors'    => 0,
                'message'   => 'Static rebuild started in the background.',
            ] );
        }
    }

    /**
     * Handle purge cache action.
     */
    public function purge_cache() {
        check_ajax_referer( 'nexeng_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        $ssg = NEXENG_SSG::get_instance();
        $result = $ssg->purge_all();
        wp_send_json_success( $result );
    }
}
