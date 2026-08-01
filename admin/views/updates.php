<?php
/**
 * Nexora Engine — Version & Licensing
 *
 * Displays the current Freemius plan, account details, and plugin changelog.
 * License activation / deactivation is handled entirely by Freemius via its
 * own Account page (admin.php?page=nexora-account).
 *
 * @package NexoraEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Permission denied.', 'nexora-engine' ) );
}

// ── License state ─────────────────────────────────────────────────────────────
// NOTE: ?nexeng_sync=1 is handled in nexora-engine.php admin_init (before output)
//       so wp_safe_redirect() works.  No redirect logic belongs in this view.
$info         = \NexoraEngine\Licensing\LicenseManager::instance()->get_info();
$tier         = $info['tier'];         // 'free' | 'pro'
$status       = $info['status'];       // 'active' | 'expired' | 'unverified'
$is_active    = $info['is_active'];
$dev_override = $info['dev_override'];
$provider     = $info['provider'];     // 'freemius' | 'free'
$user_name    = $info['user_name'];
$user_email   = $info['user_email'];
$plan_title   = $info['plan_title'];
$expiry       = $info['expiry'];
$site_count   = $info['site_count'];

$expiry_ts    = $info['expiry_ts'];   // Unix timestamp; 0 = lifetime or unavailable
$quota        = $info['quota'];       // Max sites allowed; 0 = unlimited

$is_paid      = ( 'pro' === $tier || in_array( $tier, array( 'agency', 'enterprise', 'cloud' ), true ) );

// Days remaining calculation
$is_lifetime  = ( $expiry === 'Lifetime' );
$days_left    = ( $expiry_ts > 0 ) ? (int) ceil( ( $expiry_ts - time() ) / DAY_IN_SECONDS ) : null;
$validity_state = 'none'; // 'valid' | 'warning' | 'expired' | 'lifetime' | 'none'
if ( $is_lifetime ) {
	$validity_state = 'lifetime';
} elseif ( null !== $days_left ) {
	if ( $days_left <= 0 )  $validity_state = 'expired';
	elseif ( $days_left <= 30 ) $validity_state = 'warning';
	else $validity_state = 'valid';
}

// Avatar initial from user name or email
$avatar_initial = '';
if ( $user_name ) {
	$avatar_initial = strtoupper( substr( wp_strip_all_tags( $user_name ), 0, 1 ) );
} elseif ( $user_email ) {
	$avatar_initial = strtoupper( substr( $user_email, 0, 1 ) );
}

// When the plan comes entirely from DevOverrides (NEXORA_DEV_MODE), Freemius
// is not queried so $status is always 'unverified'.  Show a clear "Dev Mode"
// label instead of the misleading "Checking…" dot.
if ( $dev_override && 'unverified' === $status ) {
	$status       = 'active';
	$status_label = esc_html__( 'Dev Mode', 'nexora-engine' );
}

// ── URLs ──────────────────────────────────────────────────────────────────────
// Account URL: use Freemius's own admin page when the Account submenu is active
// (it is kept enabled so paid users can manage their subscription).
// Falls back to the Freemius users portal for hosting environments where the
// admin page URL cannot be determined at render time.
$_adapter     = \NexoraEngine\Licensing\FreemiusAdapter::instance();
$account_url  = $_adapter->is_available()
	? $_adapter->get_account_url()
	: admin_url( 'admin.php?page=nexora-account' );
if ( empty( $account_url ) ) {
	$account_url = admin_url( 'admin.php?page=nexora-account' );
}
$upgrade_url  = \NexoraEngine\Licensing\FeatureGate::get_upgrade_url( 'pro' );

// ── Plan display helpers ──────────────────────────────────────────────────────
$tier_labels = array(
	'free'   => esc_html__( 'Free', 'nexora-engine' ),
	'pro'    => esc_html__( 'Pro', 'nexora-engine' ),
);
$tier_display = in_array( $tier, array( 'agency', 'enterprise', 'cloud' ), true ) ? 'pro' : $tier;
$tier_label = isset( $tier_labels[ $tier_display ] ) ? $tier_labels[ $tier_display ] : ucfirst( $tier_display );

$status_labels = array(
	'active'     => esc_html__( 'Active', 'nexora-engine' ),
	'expired'    => esc_html__( 'Expired', 'nexora-engine' ),
	'unverified' => esc_html__( 'Checking…', 'nexora-engine' ),
);
$status_label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( $status );

// ── Just-activated notice (set by after_license_activation hook) ──────────────
$just_activated = (bool) get_transient( 'nexeng_just_activated' );
if ( $just_activated ) {
	delete_transient( 'nexeng_just_activated' );
}

// ── Portal callback notice ────────────────────────────────────────────────────
$portal_just_connected = (bool) get_transient( 'nexeng_portal_just_connected' );
if ( $portal_just_connected ) {
	delete_transient( 'nexeng_portal_just_connected' );
}

// ── Portal connection state ───────────────────────────────────────────────────
$portal_key          = get_option( 'nexeng_portal_key', '' );
$portal_site         = get_option( 'nexeng_portal_site_id', '' );
$portal_connected_at = (int) get_option( 'nexeng_portal_connected', 0 );
$portal_connected    = $portal_connected_at > 0
                    || ( ! empty( $portal_key ) && ! empty( $portal_site ) );

// ── Sync URL (nonce-protected) ────────────────────────────────────────────────
$sync_url = wp_nonce_url(
	add_query_arg( 'nexeng_sync', '1', admin_url( 'admin.php?page=ncx-updates' ) ),
	'nexeng_sync_license'
);
?>

<?php if ( $just_activated ) : ?>
<div class="notice notice-success is-dismissible" style="margin:16px 0 0;">
	<p>
		<strong><?php esc_html_e( 'Nexora Engine Pro activated!', 'nexora-engine' ); ?></strong>
		<?php esc_html_e( 'Your Pro features are now unlocked. Welcome aboard.', 'nexora-engine' ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Next step — enable Ghost Protocol:', 'nexora-engine' ); ?></strong>
		<?php
		printf(
			/* translators: %s: URL to Headless CMS admin page */
			wp_kses(
				/* translators: %s: URL of the Headless CMS settings page. */
				__( 'Ghost Protocol has been <strong>automatically activated</strong> — WordPress fingerprints are now hidden from all responses. To also cloak your asset paths with <strong>Stealth Proxy</strong>, <a href="%s">open the Headless CMS page</a> and enable it under Ghost Protocol.', 'nexora-engine' ),
				[ 'strong' => [], 'a' => [ 'href' => [] ] ]
			),
			esc_url( admin_url( 'admin.php?page=ncx-headless' ) )
		);
		?>
	</p>
