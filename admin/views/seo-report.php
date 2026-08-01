<?php
/**
 * Nexora Engine — SEO Engine Report
 *
 * Provides a global overview of your site's SEO health, sitemap status,
 * and social card readiness.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Include only eligible CPTs — mirrors the exclusion list in NEXENG_SSG::is_eligible().
$_seo_types = get_post_types( [ 'public' => true ], 'names' );
foreach ( [ 'attachment', 'elementor_library', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ] as $_st_internal ) {
    unset( $_seo_types[ $_st_internal ] );
}

$posts = get_posts([
    'post_type'      => array_values( $_seo_types ),
    'post_status'    => 'publish',
    'posts_per_page' => -1,
]);

// ── Traffic insights (formerly on Pages & Posts page) ────────────────────────
// Enriches the SEO table with real 7-day visit counts so the report tells a
// complete story: SEO health + traffic performance in one view.
$_seo_analytics  = class_exists( 'NEXENG_Analytics' ) ? NEXENG_Analytics::get_instance() : null;
$_seo_traffic    = $_seo_analytics ? $_seo_analytics->get_top_pages( 200 ) : [];
$_seo_traffic_map = [];
foreach ( $_seo_traffic as $_t ) {
    $_seo_traffic_map[ $_t['url'] ] = (int) $_t['hits'];
}
$_seo_total_hits = array_sum( array_column( $_seo_traffic, 'hits' ) );

$missing_meta = 0;
$missing_og   = 0;
$schema_types = [];

foreach ($posts as $p) {
    $seo_data = get_post_meta($p->ID, '_nexeng_seo_data', true) ?: [];
    if (empty($seo_data['og_desc'])) $missing_meta++;
    if (empty($seo_data['og_image'])) $missing_og++;
    
    $type = $seo_data['schema_type'] ?? 'Article';
    if (!isset($schema_types[$type])) $schema_types[$type] = 0;
    $schema_types[$type]++;
}

$sitemap_url = home_url('/sitemap.xml');
?>

<div class="ncx-header">
    <div class="ncx-header-title">
        <h1>SEO & Metadata Report</h1>
        <p>Validate sitemap coverage, social metadata, and schema output before static delivery</p>
    </div>
    <div class="ncx-header-actions">
        <a href="<?php echo esc_url($sitemap_url); ?>" target="_blank" class="ncx-btn ncx-btn-outline">
            <span class="dashicons dashicons-external"></span> View Sitemap.xml
        </a>
    </div>
</div>

<div class="ncx-seo-grid-layout ncx-seo-grid-layout--4">
    <div class="ncx-card ncx-glass-card">
        <div class="ncx-metric-group">
            <span class="label"><?php esc_html_e( 'Sitemap Status', 'nexora-engine' ); ?></span>
            <div class="ncx-sitemap-status">
                <span class="ncx-dot" style="background:var(--ncx-green)"></span>
                <span class="status-text"><?php esc_html_e( 'Live', 'nexora-engine' ); ?></span>
            </div>
            <p class="ncx-p-muted"><?php
            /* translators: %d: number of indexed URLs. */
            echo esc_html( sprintf( _n( '%d URL indexed', '%d URLs indexed', count( $posts ), 'nexora-engine' ), count( $posts ) ) ); ?></p>
        </div>
    </div>
    <div class="ncx-card ncx-glass-card">
        <div class="ncx-metric-group">
            <span class="label"><?php esc_html_e( 'Social Readiness', 'nexora-engine' ); ?></span>
            <div class="ncx-stat-value"><?php echo esc_html( count($posts) > 0 ? round((count($posts) - $missing_og) / count($posts) * 100) : 0 ); ?>%</div>
            <p class="ncx-p-muted"><?php
            /* translators: %d: number of pages missing an OG image. */
            echo esc_html( sprintf( _n( '%d page missing OG image', '%d pages missing OG images', $missing_og, 'nexora-engine' ), $missing_og ) ); ?></p>
        </div>
    </div>
    <div class="ncx-card ncx-glass-card">
        <div class="ncx-metric-group">
            <span class="label"><?php esc_html_e( 'Schema Saturation', 'nexora-engine' ); ?></span>
            <div class="ncx-stat-value"><?php echo esc_html( count( $schema_types ) ); ?></div>
            <p class="ncx-p-muted"><?php esc_html_e( 'Active JSON-LD schema types', 'nexora-engine' ); ?></p>
        </div>
    </div>
    <div class="ncx-card ncx-glass-card">
        <div class="ncx-metric-group">
            <span class="label"><?php esc_html_e( 'Traffic (7 days)', 'nexora-engine' ); ?></span>
            <div class="ncx-stat-value"><?php echo esc_html( number_format( $_seo_total_hits ) ); ?></div>
            <p class="ncx-p-muted"><?php
            /* translators: %d: number of tracked pages. */
            echo esc_html( sprintf( _n( 'across %d tracked page', 'across %d tracked pages', count( $_seo_traffic ), 'nexora-engine' ), count( $_seo_traffic ) ) ); ?></p>
        </div>
    </div>
