<?php
/**
 * Nexora Engine — Pages & Posts Report
 *
 * Provides a comprehensive overview of every page's static status,
 * traffic metrics, and SEO health.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$analytics   = NEXENG_Analytics::get_instance();
$ssg         = NEXENG_SSG::get_instance();
$manifest    = $ssg->get_manifest();
$pending_count = method_exists( $ssg, 'pending_count' ) ? $ssg->pending_count() : 0;
$fatal_pages = $ssg->get_fatal_pages(); // [ post_id => ['code','message','ts'] ]

// Fetch all public post types (pages, posts, AND CPTs) — same set as eligible_post_ids()
$_report_types = get_post_types( [ 'public' => true ], 'names' );
unset( $_report_types['attachment'] ); // attachments have no browsable permalink

$posts = get_posts([
    'post_type'      => array_values( $_report_types ),
    'post_status'    => 'publish',
    'posts_per_page' => 200,
    'orderby'        => 'type',
    'order'          => 'ASC',
]);

// Fetch traffic data for the last 7 days to show in the list
$traffic_data = $analytics->get_top_pages(100);
$traffic_map  = [];
foreach ($traffic_data as $t) {
    $traffic_map[$t['url']] = $t['hits'];
}
?>

<div class="ncx-header">
    <div class="ncx-header-title">
        <h1>Pages & Posts Insight</h1>
        <p>Monitor performance and capture status across your entire content library</p>
    </div>
</div>

<?php if ( ! empty( $fatal_pages ) ) : ?>
<div class="ncx-pages-fatal-notice">
    <span class="dashicons dashicons-warning"></span>
    <div>
        <strong><?php
        /* translators: %d: number of blocked pages. */
        echo esc_html( sprintf( _n( '%d page is blocked — PHP fatal error on last capture attempt', '%d pages are blocked — PHP fatal error on last capture attempt', count( $fatal_pages ), 'nexora-engine' ), count( $fatal_pages ) ) ); ?></strong>
        <p><?php esc_html_e( 'These pages serve dynamically until fixed. Common cause: PHP memory exhausted (add define(\'WP_MEMORY_LIMIT\',\'512M\') to wp-config.php). Once fixed, click ↻ on each row to retry. A full Rebuild All also resets all blocks.', 'nexora-engine' ); ?></p>
    </div>
</div>
<?php endif; ?>

<?php if ( $pending_count > 0 ) : ?>
<div class="ncx-pages-refresh-notice">
    <span class="dashicons dashicons-update"></span>
    <div>
        <strong><?php
        /* translators: %d: number of changed pages awaiting refresh. */
        echo esc_html( sprintf( _n( '%d changed page needs refresh', '%d changed pages need refresh', $pending_count, 'nexora-engine' ), $pending_count ) ); ?></strong>
        <p><?php esc_html_e( 'Use the row action to regenerate only the changed page. Use Regenerate All only after theme, menu, or plugin-wide layout changes.', 'nexora-engine' ); ?></p>
    </div>
</div>
<?php endif; ?>