</div>
<?php endif; ?>

<?php if ( $portal_just_connected ) : ?>
<div class="notice notice-success is-dismissible" style="margin:16px 0 0;">
	<p>
		<span class="dashicons dashicons-admin-network" style="vertical-align:middle; margin-right:4px;"></span>
		<strong><?php esc_html_e( 'Site connected to Auralogics Portal!', 'nexora-engine' ); ?></strong>
		<?php esc_html_e( 'Your site is now linked. The portal can access telemetry and manage your infrastructure remotely.', 'nexora-engine' ); ?>
	</p>
</div>
<?php endif; ?>

<div class="ncx-header">
	<div class="ncx-header-title">
		<h1><?php esc_html_e( 'License & Updates', 'nexora-engine' ); ?></h1>
		<p>
			<?php esc_html_e( 'Your active infrastructure tier and plugin release details.', 'nexora-engine' ); ?>
			<?php if ( $is_paid ) : ?>
				<?php esc_html_e( 'Manage your plan via the', 'nexora-engine' ); ?>
				<a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Plan Management →', 'nexora-engine' ); ?></a>
			<?php endif; ?>
		</p>
	</div>
	<div class="ncx-header-actions">
		<span class="ncx-version-badge">v<?php echo esc_html( NEXENG_VERSION ); ?> Stable</span>
	</div>