</div>

<div class="ncx-card ncx-glass-card ncx-full-width-card ncx-seo-table-card">
    <div class="ncx-card-header ncx-seo-table-head">
        <div>
            <h3>Content SEO Health</h3>
            <p>Review the metadata Nexora preserves during mirror generation.</p>
        </div>
        <span class="ncx-seo-table-count"><?php echo esc_html( count( $posts ) ); ?> URLs</span>
    </div>
    <div class="ncx-seo-table-container">
        <table class="ncx-full-table">
            <thead>
                <tr>
                    <th class="ncx-table-entity-col"><?php esc_html_e( 'Page / Post', 'nexora-engine' ); ?></th>
                    <th><?php esc_html_e( 'Meta Description', 'nexora-engine' ); ?></th>
                    <th><?php esc_html_e( 'Social Image', 'nexora-engine' ); ?></th>
                    <th><?php esc_html_e( 'Schema', 'nexora-engine' ); ?></th>
                    <th class="ncx-seo-traffic-col"><?php esc_html_e( 'Traffic (7D)', 'nexora-engine' ); ?></th>
                    <th class="ncx-table-action-col"><?php esc_html_e( 'Actions', 'nexora-engine' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($posts)): ?>
                    <tr><td colspan="5" style="padding:40px; text-align:center; color:#94a3b8;">No published content found.</td></tr>
                <?php endif; ?>

                <?php foreach ($posts as $p):
                    $seo_data = get_post_meta($p->ID, '_nexeng_seo_data', true) ?: [];
                    $has_desc = !empty($seo_data['og_desc']);
                    $has_og   = !empty($seo_data['og_image']) || has_post_thumbnail($p->ID);
                    $type     = $seo_data['schema_type'] ?? 'Article';
                    // Traffic lookup — build relative URL the same way analytics stores it.
                    $_seo_purl     = get_permalink( $p->ID );
                    $_seo_rel      = wp_parse_url( $_seo_purl, PHP_URL_PATH ) ?: '/';
                    $_seo_home_p   = rtrim( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '', '/' );
                    if ( $_seo_home_p && strpos( $_seo_rel, $_seo_home_p ) === 0 ) {
                        $_seo_rel = substr( $_seo_rel, strlen( $_seo_home_p ) );
                    }
                    $_seo_rel  = '/' . trim( $_seo_rel, '/' );
                    if ( $_seo_rel !== '/' ) $_seo_rel .= '/';
                    $_seo_hits = $_seo_traffic_map[ $_seo_rel ] ?? 0;
                ?>
                <tr>
                    <td class="ncx-table-entity-col">
                        <div class="ncx-page-info">
                            <span class="title"><?php echo esc_html($p->post_title); ?></span>
                            <span class="url"><?php echo esc_html( get_post_type($p->ID) ); ?></span>
                        </div>
                    </td>
                    <td>
                        <?php if ($has_desc): ?>
                            <span class="ncx-status-pill success"><?php esc_html_e( 'Optimized', 'nexora-engine' ); ?></span>
                        <?php else: ?>
                            <span class="ncx-status-pill warning"><?php esc_html_e( 'Missing', 'nexora-engine' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($has_og): ?>
                            <span class="ncx-status-pill success"><?php esc_html_e( 'Ready', 'nexora-engine' ); ?></span>
                        <?php else: ?>
                            <span class="ncx-status-pill error"><?php esc_html_e( 'Missing', 'nexora-engine' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><code class="ncx-code-inline"><?php echo esc_html($type); ?></code></td>
                    <td class="ncx-seo-traffic-col">
                        <?php if ( $_seo_hits > 0 ) : ?>
                        <div class="ncx-traffic-cell">
                            <span class="hit-count"><?php echo esc_html( number_format( $_seo_hits ) ); ?></span>
                            <span class="label"><?php esc_html_e( 'hits', 'nexora-engine' ); ?></span>
                        </div>
                        <?php else : ?>
                        <span class="ncx-seo-no-traffic">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="ncx-table-action-col">
                        <a href="<?php echo esc_url( get_edit_post_link($p->ID) ); ?>" class="ncx-btn ncx-btn-sm ncx-seo-action" title="<?php esc_attr_e( 'Optimize SEO', 'nexora-engine' ); ?>">
                            <span class="dashicons dashicons-edit"></span>
                            <span><?php esc_html_e( 'Optimize', 'nexora-engine' ); ?></span>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php ob_start(); ?>
.ncx-seo-grid-layout { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
.ncx-seo-grid-layout--4 { grid-template-columns: repeat(4, 1fr); }
.ncx-seo-traffic-col { text-align: right; white-space: nowrap; }
.ncx-traffic-cell .hit-count { font-weight: 700; color: var(--ncx-gray-900); }
.ncx-traffic-cell .label { font-size: 11px; color: var(--ncx-muted); text-transform: uppercase; margin-left: 4px; }
.ncx-seo-no-traffic { color: var(--ncx-muted); font-size: 13px; }
.ncx-seo-table-card { margin-top: 22px; padding: 0; overflow: hidden; }
.ncx-seo-table-head { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:20px 22px; border-bottom:1px solid var(--ncx-brand-border); background:#fff; }
.ncx-seo-table-head h3 { margin:0; color:var(--ncx-gray-950); font-size:16px; font-weight:700; }
.ncx-seo-table-head p { margin:4px 0 0; color:var(--ncx-muted); font-size:12px; }
.ncx-seo-table-count { padding:5px 10px; border:1px solid #dbe7fb; border-radius:999px; background:#f8fbff; color:var(--ncx-primary); font-size:12px; font-weight:700; white-space:nowrap; }
.ncx-seo-table-container { overflow-x:auto; }
.ncx-full-table { width: 100%; border-collapse: collapse; min-width: 760px; }
.ncx-full-table th { background: #f8fafc; text-align: left; padding: 13px 16px; font-size: 11px; text-transform: uppercase; color: var(--ncx-muted); font-weight: 700; letter-spacing:.05em; border-bottom: 1px solid var(--ncx-brand-border); }
.ncx-full-table td { padding: 16px; border-bottom: 1px solid #eef2f7; vertical-align: middle; }
.ncx-full-table tbody tr:hover td { background:#fbfdff; }
.ncx-full-table tr:last-child td { border-bottom: none; }
.ncx-table-action-col { text-align:right; }

.ncx-sitemap-status { display: flex; align-items: center; gap: 8px; margin: 8px 0; font-weight: 700; color: var(--ncx-gray-900); }
.ncx-stat-value { font-size: 34px; font-weight: 700; color: var(--ncx-gray-900); margin: 4px 0; letter-spacing: 0; }

.ncx-status-pill { font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 5px 10px; border-radius: 999px; display: inline-block; letter-spacing:.04em; }
.ncx-status-pill.success { background: var(--ncx-green-bg); color: var(--ncx-green-dark); }
.ncx-status-pill.warning { background: var(--ncx-amber-bg); color: var(--ncx-amber-dark); }
.ncx-status-pill.error { background: var(--ncx-red-bg); color: var(--ncx-red-dark); }
.ncx-code-inline { background: var(--ncx-brand-offwhite); padding: 4px 8px; border-radius: 6px; font-size: 11px; color: var(--ncx-muted); font-family: 'SF Mono', monospace; }
.ncx-seo-action { min-height:32px; gap:6px; border-radius:8px; }
@media (max-width: 1200px) {
    .ncx-seo-grid-layout--4 { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 960px) {
    .ncx-seo-grid-layout { grid-template-columns: 1fr; }
    .ncx-seo-grid-layout--4 { grid-template-columns: 1fr 1fr; }
    .ncx-seo-traffic-col { display: none; } /* hide on small screens — not critical */
}
@media (max-width: 640px) {
    .ncx-seo-grid-layout--4 { grid-template-columns: 1fr; }
}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>
