<?php
/**
 * Nexora Engine — Addons / Ecosystem page
 *
 * Lists all official Nexora addons with live install/activation status.
 * The __call dispatcher in class-ncx-admin.php includes this file automatically
 * when the user visits admin.php?page=ncx-addons.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Registry is loaded via NEXENG_Admin::get_addon_registry() — already called by
// render_admin_frame_open so $this is in scope via the include context.
$addons   = NEXENG_Admin::get_instance()->get_addon_registry();
$is_pro   = class_exists( 'NEXENG_Licence' ) && NEXENG_Licence::is_pro();

// Count active addons for the header pill.
$active_count = count( array_filter( $addons, fn( $a ) => $a['status'] === 'active' ) );

/**
 * Helper: returns the activate URL for a plugin file (must already be installed).
 */
$addon_activate_url = function( string $file ): string {
    return wp_nonce_url(
        admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ),
        'activate-plugin_' . $file
    );
};
?>

<!-- ── Page header ─────────────────────────────────────────────────────── -->
<div class="ncx-header">
    <div class="ncx-header-title">
        <h1><?php esc_html_e( 'Nexora Ecosystem', 'nexora-engine' ); ?></h1>
        <p><?php esc_html_e( 'Official addons that extend your static infrastructure. Each addon is built by Auralogics Labs and integrates directly with Nexora Engine.', 'nexora-engine' ); ?></p>
    </div>
    <?php if ( $active_count > 0 ) : ?>
    <div class="ncx-header-actions">
        <span class="ncx-addon-connected-summary">
            <span class="dashicons dashicons-yes-alt"></span>
            <?php
            echo esc_html( sprintf(
                /* translators: %d: number of items. */
                _n( '%d addon connected', '%d addons connected', $active_count, 'nexora-engine' ),
                $active_count
            ) );
            ?>
        </span>
    </div>
    <?php endif; ?>
</div>

<!-- ── Addon grid ──────────────────────────────────────────────────────── -->
<div class="ncx-addons-grid">
    <?php foreach ( $addons as $addon ) :
        $status    = $addon['status'];      // active | installed | not-installed | coming-soon
        $card_cls  = 'ncx-addon-card ncx-addon--' . esc_attr( $status );
    ?>
    <div class="<?php echo esc_attr( $card_cls ); ?>">

        <!-- Top accent bar (colored per status) -->
        <div class="ncx-addon-accent"></div>

        <!-- Card header: icon + meta -->
        <div class="ncx-addon-card-header">
            <div class="ncx-addon-icon-wrap">
                <span class="dashicons <?php echo esc_attr( $addon['icon_dashicon'] ); ?>"></span>
            </div>
            <div class="ncx-addon-meta">
                <div class="ncx-addon-title-row">
                    <h3 class="ncx-addon-name"><?php echo esc_html( $addon['name'] ); ?></h3>
                    <?php if ( $addon['badge'] === 'recommended' ) : ?>
                        <span class="ncx-addon-badge ncx-badge--recommended"><?php esc_html_e( 'Recommended', 'nexora-engine' ); ?></span>
                    <?php elseif ( $addon['badge'] === 'coming-soon' ) : ?>
                        <span class="ncx-addon-badge ncx-badge--coming-soon"><?php esc_html_e( 'Coming Soon', 'nexora-engine' ); ?></span>
                    <?php endif; ?>
                </div>
                <span class="ncx-addon-tagline"><?php echo esc_html( $addon['tagline'] ); ?></span>
            </div>
        </div>

        <!-- Description -->
        <p class="ncx-addon-desc"><?php echo esc_html( $addon['description'] ); ?></p>

        <!-- Engine integration benefit -->
        <div class="ncx-addon-benefit">
            <span class="dashicons dashicons-superhero-alt"></span>
            <span><?php echo esc_html( $addon['benefit'] ); ?></span>
        </div>

        <!-- Footer: status pill + action button -->
        <div class="ncx-addon-footer">
            <div class="ncx-addon-footer-left">
                <?php if ( $status === 'active' ) : ?>
                    <span class="ncx-addon-status-pill ncx-pill--active">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'Active', 'nexora-engine' ); ?>
                    </span>
                <?php elseif ( $status === 'installed' ) : ?>
                    <span class="ncx-addon-status-pill ncx-pill--installed">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Installed', 'nexora-engine' ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( $addon['version'] && $status !== 'coming-soon' ) : ?>
                    <span class="ncx-addon-version">v<?php echo esc_html( $addon['version'] ); ?></span>
                <?php endif; ?>
            </div>
            <div class="ncx-addon-footer-right">
                <?php if ( $status === 'active' && $addon['settings_slug'] ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $addon['settings_slug'] ) ); ?>"
                       class="ncx-btn ncx-btn-sm ncx-btn-outline">
                        <?php esc_html_e( 'Open Settings', 'nexora-engine' ); ?>
                    </a>
                <?php elseif ( $status === 'installed' ) : ?>
                    <a href="<?php echo esc_url( $addon_activate_url( $addon['file'] ) ); ?>"
                       class="ncx-btn ncx-btn-sm ncx-btn-activate">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'Activate', 'nexora-engine' ); ?>
                    </a>
                <?php elseif ( $status === 'not-installed' ) : ?>
                    <?php if ( ! empty( $addon['wp_org_slug'] ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $addon['wp_org_slug'] ) ); ?>"
                           class="ncx-btn ncx-btn-sm">
                            <?php esc_html_e( 'Install', 'nexora-engine' ); ?>
                        </a>
                    <?php else : ?>
                        <button type="button" class="ncx-btn ncx-btn-sm ncx-btn-coming"
                                title="<?php esc_attr_e( 'Will be available on WordPress.org soon', 'nexora-engine' ); ?>"
                                disabled>
                            <?php esc_html_e( 'Coming to WP.org', 'nexora-engine' ); ?>
                        </button>
                    <?php endif; ?>
                <?php elseif ( $status === 'coming-soon' ) : ?>
                    <button type="button" class="ncx-btn ncx-btn-sm ncx-btn-coming" disabled>
                        <?php esc_html_e( 'Coming Soon', 'nexora-engine' ); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>

    </div>
    <?php endforeach; ?>

    <!-- More coming soon placeholder card -->
    <div class="ncx-addon-card ncx-addon--placeholder">
        <div class="ncx-addon-placeholder-inner">
            <span class="dashicons dashicons-plus-alt2"></span>
            <strong><?php esc_html_e( 'More addons coming', 'nexora-engine' ); ?></strong>
            <p><?php esc_html_e( 'More official Nexora addons are in development. Check back soon.', 'nexora-engine' ); ?></p>
        </div>
    </div>
</div>

<!-- ── About the ecosystem ─────────────────────────────────────────────── -->
<div class="ncx-addons-about">
    <div class="ncx-addons-about-icon">
        <span class="dashicons dashicons-superhero-alt"></span>
    </div>
    <div class="ncx-addons-about-text">
        <strong><?php esc_html_e( 'Built to work together', 'nexora-engine' ); ?></strong>
        <p>
            <?php esc_html_e(
                'Each Nexora addon is independently useful but designed to unlock deeper integration when paired with Nexora Engine. They share a common intelligence layer — so image optimisation, analytics, and static delivery are aware of each other without extra configuration.',
                'nexora-engine'
            ); ?>
        </p>
    </div>
</div>