</div>

<div class="ncx-license-overview">
	<div class="ncx-license-overview-item">
		<span><?php esc_html_e( 'Plan', 'nexora-engine' ); ?></span>
		<strong><?php echo esc_html( $tier_label ); ?></strong>
	</div>
	<div class="ncx-license-overview-item">
		<span><?php esc_html_e( 'License Status', 'nexora-engine' ); ?></span>
		<strong><?php echo esc_html( $status_label ); ?></strong>
	</div>
	<div class="ncx-license-overview-item">
		<span><?php esc_html_e( 'Sites', 'nexora-engine' ); ?></span>
		<strong>
			<?php
			if ( $quota > 0 ) {
				echo esc_html( sprintf( '%1$d / %2$d', (int) $site_count, (int) $quota ) );
			} else {
				echo esc_html( $is_paid ? __( 'Unlimited', 'nexora-engine' ) : __( 'Single site', 'nexora-engine' ) );
			}
			?>
		</strong>
	</div>
	<div class="ncx-license-overview-item">
		<span><?php esc_html_e( 'Update Channel', 'nexora-engine' ); ?></span>
		<strong><?php echo esc_html( $is_paid ? $tier_label : __( 'Free', 'nexora-engine' ) ); ?></strong>
	</div>
</div>

<div class="ncx-updates-grid">

	<!-- ── License Card ─────────────────────────────────────────────────── -->
	<div class="ncx-card ncx-glass-card <?php echo esc_attr( $is_paid ? 'ncx-active-card' : '' ); ?>">
		<div class="ncx-card-header">
			<div class="ncx-card-icon">
				<span class="dashicons <?php echo esc_attr( $is_paid ? 'dashicons-yes-alt' : 'dashicons-admin-network' ); ?>"></span>
			</div>
			<h3><?php esc_html_e( 'Nexora Engine License', 'nexora-engine' ); ?></h3>
		</div>
		<div class="ncx-card-body">

			<?php if ( $is_paid ) : ?>
				<!-- ── Active plan: show account details ────────────────── -->
				<div class="ncx-license-active">

					<!-- Plan + status badges -->
					<div class="ncx-plan-badge-row">
						<span class="ncx-plan-badge ncx-plan-badge--<?php echo esc_attr( $tier ); ?>">
							<?php echo esc_html( $tier_label ); ?>
						</span>
						<span class="ncx-status-dot ncx-status-dot--<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $status_label ); ?>
						</span>
						<?php if ( $dev_override ) : ?>
							<span class="ncx-badge ncx-badge--dev"><?php esc_html_e( 'DEV OVERRIDE', 'nexora-engine' ); ?></span>
						<?php endif; ?>
					</div>

					<!-- Account holder -->
					<?php if ( $user_name || $user_email ) : ?>
					<div class="ncx-license-user">
						<?php if ( $avatar_initial ) : ?>
						<div class="ncx-license-avatar"><?php echo esc_html( $avatar_initial ); ?></div>
						<?php endif; ?>
						<div class="ncx-license-user-info">
							<?php if ( $user_name ) : ?>
							<div class="ncx-license-user-name"><?php echo esc_html( $user_name ); ?></div>
							<?php endif; ?>
							<?php if ( $user_email ) : ?>
							<div class="ncx-license-user-email"><?php echo esc_html( $user_email ); ?></div>
							<?php endif; ?>
						</div>
					</div>
					<?php endif; ?>

					<!-- License validity -->
					<?php if ( 'none' !== $validity_state ) : ?>
					<div class="ncx-validity-block ncx-validity--<?php echo esc_attr( $validity_state ); ?>">
						<div class="ncx-validity-main">
							<span class="ncx-validity-icon dashicons
								<?php if ( $validity_state === 'lifetime' ) echo 'dashicons-awards';
								elseif ( $validity_state === 'valid' )    echo 'dashicons-yes-alt';
								elseif ( $validity_state === 'warning' )  echo 'dashicons-clock';
								else                                      echo 'dashicons-warning'; ?>">
							</span>
							<div>
								<span class="ncx-validity-label">
									<?php
									if ( $validity_state === 'lifetime' )      esc_html_e( 'Lifetime License', 'nexora-engine' );
									elseif ( $validity_state === 'expired' )   esc_html_e( 'License Expired', 'nexora-engine' );
									elseif ( $validity_state === 'warning' )   esc_html_e( 'Renewal Due Soon', 'nexora-engine' );
									else                                       esc_html_e( 'License Valid Until', 'nexora-engine' );
									?>
								</span>
								<?php if ( $expiry && ! $is_lifetime ) : ?>
								<span class="ncx-validity-date"><?php echo esc_html( $expiry ); ?></span>
								<?php endif; ?>
							</div>
						</div>
						<div class="ncx-validity-aside">
							<?php if ( $validity_state === 'lifetime' ) : ?>
								<span class="ncx-days-badge ncx-days-badge--lifetime"><?php esc_html_e( 'No expiry', 'nexora-engine' ); ?></span>
							<?php elseif ( $validity_state === 'expired' ) : ?>
								<span class="ncx-days-badge ncx-days-badge--expired"><?php esc_html_e( 'Expired', 'nexora-engine' ); ?></span>
								<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" class="ncx-btn ncx-btn-sm ncx-btn-primary" style="margin-top:6px;"><?php esc_html_e( 'Renew →', 'nexora-engine' ); ?></a>
							<?php elseif ( null !== $days_left ) : ?>
								<span class="ncx-days-badge ncx-days-badge--<?php echo esc_attr( $validity_state ); ?>">
									<?php
									/* translators: %d: number of items. */
									echo esc_html( sprintf( _n( '%d day', '%d days', $days_left, 'nexora-engine' ), $days_left ) ); ?>
								</span>
								<?php if ( $validity_state === 'warning' ) : ?>
								<a href="<?php echo esc_url( $account_url ); ?>" target="_blank" class="ncx-days-renew-link"><?php esc_html_e( 'Renew now →', 'nexora-engine' ); ?></a>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
					<?php endif; ?>

					<!-- Sites usage -->
					<?php if ( $site_count || $quota ) : ?>
					<div class="ncx-sites-row">
						<span class="ncx-sites-label"><?php esc_html_e( 'Sites activated', 'nexora-engine' ); ?></span>
						<span class="ncx-sites-value">
							<?php echo esc_html( $site_count ?: '0' ); ?>
							<?php if ( $quota > 0 ) : ?>
								<span class="ncx-sites-quota"><?php
								/* translators: %d: number of items. */
								echo esc_html( sprintf( __( 'of %d', 'nexora-engine' ), $quota ) ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<?php if ( $site_count === 0 ) : ?>
					<p style="font-size:12px;color:#64748b;margin:6px 0 0;line-height:1.5;">
						<?php esc_html_e( 'Staging and preview domains (e.g. *.staging.*, *.tempurl.host) are not counted against your license quota — they are always free. Your quota only counts production domains.', 'nexora-engine' ); ?>
					</p>
					<?php endif; ?>
					<?php endif; ?>

					<!-- Actions -->
					<div style="margin-top:16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
						<a href="<?php echo esc_url( $account_url ); ?>" class="ncx-btn ncx-btn-sm">
							<?php esc_html_e( 'Manage Plan →', 'nexora-engine' ); ?>
						</a>
						<a href="<?php echo esc_url( $sync_url ); ?>" class="ncx-btn ncx-btn-sm" style="background:transparent;border:1px solid var(--ncx-brand-border);color:var(--ncx-muted);" title="<?php esc_attr_e( 'Force-refresh entitlement state from Freemius', 'nexora-engine' ); ?>">
							<span class="dashicons dashicons-update" style="font-size:14px;vertical-align:middle;margin-right:2px;"></span>
							<?php esc_html_e( 'Sync', 'nexora-engine' ); ?>
						</a>
					</div>

				</div><!-- /.ncx-license-active -->

			<?php else : ?>
				<!-- ── Free plan: upgrade CTA ────────────────────────────── -->
				<div class="ncx-license-inactive">

					<div class="ncx-plan-badge-row">
						<span class="ncx-plan-badge ncx-plan-badge--free">
							<?php esc_html_e( 'Free Plan', 'nexora-engine' ); ?>
						</span>
						<?php if ( $dev_override ) : ?>
							<span class="ncx-badge ncx-badge--dev"><?php esc_html_e( 'DEV OVERRIDE', 'nexora-engine' ); ?></span>
						<?php endif; ?>
					</div>

					<p class="ncx-p-muted" style="margin:14px 0;">
						<?php esc_html_e( 'Upgrade to Pro to unlock Ghost Protocol, SEO Intelligence, Core Web Vitals tracking, multisite fleet orchestration, and 30+ advanced features.', 'nexora-engine' ); ?>
					</p>

					<div class="ncx-upgrade-actions">
						<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener"
						   class="ncx-btn ncx-btn-primary">
							<?php esc_html_e( 'Upgrade to Pro →', 'nexora-engine' ); ?>
						</a>
					</div>

					<p class="ncx-small-info" style="margin-top:14px;">
						<?php esc_html_e( '14-day free trial · No credit card required · Cancel anytime', 'nexora-engine' ); ?>
					</p>

					<!-- ── Post-checkout sync hint ──────────────────────────── -->
					<div class="ncx-sync-hint">
						<span class="dashicons dashicons-info-outline" style="font-size:14px;vertical-align:middle;color:var(--ncx-muted);margin-right:4px;"></span>
						<?php esc_html_e( 'Just completed checkout and still seeing Free?', 'nexora-engine' ); ?>
						<a href="<?php echo esc_url( $sync_url ); ?>" class="ncx-sync-link">
							<?php esc_html_e( 'Pull license from Freemius →', 'nexora-engine' ); ?>
						</a>
					</div>
					<p style="font-size:11px;color:var(--ncx-muted);margin:4px 0 0;padding-left:20px;">
						<?php esc_html_e( 'Makes a live API call to Freemius to refresh your license state. Required if the checkout window closed before WordPress was notified.', 'nexora-engine' ); ?>
					</p>

				</div><!-- /.ncx-license-inactive -->
			<?php endif; ?>

		</div><!-- /.ncx-card-body -->
	</div><!-- /.ncx-card (license) -->

	<!-- ── Update Status ────────────────────────────────────────────────── -->
	<div class="ncx-card ncx-glass-card">
		<div class="ncx-card-header">
			<div class="ncx-card-icon"><span class="dashicons dashicons-update"></span></div>
			<h3><?php esc_html_e( 'Plugin Updates', 'nexora-engine' ); ?></h3>
		</div>
		<div class="ncx-card-body">
			<div class="ncx-update-status">
				<div class="ncx-current-ver">
					<span class="label"><?php esc_html_e( 'Installed', 'nexora-engine' ); ?></span>
					<span class="value">v<?php echo esc_html( NEXENG_VERSION ); ?></span>
				</div>
				<div class="ncx-update-ver">
					<span class="label"><?php esc_html_e( 'Channel', 'nexora-engine' ); ?></span>
					<span class="value"><?php echo esc_html( $tier_label ); ?></span>
				</div>
			</div>
			<p class="ncx-p-muted" style="font-size:13px;margin-bottom:16px;">
				<?php esc_html_e( 'Updates are delivered automatically through WordPress. Freemius handles license-locked update delivery for Pro plans.', 'nexora-engine' ); ?>
			</p>
			<div class="ncx-btn-group">
				<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="ncx-btn ncx-btn-block">
					<?php esc_html_e( 'Check for Updates', 'nexora-engine' ); ?>
				</a>
			</div>
		</div>
	</div>

</div><!-- /.ncx-updates-grid -->

<!-- ── Portal Status (read-only — manage via Portal page) ──────────────────── -->
<div style="margin-top:24px; padding:14px 20px; background:var(--ncx-brand-offwhite); border:1px solid var(--ncx-brand-border); border-radius:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
	<div style="display:flex; align-items:center; gap:10px;">
		<?php if ( $portal_connected ) : ?>
			<span class="dashicons dashicons-yes-alt" style="color:#A96A06; font-size:18px; width:18px; height:18px;"></span>
			<span style="font-size:13px; font-weight:600; color:#1e293b;"><?php esc_html_e( 'Auralogics Portal: Connected', 'nexora-engine' ); ?></span>
			<?php if ( $portal_connected_at > 0 ) : ?>
				<span style="font-size:12px; color:#94a3b8;">&mdash; <?php echo esc_html( date_i18n( get_option( 'date_format' ), $portal_connected_at ) ); ?></span>
			<?php endif; ?>
		<?php else : ?>
			<span class="dashicons dashicons-admin-network" style="color:#94a3b8; font-size:18px; width:18px; height:18px;"></span>
			<span style="font-size:13px; font-weight:600; color:#64748b;"><?php esc_html_e( 'Auralogics Portal: Not connected', 'nexora-engine' ); ?></span>
		<?php endif; ?>
	</div>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=ncx-portal' ) ); ?>"
	   style="font-size:12px; font-weight:700; color:var(--ncx-primary); text-decoration:none; white-space:nowrap;">
		<?php echo $portal_connected ? esc_html__( 'Manage Portal →', 'nexora-engine' ) : esc_html__( 'Connect to Portal →', 'nexora-engine' ); ?>
	</a>
</div>

<!-- ── Changelog ───────────────────────────────────────────────────────────── -->
<div class="ncx-card ncx-glass-card" style="margin-top:24px;">
	<div class="ncx-card-header">
		<h3><?php esc_html_e( 'Changelog', 'nexora-engine' ); ?></h3>
	</div>
	<div class="ncx-changelog">

		<div class="ncx-log-entry">
			<div class="ncx-log-header">
				<span class="version">v2.0.0</span>
				<span class="date">May 2026</span>
			</div>
			<div class="ncx-log-title"><?php esc_html_e( 'Nexora Engine — Enterprise Architecture', 'nexora-engine' ); ?></div>
			<ul class="ncx-log-list">
				<li><?php esc_html_e( 'Rebranded to Nexora Engine with PSR-4 autoloading and NexoraEngine\\ namespace.', 'nexora-engine' ); ?></li>
				<li><?php esc_html_e( 'Freemius-powered licensing: free plan, 14-day trial, and Pro tier.', 'nexora-engine' ); ?></li>
				<li><?php esc_html_e( 'Centralized Feature Gate system — no more scattered is_pro() checks.', 'nexora-engine' ); ?></li>
				<li><?php esc_html_e( 'Five-layer license resolution: Freemius → 24h cache → 72h grace period → free fallback.', 'nexora-engine' ); ?></li>
				<li><?php esc_html_e( 'Developer overrides via NEXORA_DEV_MODE and NEXORA_PRO_ENABLED constants.', 'nexora-engine' ); ?></li>
				<li><?php esc_html_e( 'Hardened AJAX security — all admin actions require nonce + manage_options capability.', 'nexora-engine' ); ?></li>
				<li><?php esc_html_e( 'WP.org compliance: explicit opt-in analytics, proper uninstall hook.', 'nexora-engine' ); ?></li>
			</ul>
		</div>

		<div class="ncx-log-entry">
			<div class="ncx-log-header">
				<span class="version">v1.7.0</span>
				<span class="date">May 2026</span>
			</div>
			<div class="ncx-log-title"><?php esc_html_e( 'Static Infrastructure + Ghost Protocol', 'nexora-engine' ); ?></div>
			<ul class="ncx-log-list">
				<li><strong><?php esc_html_e( 'Ghost Protocol', 'nexora-engine' ); ?></strong>: <?php esc_html_e( 'strips all WP fingerprints from headers and HTML.', 'nexora-engine' ); ?></li>
				<li><strong><?php esc_html_e( 'SSG Engine', 'nexora-engine' ); ?></strong>: <?php esc_html_e( '22ms TTFB via pre-rendered static HTML snapshots.', 'nexora-engine' ); ?></li>
				<li><strong><?php esc_html_e( 'Universal drop-in', 'nexora-engine' ); ?></strong>: <?php esc_html_e( 'advanced-cache.php serves files before PHP boots.', 'nexora-engine' ); ?></li>
				<li><strong><?php esc_html_e( 'Analytics', 'nexora-engine' ); ?></strong>: <?php esc_html_e( 'real-time analytics, TTFB beacon, Core Web Vitals tracking.', 'nexora-engine' ); ?></li>
			</ul>
		</div>

	</div>
</div>

<?php ob_start(); ?>
.ncx-updates-grid            { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:30px; }
.ncx-version-badge           { background:var(--ncx-primary-dark); color:#fff; padding:4px 12px; border-radius:20px; font-weight:700; font-size:12px; }
.ncx-license-overview        { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:22px; }
.ncx-license-overview-item   { background:#fff; border:1px solid var(--ncx-brand-border); border-radius:12px; padding:14px 16px; box-shadow:0 8px 24px rgba(15,23,42,.04); }
.ncx-license-overview-item span { display:block; color:var(--ncx-muted); font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; margin-bottom:5px; }
.ncx-license-overview-item strong { display:block; color:var(--ncx-gray-900); font-size:15px; font-weight:700; }

/* Plan badge */
.ncx-plan-badge-row          { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:4px; }
.ncx-plan-badge              { display:inline-block; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
.ncx-plan-badge--free        { background:#f0f0f0; color:#555; }
.ncx-plan-badge--pro         { background:linear-gradient(135deg,#0252FA,#063CE6); color:#fff; }
.ncx-plan-badge--agency      { background:linear-gradient(135deg,#7c3aed,#4f46e5); color:#fff; }

/* Status dot */
.ncx-status-dot              { font-size:12px; font-weight:600; padding:3px 10px; border-radius:20px; }
.ncx-status-dot--active      { background:#d1fae5; color:#065f46; }
.ncx-status-dot--expired     { background:#fee2e2; color:#991b1b; }
.ncx-status-dot--unverified  { background:#fef3c7; color:#92400e; }

/* Dev override badge */
.ncx-badge--dev              { background:#fef3c7; color:#92400e; font-size:10px; font-weight:700; padding:2px 8px; border-radius:12px; text-transform:uppercase; letter-spacing:.05em; }

/* Account holder */
.ncx-license-user            { display:flex; align-items:center; gap:12px; margin-top:16px; padding:12px 14px; background:var(--ncx-brand-offwhite); border-radius:10px; }
.ncx-license-avatar          { flex-shrink:0; width:38px; height:38px; border-radius:50%; background:linear-gradient(135deg,#0252FA,#063CE6); color:#fff; font-size:15px; font-weight:800; display:flex; align-items:center; justify-content:center; }
.ncx-license-user-name       { font-size:14px; font-weight:700; color:#1e293b; line-height:1.3; }
.ncx-license-user-email      { font-size:12px; color:#64748b; margin-top:1px; }

/* License validity block */
.ncx-validity-block          { margin-top:14px; border-radius:10px; padding:12px 14px; display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.ncx-validity--valid         { background:#f0fdf4; border:1px solid #bbf7d0; }
.ncx-validity--warning       { background:#fffbeb; border:1px solid #fde68a; }
.ncx-validity--expired       { background:#fef2f2; border:1px solid #fecaca; }
.ncx-validity--lifetime      { background:#f0f9ff; border:1px solid #bae6fd; }
.ncx-validity-main           { display:flex; align-items:flex-start; gap:10px; }
.ncx-validity-icon           { font-size:18px; width:18px; height:18px; flex-shrink:0; margin-top:2px; }
.ncx-validity--valid   .ncx-validity-icon { color:#16a34a; }
.ncx-validity--warning .ncx-validity-icon { color:#d97706; }
.ncx-validity--expired .ncx-validity-icon { color:#dc2626; }
.ncx-validity--lifetime .ncx-validity-icon { color:#0284c7; }
.ncx-validity-label          { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; margin-bottom:3px; }
.ncx-validity-date           { display:block; font-size:15px; font-weight:800; color:#1e293b; }
.ncx-validity-aside          { display:flex; flex-direction:column; align-items:flex-end; gap:6px; }
.ncx-days-badge              { display:inline-block; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:700; white-space:nowrap; }
.ncx-days-badge--valid       { background:#dcfce7; color:#15803d; }
.ncx-days-badge--warning     { background:#fef3c7; color:#92400e; }
.ncx-days-badge--expired     { background:#fee2e2; color:#991b1b; }
.ncx-days-badge--lifetime    { background:#e0f2fe; color:#0369a1; }
.ncx-days-renew-link         { font-size:11px; font-weight:700; color:#d97706; text-decoration:none; }
.ncx-days-renew-link:hover   { text-decoration:underline; }

/* Sites usage */
.ncx-sites-row               { display:flex; align-items:center; justify-content:space-between; margin-top:12px; padding:8px 14px; background:var(--ncx-brand-offwhite); border-radius:8px; }
.ncx-sites-label             { font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
.ncx-sites-value             { font-size:14px; font-weight:800; color:#1e293b; }
.ncx-sites-quota             { font-size:12px; font-weight:500; color:#94a3b8; margin-left:4px; }

/* Upgrade actions */
.ncx-upgrade-actions         { display:flex; align-items:center; flex-wrap:wrap; gap:8px; margin-top:4px; }

/* Post-checkout sync hint */
.ncx-sync-hint               { margin-top:16px; font-size:12px; color:var(--ncx-muted); display:flex; align-items:center; gap:4px; flex-wrap:wrap; }
.ncx-sync-link               { color:var(--ncx-primary); text-decoration:underline; cursor:pointer; }

/* Update status block */
.ncx-update-status           { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:16px; padding:16px; background:var(--ncx-brand-offwhite); border-radius:12px; }
.ncx-current-ver .label,
.ncx-update-ver .label       { display:block; font-size:11px; text-transform:uppercase; color:var(--ncx-muted); font-weight:700; }
.ncx-current-ver .value,
.ncx-update-ver .value       { font-size:18px; font-weight:800; color:var(--ncx-gray-900); }

/* Changelog */
.ncx-changelog               { margin-top:20px; }
.ncx-log-entry               { padding:20px 0; border-bottom:1px solid rgba(0,0,0,0.05); }
.ncx-log-entry:last-child    { border-bottom:none; }
.ncx-log-header              { display:flex; justify-content:space-between; margin-bottom:8px; }
.ncx-log-header .version     { font-weight:800; color:var(--ncx-gray-900); }
.ncx-log-header .date        { font-size:12px; color:var(--ncx-muted); }
.ncx-log-title               { font-weight:600; color:var(--ncx-gray-700); margin-bottom:12px; }
.ncx-log-list                { padding-left:18px; list-style:disc; color:var(--ncx-muted); font-size:14px; line-height:1.6; }

@media (max-width:900px) {
	.ncx-updates-grid { grid-template-columns:1fr; }
	.ncx-license-overview { grid-template-columns:1fr 1fr; }
}

@media (max-width:600px) {
	.ncx-license-overview { grid-template-columns:1fr; }
}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>
