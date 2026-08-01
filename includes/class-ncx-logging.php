<?php
/**
 * Nexora Engine — Logging Class
 *
 * Handles lightweight logging for cache hits, misses, and TTFB samples.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Dedicated logging layer for the plugin's OWN nexeng_hits table. The table name is
// always built as $wpdb->prefix . 'nexeng_hits' (see __construct), never user input,
// so it cannot be a %s placeholder. These queries take no user-supplied values
// (only fixed time-window filters). Custom plugin tables are not object-cached and
// legitimately require direct queries. Disabling the corresponding sniffs file-wide:
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

class NEXENG_Logging {

    private static $instance = null;
    private $table_name;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'nexeng_hits';
        $this->create_table();
        $this->schedule_aggregation();
    }

    /**
     * Create the hits table if it doesn't exist.
     */
    private function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            blog_id INT NOT NULL DEFAULT 1,
            post_id BIGINT UNSIGNED DEFAULT NULL,
            url VARCHAR(1000) NOT NULL,
            hit_type ENUM('hit','miss') NOT NULL DEFAULT 'hit',
            response_time INT DEFAULT NULL,
            ip_hash VARCHAR(64) NOT NULL,
            ua_class ENUM('desktop','mobile','tablet','bot') NOT NULL DEFAULT 'desktop',
            ref_class ENUM('direct','search','social','internal','other') NOT NULL DEFAULT 'direct',
            country CHAR(2) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_blog_url (blog_id, url(191)),
            KEY idx_created (created_at),
            KEY idx_post_id (post_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Log a cache hit or miss.
     */
    public function log_hit( $url, $hit_type, $ttfb = null, $ip = null, $user_agent = null ) {
        global $wpdb;
        $wpdb->insert(
            $this->table_name,
            array(
                'blog_id'       => get_current_blog_id(),
                'url'           => esc_url_raw( $url ),
                'hit_type'      => $hit_type === 'miss' ? 'miss' : 'hit',
                'response_time' => null === $ttfb ? null : (int) $ttfb,
                'ip_hash'       => hash( 'sha256', (string) $ip . 'nexeng_salt' ),
                'ua_class'      => $this->classify_ua( (string) $user_agent ),
                'ref_class'     => 'direct',
                'country'       => NEXENG_Request::server( 'HTTP_CF_IPCOUNTRY' ) ?: null,
            ),
            array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Get cache hit ratio for last 24 hours.
     */
    public function get_hit_ratio_24h() {
        global $wpdb;
        $total_hits = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE hit_type = 'hit' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)" );
        $total_misses = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE hit_type = 'miss' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)" );
        $total = $total_hits + $total_misses;
        return $total > 0 ? round( ($total_hits / $total) * 100, 1 ) : 0;
    }

    /**
     * Get TTFB percentiles.
     */
    public function get_ttfb_percentiles() {
        global $wpdb;
        $ttfbs = $wpdb->get_col( "SELECT response_time FROM {$this->table_name} WHERE hit_type = 'hit' AND response_time IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY response_time" );
        if ( empty( $ttfbs ) ) {
            return array( 'p50' => 0, 'p95' => 0 );
        }
        $count = count( $ttfbs );
        $p50_index = floor( $count * 0.5 );
        $p95_index = floor( $count * 0.95 );
        return array(
            'p50' => $ttfbs[$p50_index],
            'p95' => $ttfbs[$p95_index],
        );
    }

    /**
     * Get top 10 pages by traffic (hits + misses) last 7 days.
     */
    public function get_top_pages_7d() {
        global $wpdb;
        return $wpdb->get_results( "SELECT url, COUNT(*) as hits FROM {$this->table_name} WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY url ORDER BY hits DESC LIMIT 10", ARRAY_A );
    }

    /**
     * Schedule daily aggregation cron.
     */
    private function schedule_aggregation() {
        if ( ! wp_next_scheduled( 'nexeng_aggregate_hits' ) ) {
            wp_schedule_event( time(), 'daily', 'nexeng_aggregate_hits' );
        }
        add_action( 'nexeng_aggregate_hits', array( $this, 'aggregate_hits' ) );
    }

    /**
     * Aggregate old hits (older than 7 days) into summary.
     * For now, just delete old data to keep table small.
     */
    public function aggregate_hits() {
        global $wpdb;
        // Delete hits older than 30 days to keep table small
        $wpdb->query( "DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
    }

    /**
     * Handle TTFB beacon from client.
     */
    public function handle_ttfb_beacon() {
        check_ajax_referer( 'nexeng_ttfb', 'nonce' );
        $user_agent = NEXENG_Request::user_agent();
        if ( is_user_logged_in() || $this->classify_ua( $user_agent ) === 'bot' ) {
            wp_die();
        }

        $ttfb = max( 1, intval( $_POST['ttfb'] ?? 0 ) );
        $url = wp_get_referer();
        if ( ! $url ) {
            wp_die();
        }

        $ip = NEXENG_Request::ip();
        $this->log_hit( $url, 'hit', $ttfb, $ip, $user_agent ); // Log as hit with TTFB
        wp_die();
    }

    private function classify_ua( $ua ) {
        $ua = strtolower( $ua );
        if ( strpos( $ua, 'bot' ) !== false || strpos( $ua, 'spider' ) !== false ) return 'bot';
        if ( strpos( $ua, 'mobile' ) !== false || strpos( $ua, 'android' ) !== false || strpos( $ua, 'iphone' ) !== false ) return 'mobile';
        if ( strpos( $ua, 'tablet' ) !== false || strpos( $ua, 'ipad' ) !== false ) return 'tablet';
        return 'desktop';
    }

    /**
     * Process the log file and insert into DB.
     */
    public function process_log_file() {
        $log_file = wp_upload_dir()['basedir'] . '/nexeng_hits.log';
        if ( ! file_exists( $log_file ) ) return;
        $lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        foreach ( $lines as $line ) {
            $data = json_decode( $line, true );
            if ( $data ) {
                $this->log_hit( $data['url'], $data['hit_type'], $data['ttfb'], $data['ip'], $data['user_agent'] );
            }
        }
        // Clear the log file
        file_put_contents( $log_file, '' );
    }
}