<div class="ncx-report-filters">
    <div class="ncx-filter-group ncx-filter-group--type">
        <span class="ncx-filter-label"><?php esc_html_e( 'Content Type', 'nexora-engine' ); ?></span>
        <button class="ncx-btn active" data-filter="all"><?php esc_html_e( 'All Content', 'nexora-engine' ); ?></button>
        <?php
        // Dynamically build filter buttons from post types actually present in $posts.
        $_types_present = array_unique( array_column( $posts, 'post_type' ) );
        sort( $_types_present );
        foreach ( $_types_present as $_pt ) :
            $pto = get_post_type_object( $_pt );
            $label = $pto ? $pto->labels->name : ucfirst( $_pt );
        ?>
        <button class="ncx-btn" data-filter="<?php echo esc_attr( $_pt ); ?>"><?php echo esc_html( $label ); ?></button>
        <?php endforeach; ?>
    </div>
    <div class="ncx-filter-tools">
        <label class="ncx-filter-select-wrap">
            <span><?php esc_html_e( 'Capture Status', 'nexora-engine' ); ?></span>
            <select class="ncx-capture-filter-select" aria-label="<?php esc_attr_e( 'Filter by capture status', 'nexora-engine' ); ?>">
                <option value="all"><?php esc_html_e( 'All statuses', 'nexora-engine' ); ?></option>
                <option value="captured"><?php esc_html_e( 'Captured', 'nexora-engine' ); ?></option>
                <option value="stale"><?php esc_html_e( 'Needs Refresh', 'nexora-engine' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pending', 'nexora-engine' ); ?></option>
                <?php if ( ! empty( $fatal_pages ) ) : ?>
                <option value="fatal"><?php esc_html_e( 'Blocked (Fatal)', 'nexora-engine' ); ?></option>
                <?php endif; ?>
            </select>
        </label>
        <div class="ncx-search-wrap">
            <span class="dashicons dashicons-search"></span>
            <input type="text" class="ncx-search-pages" placeholder="Search by title or URL...">
        </div>
    </div>
</div>

<div class="ncx-pages-table-container">
    <table class="ncx-pages-table">
        <thead>
            <tr>
                <th>Page / URL</th>
                <th>Type</th>
                <th>Capture Status</th>
                <th>7D Traffic</th>
                <th>Last Optimized</th>
                <th style="text-align:right">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($posts as $p): 
                $url = get_permalink($p->ID);
                $relative_url = wp_parse_url($url, PHP_URL_PATH) ?: '/';
                $home_path = rtrim(wp_parse_url(home_url(), PHP_URL_PATH) ?: '', '/');
                if ($home_path && strpos($relative_url, $home_path) === 0) {
                    $relative_url = substr($relative_url, strlen($home_path));
                }
                $relative_url = '/' . trim($relative_url, '/');
                if ($relative_url !== '/') {
                    $relative_url .= '/';
                }
                $is_captured = isset($manifest[$p->ID]);
                $is_stale = $is_captured && $ssg->is_post_stale( (int) $p->ID, $manifest[$p->ID] );
                $is_fatal_page = isset( $fatal_pages[ $p->ID ] );
                $fatal_info    = $is_fatal_page ? $fatal_pages[ $p->ID ] : null;
                $capture_state = $is_fatal_page ? 'fatal' : ( $is_stale ? 'stale' : ( $is_captured ? 'captured' : 'pending' ) );
                $hits = $traffic_map[$relative_url] ?? 0;
                $mtime = $is_captured ? $manifest[$p->ID]['generated_at'] : 0;
            ?>
            <tr class="ncx-page-row" data-type="<?php echo esc_attr( $p->post_type ); ?>" data-capture="<?php echo esc_attr( $capture_state ); ?>" data-title="<?php echo esc_attr($p->post_title); ?>"<?php if ( $is_fatal_page ) echo ' data-fatal="1"'; ?>>
                <td>
                    <div class="ncx-page-info">
                        <span class="title"><?php echo esc_html($p->post_title); ?></span>
                        <a href="<?php echo esc_url($url); ?>" target="_blank" class="url"><?php echo esc_html($relative_url); ?></a>
                    </div>
                </td>
                <td><span class="ncx-badge-type"><?php echo esc_html( ucfirst( $p->post_type ) ); ?></span></td>
                <td>
                    <?php if ($is_fatal_page): ?>
                        <?php
                        $fatal_msg = htmlspecialchars( $fatal_info['message'] ?? 'PHP fatal error during capture', ENT_QUOTES, 'UTF-8' );
                        $fatal_age = $fatal_info['ts'] ? human_time_diff( $fatal_info['ts'], time() ) . ' ago' : '';
                        ?>
                        <span class="ncx-badge ncx-badge-fatal" title="<?php echo esc_attr( $fatal_msg . ( $fatal_age ? ' · ' . $fatal_age : '' ) ); ?>">
                            <span class="dashicons dashicons-warning" style="font-size:12px;width:12px;height:12px;margin-right:3px;vertical-align:middle;"></span><?php esc_html_e( 'Blocked', 'nexora-engine' ); ?>
                        </span>
                    <?php elseif ($is_stale): ?>
                        <span class="ncx-badge warning"><?php esc_html_e( 'Needs Refresh', 'nexora-engine' ); ?></span>
                    <?php elseif ($is_captured): ?>
                        <span class="ncx-badge success">Captured</span>
                    <?php else: ?>
                        <span class="ncx-badge warning">Pending</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="ncx-traffic-cell">
                        <span class="hit-count"><?php echo number_format($hits); ?></span>
                        <span class="label">hits</span>
                    </div>
                </td>
                <td class="ncx-date-cell">
                    <?php echo $mtime ? esc_html( human_time_diff( $mtime ) . ' ago' ) : 'Never'; ?>
                </td>
                <td style="text-align:right">
                    <div class="ncx-row-actions">
                        <?php if ( $is_fatal_page ) : ?>
                        <button class="ncx-btn ncx-btn-sm ncx-btn-fatal-retry ncx-regen-one" data-id="<?php echo esc_attr( $p->ID ); ?>"
                                title="<?php esc_attr_e( 'Retry capture — clears the block and attempts again. Make sure you\'ve raised PHP memory first.', 'nexora-engine' ); ?>">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                        <?php else : ?>
                        <button class="ncx-btn ncx-btn-sm ncx-regen-one" data-id="<?php echo esc_attr( $p->ID ); ?>" title="Regenerate">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>" class="ncx-btn ncx-btn-sm" title="Edit SEO">
                            <span class="dashicons dashicons-edit"></span>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php ob_start(); ?>
.ncx-report-filters { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; gap: 16px; flex-wrap: wrap; }
.ncx-pages-refresh-notice { display:flex; align-items:flex-start; gap:12px; margin:0 0 18px; padding:14px 16px; border:1px solid #fde68a; border-radius:12px; background:#fffbeb; color:#78350f; }
.ncx-pages-refresh-notice .dashicons { width:20px; height:20px; font-size:20px; color:#d97706; margin-top:1px; }
.ncx-pages-refresh-notice strong { display:block; font-size:14px; color:#78350f; margin-bottom:2px; }
.ncx-pages-refresh-notice p { margin:0; color:#92400e; font-size:12px; line-height:1.5; }
.ncx-pages-fatal-notice { display:flex; align-items:flex-start; gap:12px; margin:0 0 18px; padding:14px 16px; border:1px solid #fecaca; border-radius:12px; background:#fff5f5; color:#7f1d1d; }
.ncx-pages-fatal-notice .dashicons { width:20px; height:20px; font-size:20px; color:#dc2626; margin-top:1px; flex-shrink:0; }
.ncx-pages-fatal-notice strong { display:block; font-size:14px; color:#991b1b; margin-bottom:2px; }
.ncx-pages-fatal-notice p { margin:0; color:#b91c1c; font-size:12px; line-height:1.5; }
.ncx-badge-fatal { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; font-size:11px; font-weight:700; padding:2px 8px; border-radius:4px; display:inline-flex; align-items:center; cursor:help; }
.ncx-btn-fatal-retry { border-color:#d97706 !important; color:#d97706 !important; }
.ncx-filter-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ncx-filter-label { color: var(--ncx-muted); font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; min-width: 94px; }
.ncx-filter-tools { display: flex; align-items: center; gap: 10px; margin-left: auto; }
.ncx-filter-select-wrap { display: flex; align-items: center; gap: 8px; }
.ncx-filter-select-wrap span { color: var(--ncx-muted); font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; white-space: nowrap; }
.ncx-filter-select-wrap select { min-height: 40px; padding: 0 34px 0 12px; border: 1px solid var(--ncx-brand-border); border-radius: 10px; background-color: #fff; color: var(--ncx-gray-900); font-size: 13px; box-shadow: 0 1px 2px rgba(15, 23, 42, .03); }
.ncx-filter-select-wrap select:focus { border-color: rgba(2,82,250,.45); box-shadow: 0 0 0 3px rgba(2,82,250,.1); outline: none; }
.ncx-search-wrap { position: relative; flex: 1; width: 320px; max-width: 360px; }
.ncx-search-wrap .dashicons { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--ncx-gray-400); }
.ncx-search-wrap input { width: 100%; padding: 10px 12px 10px 40px; border: 1px solid var(--ncx-brand-border); border-radius: 12px; background: #fff; }

.ncx-pages-table-container { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid var(--ncx-brand-border); }
.ncx-pages-table { width: 100%; border-collapse: collapse; text-align: left; }
.ncx-pages-table th { padding: 16px 20px; background: var(--ncx-brand-offwhite); font-size: 13px; font-weight: 600; color: var(--ncx-muted); border-bottom: 1px solid var(--ncx-brand-border); }
.ncx-pages-table td { padding: 16px 20px; border-bottom: 1px solid var(--ncx-gray-100); vertical-align: middle; }
.ncx-pages-table tr:last-child td { border-bottom: none; }

.ncx-page-info .title { display: block; font-weight: 600; color: var(--ncx-gray-900); margin-bottom: 2px; }
.ncx-page-info .url { font-size: 12px; color: var(--ncx-muted); text-decoration: none; }
.ncx-page-info .url:hover { color: var(--ncx-primary); }

.ncx-badge-type { font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--ncx-muted); background: var(--ncx-gray-100); padding: 2px 8px; border-radius: 4px; }
.ncx-traffic-cell .hit-count { font-weight: 700; color: var(--ncx-gray-900); }
.ncx-traffic-cell .label { font-size: 11px; color: var(--ncx-muted); text-transform: uppercase; margin-left: 4px; }
.ncx-date-cell { font-size: 13px; color: var(--ncx-muted); }

.ncx-row-actions { display: flex; gap: 8px; justify-content: flex-end; }
.ncx-row-actions .ncx-btn-sm { width: 32px; height: 32px; padding: 0; display: flex; align-items: center; justify-content: center; border-radius: 8px; }
@media (max-width: 960px) {
    .ncx-filter-tools { width: 100%; margin-left: 0; }
    .ncx-search-wrap { width: 100%; max-width: none; }
}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>
