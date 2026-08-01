<?php
/**
 * Nexora Engine — Setup Wizard
 * Layout v2.3: Sidebar nav + CTA in step header + readable fonts
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$wizard = NEXENG_Wizard::get_instance();

// Wizard already completed — show a clear "setup done" screen instead of silently
// redirecting to the dashboard (which felt like a blank wizard page to many users).
$wizard_completed_only = $wizard->is_completed();

$preflight = $wizard_completed_only ? [] : $wizard->get_preflight_data();
$server    = $wizard->get_server_info();
$conflicts = $wizard_completed_only ? [] : $wizard->get_active_conflicts();
$is_pro    = class_exists( 'NEXENG_Licence' ) && NEXENG_Licence::is_pro();
$is_network = is_multisite();
$upgrade_url = ( ! $is_pro && function_exists( 'ne_fs' ) ) ? ne_fs()->get_upgrade_url() : '#';

$has_blocking = false;
foreach ( $conflicts as $c ) {
	if ( $c['slug'] === 'foreign-dropin' ) { $has_blocking = true; break; }
}

$tier_colors = [ 1 => '#22c55e', 2 => '#0252FA', 3 => '#f59e0b' ];
$tier_icons  = [ 1 => 'dashicons-yes-alt', 2 => 'dashicons-performance', 3 => 'dashicons-admin-tools' ];
$tier_color  = $tier_colors[ $server['tier'] ] ?? '#0252FA';
$tier_icon   = $tier_icons[ $server['tier'] ] ?? 'dashicons-performance';

$ssg         = NEXENG_SSG::get_instance();
$ssg_stats   = $ssg->stats();
$files_count = (int) ( $ssg_stats['total_files'] ?? 0 );
$archive_status   = $ssg->archive_manifest_status();
$archives_captured = (int) ( $archive_status['captured'] ?? 0 );
$archives_eligible   = (int) ( $archive_status['eligible'] ?? 0 );
$conflict_count = count( $conflicts );
// Current delivery settings — shown on the completed screen so the user
// sees exactly what state the engine is in without re-opening other pages.
$ssg_on       = NEXENG_SSG::is_enabled();
$ghost_on     = get_option( 'nexeng_headless_mode', 'off' ) === 'on';
$auto_rebuild = get_option( 'nexeng_auto_rebuild', 'on' ) === 'on';
$http_auth_user = (string) get_option( 'nexeng_http_auth_user', '' );
$http_auth_pass = (string) get_option( 'nexeng_http_auth_pass', '' );

$wizard_just_reset = (bool) get_transient( 'nexeng_wizard_just_reset' );
if ( $wizard_just_reset ) {
	delete_transient( 'nexeng_wizard_just_reset' );
}

$step_data = [
	1 => [ 'label' => 'System Check', 'sub' => 'Verify compatibility'  ],
	2 => [ 'label' => 'Activating',   'sub' => 'Configure engine'      ],
	3 => [ 'label' => 'Conflicts',    'sub' => 'Review plugins'        ],
	4 => [ 'label' => 'Building',     'sub' => 'Generate static files' ],
	5 => [ 'label' => 'Live!',        'sub' => 'Site is ready'         ],
];
?>
<?php if ( $wizard_completed_only ) : ?>
<div class="ncx-wizard-wrap ncx-wizard-complete">

	<div class="ncx-wiz-header">
		<div class="ncx-wiz-brand">
			<div class="ncx-wiz-logo">
				<svg width="28" height="28" viewBox="0 0 32 32" fill="none"><path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" fill="#fff"/><path d="M16 8L22 11.5V20.5L16 24L10 20.5V11.5L16 8Z" fill="#0252FA"/><circle cx="16" cy="16" r="3" fill="#fff"/></svg>
			</div>
			<div>
				<div class="ncx-wiz-brand-name"><?php esc_html_e( 'Nexora Engine', 'nexora-engine' ); ?></div>
				<div class="ncx-wiz-brand-sub"><?php esc_html_e( 'Static delivery setup', 'nexora-engine' ); ?></div>
			</div>
		</div>
		<div class="ncx-wiz-step-counter">
			<?php esc_html_e( 'Setup Wizard', 'nexora-engine' ); ?> &nbsp;·&nbsp; <?php esc_html_e( 'Complete', 'nexora-engine' ); ?>
		</div>
	</div>

	<div class="ncx-wiz-main">
		<aside class="ncx-wiz-sidebar">
			<nav class="ncx-wiz-steps" aria-label="<?php esc_attr_e( 'Wizard steps', 'nexora-engine' ); ?>">
				<?php foreach ( $step_data as $n => $step ) : ?>
				<?php if ( $n > 1 ) : ?><div class="ncx-wiz-step-line is-done"></div><?php endif; ?>
				<div class="ncx-wiz-step-dot completed" data-step="<?php echo (int) $n; ?>">
					<div class="ncx-wiz-step-circle">
						<span class="ncx-wiz-step-num"><?php echo (int) $n; ?></span>
						<span class="ncx-wiz-step-check">✓</span>
					</div>
					<div class="ncx-wiz-step-text">
						<span class="ncx-wiz-step-label"><?php echo esc_html( $step['label'] ); ?></span>
						<span class="ncx-wiz-step-sublabel"><?php echo esc_html( $step['sub'] ); ?></span>
					</div>
				</div>
				<?php endforeach; ?>
			</nav>
		</aside>

		<div class="ncx-wiz-body">
			<div class="ncx-wizard-complete-panel">
				<div class="ncx-wizard-complete-icon" aria-hidden="true">
					<svg width="40" height="40" viewBox="0 0 40 40" fill="none"><circle cx="20" cy="20" r="20" fill="#dcfce7"/><path d="M12 20l6 6 12-12" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</div>
				<h2 class="ncx-wiz-title"><?php esc_html_e( 'Setup is complete', 'nexora-engine' ); ?></h2>
				<p class="ncx-wiz-subtitle"><?php esc_html_e( 'Your static mirror is live. Use Build Control on the Static Delivery page to rebuild, monitor, and tune delivery day-to-day.', 'nexora-engine' ); ?></p>

				<!-- Current configuration state -->
				<div class="ncx-wiz-cfg-strip">
					<div class="ncx-wiz-cfg-pill ncx-wiz-cfg-<?php echo esc_attr( $ssg_on ? 'on' : 'off' ); ?>">
						<span class="ncx-wiz-cfg-dot"></span>
						<?php echo $ssg_on ? esc_html__( 'Static delivery active', 'nexora-engine' ) : esc_html__( 'Static delivery off', 'nexora-engine' ); ?>
					</div>
					<?php if ( $is_pro ) : ?>
					<div class="ncx-wiz-cfg-pill ncx-wiz-cfg-<?php echo esc_attr( $ghost_on ? 'on' : 'off' ); ?>">
						<span class="ncx-wiz-cfg-dot"></span>
						<?php echo $ghost_on ? esc_html__( 'WP Masking active', 'nexora-engine' ) : esc_html__( 'WP Masking off', 'nexora-engine' ); ?>
					</div>
					<div class="ncx-wiz-cfg-pill ncx-wiz-cfg-<?php echo esc_attr( $auto_rebuild ? 'on' : 'off' ); ?>">
						<span class="ncx-wiz-cfg-dot"></span>
						<?php echo $auto_rebuild ? esc_html__( 'Auto-rebuild active', 'nexora-engine' ) : esc_html__( 'Auto-rebuild off', 'nexora-engine' ); ?>
					</div>
					<?php else : ?>
					<div class="ncx-wiz-cfg-pill ncx-wiz-cfg-locked">
						<span class="dashicons dashicons-lock" style="font-size:11px;width:11px;height:11px;vertical-align:middle;margin-right:2px;"></span>
						<?php esc_html_e( 'WP Masking — Pro', 'nexora-engine' ); ?>
					</div>
					<?php endif; ?>
				</div>

				<div class="ncx-wizard-complete-stats">
					<div class="ncx-wizard-complete-stat">
						<div class="ncx-wcs-icon" aria-hidden="true">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" fill="#0252FA" opacity=".15"/><path d="M14 2v6h6M8 13h8M8 17h5" stroke="#0252FA" stroke-width="1.5" stroke-linecap="round"/></svg>
						</div>
						<strong><?php echo esc_html( number_format_i18n( $files_count ) ); ?></strong>
						<span><?php esc_html_e( 'Static files on disk', 'nexora-engine' ); ?></span>
					</div>
					<div class="ncx-wizard-complete-stat">
						<div class="ncx-wcs-icon" aria-hidden="true">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h10" stroke="#0252FA" stroke-width="1.5" stroke-linecap="round"/></svg>
						</div>
						<strong><?php echo esc_html( number_format_i18n( $archives_captured ) ); ?><?php if ( $archives_eligible > 0 ) : ?><small>/<?php echo esc_html( number_format_i18n( $archives_eligible ) ); ?></small><?php endif; ?></strong>
						<span><?php esc_html_e( 'Archive pages captured', 'nexora-engine' ); ?></span>
					</div>
					<div class="ncx-wizard-complete-stat">
						<div class="ncx-wcs-icon" aria-hidden="true">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M13 10V3L4 14h7v7l9-11h-7z" fill="#22c55e" opacity=".2"/><path d="M13 10V3L4 14h7v7l9-11h-7z" stroke="#16a34a" stroke-width="1.5" stroke-linejoin="round"/></svg>
						</div>
						<strong><?php echo esc_html( $server['tier_label'] ?? __( 'Active', 'nexora-engine' ) ); ?></strong>
						<span><?php esc_html_e( 'Delivery mode', 'nexora-engine' ); ?></span>
					</div>
				</div>

				<div class="ncx-wizard-complete-actions">
					<a class="ncx-wiz-btn ncx-wiz-btn--primary ncx-wiz-btn--lg" href="<?php echo esc_url( admin_url( 'admin.php?page=ncx-headless' ) ); ?>">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M13 10V3L4 14h7v7l9-11h-7z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
						<?php esc_html_e( 'Open Build Control', 'nexora-engine' ); ?>
					</a>
					<a class="ncx-wiz-btn ncx-wiz-btn--outline ncx-wiz-btn--lg" href="<?php echo esc_url( admin_url( 'admin.php?page=nexora' ) ); ?>"><?php esc_html_e( 'Open Dashboard', 'nexora-engine' ); ?></a>
				</div>
				<p class="ncx-wizard-complete-hint">
					<?php esc_html_e( 'Need a full rebuild? Use Mirror Build Control → Rebuild Full Mirror.', 'nexora-engine' ); ?>
					&nbsp;<a href="<?php echo esc_url( NEXENG_Wizard::get_admin_url( true ) ); ?>" class="ncx-wiz-rerun-link"><?php esc_html_e( 'Re-run setup wizard', 'nexora-engine' ); ?></a>
				</p>
			</div>
		</div>
	</div>
</div>
<?php else : ?>
<div class="ncx-wizard-wrap">
<?php if ( $wizard_just_reset ) : ?>
	<div class="ncx-wiz-inline-notice" role="status"><?php esc_html_e( 'Setup wizard reset — you can run through all steps again.', 'nexora-engine' ); ?></div>
<?php endif; ?>
<noscript><div class="ncx-wiz-inline-notice ncx-wiz-inline-notice--warn"><?php esc_html_e( 'JavaScript is required for the setup wizard. Step 1 remains visible below — enable JavaScript for the full guided flow.', 'nexora-engine' ); ?></div></noscript>

	<!-- ══ Header ══════════════════════════════════════════════════════════════ -->
	<div class="ncx-wiz-header">
		<div class="ncx-wiz-brand">
			<div class="ncx-wiz-logo">
				<svg width="28" height="28" viewBox="0 0 32 32" fill="none"><path d="M16 2L28 9V23L16 30L4 23V9L16 2Z" fill="#fff"/><path d="M16 8L22 11.5V20.5L16 24L10 20.5V11.5L16 8Z" fill="#0252FA"/><circle cx="16" cy="16" r="3" fill="#fff"/></svg>
			</div>
			<div>
				<div class="ncx-wiz-brand-name">Nexora Engine</div>
				<div class="ncx-wiz-brand-sub">Static delivery setup</div>
			</div>
		</div>
		<div class="ncx-wiz-step-counter">
			<?php esc_html_e( 'Setup Wizard', 'nexora-engine' ); ?> &nbsp;·&nbsp; <?php esc_html_e( 'Step', 'nexora-engine' ); ?> <span id="ncx-header-step-num">1</span> <?php esc_html_e( 'of 5', 'nexora-engine' ); ?>
		</div>
	</div>

	<!-- ══ Main: Sidebar + Content ══════════════════════════════════════════════ -->
	<div class="ncx-wiz-main">

		<!-- ─── Sidebar: pure step navigator ─────────────────────────────────── -->
		<aside class="ncx-wiz-sidebar">
			<nav class="ncx-wiz-steps" aria-label="<?php esc_attr_e( 'Wizard steps', 'nexora-engine' ); ?>">
				<?php foreach ( $step_data as $n => $step ) : ?>
				<?php if ( $n > 1 ) : ?><div class="ncx-wiz-step-line"></div><?php endif; ?>
				<div class="ncx-wiz-step-dot <?php echo esc_attr( $n === 1 ? 'active' : '' ); ?>" data-step="<?php echo (int) $n; ?>">
					<div class="ncx-wiz-step-circle">
						<span class="ncx-wiz-step-num"><?php echo (int) $n; ?></span>
						<span class="ncx-wiz-step-check">✓</span>
					</div>
					<div class="ncx-wiz-step-text">
						<span class="ncx-wiz-step-label"><?php echo esc_html( $step['label'] ); ?></span>
						<span class="ncx-wiz-step-sublabel"><?php echo esc_html( $step['sub'] ); ?></span>
					</div>
				</div>
				<?php endforeach; ?>
			</nav>
		</aside>

		<!-- ─── Content Panel ─────────────────────────────────────────────────── -->
		<div class="ncx-wiz-body">

			<!-- ══ Step 1: System Check ═══════════════════════════════════════════ -->
			<div class="ncx-wizard-step active" id="step-1">
				<div class="ncx-wiz-step-inner">

					<!-- Step header: icon | title+subtitle | CTA button -->
					<div class="ncx-wiz-step-head">
						<div class="ncx-wiz-hero-icon">
							<svg width="48" height="48" viewBox="0 0 52 52" fill="none"><circle cx="26" cy="26" r="26" fill="#EBF1FF"/><path d="M26 12L37 18V30L26 36L15 30V18L26 12Z" fill="#0252FA"/><path d="M26 18L31 20.75V26.25L26 29L21 26.25V20.75L26 18Z" fill="white"/><circle cx="26" cy="24" r="3" fill="#0252FA"/></svg>
						</div>
						<div class="ncx-wiz-step-title-block">
							<h2 class="ncx-wiz-title"><?php esc_html_e( 'Launch the static delivery pipeline.', 'nexora-engine' ); ?></h2>
							<p class="ncx-wiz-subtitle"><?php esc_html_e( 'Nexora checks the environment, prepares the mirror path, and serves visitors from static files while WordPress stays available for editing.', 'nexora-engine' ); ?></p>
						</div>
					</div>

					<div class="ncx-wiz-two-col">
						<!-- Features -->
						<div class="ncx-wiz-features">
							<h3 class="ncx-wiz-section-label"><?php esc_html_e( 'What you\'re activating', 'nexora-engine' ); ?></h3>
							<div class="ncx-wiz-feature-list">
								<div class="ncx-wiz-feature">
									<div class="ncx-wiz-feature-icon" style="background:#EBF1FF;color:#0252FA">⚡</div>
									<div>
										<strong><?php esc_html_e( 'Static Site Generation', 'nexora-engine' ); ?></strong>
										<p><?php esc_html_e( 'Every page pre-rendered as plain HTML. No PHP, no database per visitor request.', 'nexora-engine' ); ?></p>
									</div>
								</div>
								<div class="ncx-wiz-feature">
									<div class="ncx-wiz-feature-icon" style="background:#EDFDF4;color:#22c55e">🛡️</div>
									<div>
										<strong><?php esc_html_e( 'Automatic Serve Rules', 'nexora-engine' ); ?></strong>
										<p><?php esc_html_e( 'Apache or drop-in cache delivers files at web-server speed — 10–50× faster TTFB.', 'nexora-engine' ); ?></p>
									</div>
								</div>
								<div class="ncx-wiz-feature">
									<div class="ncx-wiz-feature-icon" style="background:#FFF7ED;color:#f59e0b">🔄</div>
									<div>
										<strong><?php esc_html_e( 'Smart Invalidation', 'nexora-engine' ); ?></strong>
										<p><?php esc_html_e( 'Static files update automatically whenever you publish or update a post.', 'nexora-engine' ); ?></p>
									</div>
								</div>
								<?php if ( $is_pro ) : ?>
								<div class="ncx-wiz-feature">
									<div class="ncx-wiz-feature-icon" style="background:#F3F0FF;color:#7c3aed">👻</div>
									<div>
										<strong><?php esc_html_e( 'WP Masking', 'nexora-engine' ); ?> <span class="ncx-badge-pro">PRO</span></strong>
										<p><?php esc_html_e( 'All WordPress headers and fingerprints stripped from every response.', 'nexora-engine' ); ?></p>
									</div>
								</div>
								<?php endif; ?>
							</div>
						</div>

						<!-- System checks -->
						<div class="ncx-wiz-checks">
							<h3 class="ncx-wiz-section-label"><?php esc_html_e( 'System verification', 'nexora-engine' ); ?></h3>
							<div class="ncx-check-list">
								<?php foreach ( $preflight['checks'] as $check ) : ?>
								<div class="ncx-check-item <?php echo esc_attr( $check['pass'] ? 'pass' : 'fail' ); ?>">
									<div class="ncx-check-icon">
										<?php if ( $check['pass'] ) : ?>
										<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="9" r="9" fill="#22c55e"/><path d="M5.5 9L7.5 11L12.5 6" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
										<?php else : ?>
										<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="9" r="9" fill="#f59e0b"/><path d="M9 5.5V9.5M9 11.5V12" stroke="white" stroke-width="1.6" stroke-linecap="round"/></svg>
										<?php endif; ?>
									</div>
									<div class="ncx-check-body">
										<span class="ncx-check-label"><?php echo esc_html( $check['label'] ); ?></span>
										<span class="ncx-check-value"><?php echo esc_html( $check['current'] ); ?></span>
									</div>
								</div>
								<?php endforeach; ?>
								<div class="ncx-check-item pass">
									<div class="ncx-check-icon">
										<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="9" r="9" fill="#0252FA"/><path d="M5 9h8M9 5v8" stroke="white" stroke-width="1.6" stroke-linecap="round"/></svg>
									</div>
									<div class="ncx-check-body">
										<span class="ncx-check-label"><?php esc_html_e( 'Web Server', 'nexora-engine' ); ?></span>
										<span class="ncx-check-value"><?php echo esc_html( $server['server'] ); ?></span>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Tier prediction -->
					<div class="ncx-wiz-tier-card" id="ncx-tier-prediction" style="border-color:<?php echo esc_attr( $tier_color ); ?>40;background:<?php echo esc_attr( $tier_color ); ?>07;">
						<div class="ncx-tier-icon-wrap" style="background:<?php echo esc_attr( $tier_color ); ?>15;">
							<span class="dashicons <?php echo esc_attr( $tier_icon ); ?>" style="color:<?php echo esc_attr( $tier_color ); ?>"></span>
						</div>
						<div class="ncx-tier-body">
							<div class="ncx-tier-badge" style="background:<?php echo esc_attr( $tier_color ); ?>18;color:<?php echo esc_attr( $tier_color ); ?>">
								<?php echo esc_html( $server['tier_label'] ); ?>
							</div>
							<p class="ncx-tier-desc"><?php echo esc_html( $server['tier_desc'] ); ?></p>
						</div>
						<div class="ncx-tier-ttfb-block">
							<div class="ncx-tier-ttfb-big" style="color:<?php echo esc_attr( $tier_color ); ?>"><?php echo esc_html( $server['tier_ttfb'] ); ?></div>
							<div class="ncx-tier-ttfb-sub"><?php esc_html_e( 'Est. TTFB', 'nexora-engine' ); ?></div>
						</div>
						<div class="ncx-tier-action">
							<button class="ncx-wiz-header-activate ncx-wiz-tier-activate" id="btn-launch-nexora">
								<svg width="15" height="15" viewBox="0 0 18 18" fill="none"><path d="M9 2l7 4v8l-7 4L2 14V6l7-4z" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linejoin="round"/><circle cx="9" cy="9" r="2" fill="currentColor"/></svg>
								<span><?php esc_html_e( 'Activate Nexora Engine', 'nexora-engine' ); ?></span>
							</button>
						</div>
					</div>

					<?php if ( $preflight['status'] !== 'pass' ) : ?>
					<div class="ncx-wiz-warn">
						<span class="ncx-wiz-warn-icon">⚠️</span>
						<p><?php esc_html_e( 'Some checks didn\'t pass, but you can still continue. Non-critical warnings won\'t block performance.', 'nexora-engine' ); ?></p>
					</div>
					<?php endif; ?>

				</div>
			</div><!-- /#step-1 -->

			<!-- ══ Step 2: Activating (auto-progress) ════════════════════════════ -->
			<div class="ncx-wizard-step" id="step-2">
				<div class="ncx-wiz-step-inner ncx-wiz-step-inner--center">
					<div class="ncx-wiz-step-head ncx-wiz-step-head--center">
						<div class="ncx-wiz-hero-icon">
							<svg width="48" height="48" viewBox="0 0 52 52" fill="none"><circle cx="26" cy="26" r="26" fill="#EBF1FF"/><path d="M16 26l7 7 13-13" stroke="#0252FA" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</div>
						<h2 class="ncx-wiz-title"><?php esc_html_e( 'Nexora Engine Activated', 'nexora-engine' ); ?></h2>
						<p class="ncx-wiz-subtitle"><?php esc_html_e( 'Your site infrastructure has been configured. Here\'s what was set up:', 'nexora-engine' ); ?></p>
					</div>

					<div class="ncx-act-checklist">
						<div class="ncx-act-item" id="ncx-act-ssg">
							<div class="ncx-act-check">✓</div>
							<div class="ncx-act-body">
								<span class="ncx-act-label"><?php esc_html_e( 'Static Site Generation', 'nexora-engine' ); ?></span>
								<span class="ncx-act-desc"><?php esc_html_e( 'Pages captured and served as static HTML', 'nexora-engine' ); ?></span>
							</div>
							<div class="ncx-act-status ncx-act-status--on"><?php esc_html_e( 'Enabled', 'nexora-engine' ); ?></div>
						</div>
						<div class="ncx-act-item" id="ncx-act-serve-row">
							<div class="ncx-act-check">✓</div>
							<div class="ncx-act-body">
								<span class="ncx-act-label"><?php echo $is_network ? esc_html__( 'Network Delivery Layer', 'nexora-engine' ) : esc_html__( 'Web Server Serve Rules', 'nexora-engine' ); ?></span>
								<span class="ncx-act-desc"><?php echo $is_network ? esc_html__( 'Network drop-in resolves each site — shared .htaccess rules are not used on multisite', 'nexora-engine' ) : esc_html__( 'Apache .htaccess or drop-in cache configured', 'nexora-engine' ); ?></span>
							</div>
							<div class="ncx-act-status" id="ncx-act-serve"><?php esc_html_e( 'Installed', 'nexora-engine' ); ?></div>
						</div>
						<div class="ncx-act-item" id="ncx-act-dropin-row">
							<div class="ncx-act-check">✓</div>
							<div class="ncx-act-body">
								<span class="ncx-act-label"><?php esc_html_e( 'Drop-in Cache', 'nexora-engine' ); ?></span>
								<span class="ncx-act-desc"><?php esc_html_e( 'PHP-level intercept before WordPress boots', 'nexora-engine' ); ?></span>
							</div>
							<div class="ncx-act-status" id="ncx-act-dropin"><?php esc_html_e( 'Installed', 'nexora-engine' ); ?></div>
						</div>
						<div class="ncx-act-item">
							<div class="ncx-act-check">✓</div>
							<div class="ncx-act-body">
								<span class="ncx-act-label"><?php esc_html_e( 'Asset Delivery Mode', 'nexora-engine' ); ?></span>
								<span class="ncx-act-desc"><?php esc_html_e( 'Maximum speed with direct file paths', 'nexora-engine' ); ?></span>
							</div>
							<div class="ncx-act-status ncx-act-status--on"><?php esc_html_e( 'Direct', 'nexora-engine' ); ?></div>
						</div>
						<?php if ( $is_pro ) : ?>
						<div class="ncx-act-item">
							<div class="ncx-act-check">✓</div>
							<div class="ncx-act-body">
								<span class="ncx-act-label"><?php esc_html_e( 'WP Masking', 'nexora-engine' ); ?></span>
								<span class="ncx-act-desc"><?php esc_html_e( 'WordPress fingerprints stripped from all responses', 'nexora-engine' ); ?></span>
							</div>
							<div class="ncx-act-status ncx-act-status--pro"><?php esc_html_e( 'Pro Active', 'nexora-engine' ); ?></div>
						</div>
						<?php endif; ?>
					</div>

					<div class="ncx-wiz-tier-card ncx-wiz-tier-card--activated" id="ncx-activation-tier">
						<div class="ncx-tier-icon-wrap ncx-tier-icon-wrap--lg" id="ncx-act-tier-icon-wrap">
							<em class="tier-icon" aria-hidden="true">⚡</em>
						</div>
						<strong class="ncx-act-tier-title"><?php esc_html_e( 'Detecting tier…', 'nexora-engine' ); ?></strong>
						<p class="ncx-act-tier-desc"><?php esc_html_e( 'Verifying serve mode', 'nexora-engine' ); ?></p>
					</div>

					<div class="ncx-wiz-auto-advance">
						<div class="ncx-wiz-spinner"></div>
						<span><?php esc_html_e( 'Scanning for conflicts…', 'nexora-engine' ); ?></span>
					</div>
				</div>
			</div><!-- /#step-2 -->

			<!-- ══ Step 3: Conflicts ══════════════════════════════════════════════ -->
			<div class="ncx-wizard-step" id="step-3">
				<div class="ncx-wiz-step-inner">

					<!-- Step header: icon | title+subtitle | CTA button -->
					<div class="ncx-wiz-step-head">
						<div class="ncx-wiz-hero-icon">
							<?php if ( empty( $conflicts ) ) : ?>
							<svg width="48" height="48" viewBox="0 0 52 52" fill="none"><circle cx="26" cy="26" r="26" fill="#EDFDF4"/><path d="M16 26l7 7 13-13" stroke="#22c55e" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
							<?php else : ?>
							<svg width="48" height="48" viewBox="0 0 52 52" fill="none"><circle cx="26" cy="26" r="26" fill="#FFF7ED"/><path d="M26 17v10M26 31v1.5" stroke="#f59e0b" stroke-width="2.8" stroke-linecap="round"/></svg>
							<?php endif; ?>
						</div>
						<div class="ncx-wiz-step-title-block">
							<h2 class="ncx-wiz-title"><?php echo esc_html( empty( $conflicts ) ? __( 'Perfect Environment', 'nexora-engine' ) : __( 'Conflicts Detected', 'nexora-engine' ) ); ?></h2>
							<p class="ncx-wiz-subtitle"><?php echo esc_html( empty( $conflicts ) ? __( 'No conflicting plugins found. Your environment is clean and ready.', 'nexora-engine' ) : __( "Some plugins may interfere with Nexora's static delivery. Review and resolve below.", 'nexora-engine' ) ); ?></p>
						</div>
						<div class="ncx-wiz-step-head-action">
							<?php if ( $has_blocking ) : ?>
							<button class="ncx-wiz-btn ncx-wiz-btn--outline ncx-wiz-btn--fill" id="btn-refresh-conflicts">
								<svg width="13" height="13" viewBox="0 0 15 15" fill="none"><path d="M13 7.5A5.5 5.5 0 112.5 4M2 2v2.5H4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
								<?php esc_html_e( 'Re-scan', 'nexora-engine' ); ?>
							</button>
							<p class="ncx-head-action-note ncx-note--warn"><?php esc_html_e( 'Resolve blocking issue first', 'nexora-engine' ); ?></p>
							<?php else : ?>
							<button class="ncx-wiz-btn ncx-wiz-btn--primary ncx-wiz-btn--lg ncx-wiz-btn--fill ncx-next-step-btn" data-next="4" id="btn-to-build">
								<span><?php echo esc_html( empty( $conflicts ) ? __( 'Start Building', 'nexora-engine' ) : __( 'Continue to Build', 'nexora-engine' ) ); ?></span>
								<svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M8 3L13 8L8 13M13 8H3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<p class="ncx-head-action-note <?php echo esc_attr( ! empty( $conflicts ) ? 'ncx-note--warn' : '' ); ?>">
								<?php
								if ( empty( $conflicts ) ) {
									esc_html_e( 'Environment clean · Ready', 'nexora-engine' );
								} elseif ( $has_blocking ) {
									esc_html_e( 'Blocking conflict — resolve below', 'nexora-engine' );
								} else {
									echo esc_html( sprintf(
										/* translators: %d: number of compatibility notes */
										_n( '%d compatibility note — review below', '%d compatibility notes — review below', $conflict_count, 'nexora-engine' ),
										$conflict_count
									) );
								}
								?>
							</p>
							<?php endif; ?>
						</div>
					</div>

					<div id="conflict-container" data-conflict-count="<?php echo (int) $conflict_count; ?>" data-has-blocking="<?php echo esc_attr( $has_blocking ? '1' : '0' ); ?>">
						<?php if ( empty( $conflicts ) ) : ?>
						<div class="ncx-success-state">
							<div class="ncx-success-glyph">
								<svg width="72" height="72" viewBox="0 0 80 80" fill="none"><path d="M40 4L72 22V58L40 76L8 58V22L40 4Z" fill="#EBF1FF"/><path d="M40 14L62 26.5V51.5L40 64L18 51.5V26.5L40 14Z" fill="#0252FA" opacity=".15"/><path d="M40 22L56 31V49L40 58L24 49V31L40 22Z" fill="#0252FA"/><path d="M28 40l8 8 16-16" stroke="#fff" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</div>
							<div class="ncx-success-eyebrow"><?php esc_html_e( 'Environment Verified', 'nexora-engine' ); ?></div>
							<h3><?php esc_html_e( 'Zero conflicts detected', 'nexora-engine' ); ?></h3>
							<p><?php esc_html_e( 'Your server is clean and ready. Nexora Engine is about to generate your static site.', 'nexora-engine' ); ?></p>
						</div>
						<?php else : ?>
						<div class="ncx-conflict-list">
							<?php foreach ( $conflicts as $conflict ) :
								$can_fix = $wizard->conflict_can_auto_fix( $conflict );
							?>
							<div class="ncx-conflict-card severity-<?php echo esc_attr( $conflict['severity'] ); ?>" data-conflict-slug="<?php echo esc_attr( $conflict['slug'] ); ?>">
								<div class="ncx-conflict-head">
									<span class="ncx-conflict-sev sev-<?php echo esc_attr( $conflict['severity'] ); ?>"><?php echo esc_html( strtoupper( $conflict['severity'] ) ); ?></span>
									<strong><?php echo esc_html( $conflict['name'] ); ?></strong>
								</div>
								<p class="ncx-conflict-reason"><?php echo esc_html( $conflict['reason'] ); ?></p>
								<code class="ncx-conflict-fix"><?php echo esc_html( $conflict['fix'] ); ?></code>
								<?php if ( $can_fix ) : ?>
								<button class="ncx-wiz-btn ncx-wiz-btn--outline ncx-fix-conflict" data-slug="<?php echo esc_attr( $conflict['slug'] ); ?>">
									<svg width="13" height="13" viewBox="0 0 14 14" fill="none"><path d="M2 7h10M7 2l5 5-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
									<?php esc_html_e( 'Auto-Fix', 'nexora-engine' ); ?>
								</button>
								<?php endif; ?>
							</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
					</div>

				</div>
			</div><!-- /#step-3 -->

			<!-- ══ Step 4: Building (auto-progress) ══════════════════════════════ -->
			<div class="ncx-wizard-step" id="step-4">
				<div class="ncx-wiz-step-inner ncx-wiz-step-inner--center">
					<div class="ncx-wiz-step-head ncx-wiz-step-head--center">
						<div class="ncx-wiz-hero-icon">
							<svg width="48" height="48" viewBox="0 0 52 52" fill="none"><circle cx="26" cy="26" r="26" fill="#EBF1FF"/><rect x="14" y="24" width="24" height="4" rx="2" fill="#0252FA"/><rect x="14" y="30" width="17" height="4" rx="2" fill="#0252FA" opacity="0.5"/><rect x="14" y="18" width="10" height="4" rx="2" fill="#0252FA" opacity="0.3"/></svg>
						</div>
						<h2 class="ncx-wiz-title"><?php esc_html_e( 'Building Your Static Site', 'nexora-engine' ); ?></h2>
						<p class="ncx-wiz-subtitle"><?php esc_html_e( 'Converting your published pages, posts, and category/tag archives into ultra-fast static HTML.', 'nexora-engine' ); ?></p>
					</div>

					<details class="ncx-wiz-staging-auth" id="ncx-wiz-staging-auth">
						<summary>
							<span class="dashicons dashicons-lock" aria-hidden="true"></span>
							<?php esc_html_e( 'Staging HTTP password (optional)', 'nexora-engine' ); ?>
						</summary>
						<div class="ncx-wiz-staging-auth-body">
							<p><?php esc_html_e( 'If your site shows a browser login popup before pages load (common on WPMU DEV, Kinsta, Cloudways staging), enter those credentials here so Nexora can capture pages during setup.', 'nexora-engine' ); ?></p>
							<div class="ncx-wiz-staging-auth-fields">
								<input type="text" class="ncx-wiz-auth-user-input" id="ncx-wiz-auth-user" value="<?php echo esc_attr( $http_auth_user ); ?>" placeholder="<?php esc_attr_e( 'Staging username', 'nexora-engine' ); ?>" autocomplete="off">
								<input type="password" class="ncx-wiz-auth-pass-input" id="ncx-wiz-auth-pass" value="<?php echo esc_attr( $http_auth_pass ); ?>" placeholder="<?php esc_attr_e( 'Staging password', 'nexora-engine' ); ?>" autocomplete="new-password">
								<button type="button" class="ncx-wiz-btn ncx-wiz-btn--outline" id="ncx-wiz-save-auth"><?php esc_html_e( 'Save credentials', 'nexora-engine' ); ?></button>
							</div>
						</div>
					</details>

					<div class="ncx-build-panel">
						<div class="ncx-build-ring-wrap">
							<div class="ncx-build-ring">
								<svg width="150" height="150" viewBox="0 0 160 160">
									<circle cx="80" cy="80" r="68" stroke="#EEF2FF" stroke-width="10" fill="none"/>
									<circle cx="80" cy="80" r="68" stroke="url(#ncxBuildGrad)" stroke-width="10" fill="none"
										stroke-linecap="round" stroke-dasharray="427.256" stroke-dashoffset="427.256"
										class="progress-circle" style="transform:rotate(-90deg);transform-origin:80px 80px;transition:stroke-dashoffset 0.6s ease"/>
									<defs>
										<linearGradient id="ncxBuildGrad" x1="0" y1="0" x2="1" y2="1">
											<stop offset="0%" stop-color="#0252FA"/>
											<stop offset="100%" stop-color="#56A2FA"/>
										</linearGradient>
									</defs>
								</svg>
								<div class="ncx-build-ring-center">
									<div class="progress-percentage">0%</div>
									<div class="progress-label"><?php esc_html_e( 'Complete', 'nexora-engine' ); ?></div>
								</div>
							</div>
						</div>
						<div class="ncx-build-stats">
							<div class="ncx-build-stat">
								<span class="ncx-build-stat-label"><?php esc_html_e( 'Pages Processed', 'nexora-engine' ); ?></span>
								<span class="ncx-build-stat-value"><span class="current-count">0</span> <span class="ncx-build-divider">/</span> <span class="total-count">—</span></span>
							</div>
							<div class="ncx-build-progress-bar"><div class="progress-fill" style="width:0%"></div></div>
							<div class="ncx-build-stat">
								<span class="ncx-build-stat-label"><?php esc_html_e( 'Current Page', 'nexora-engine' ); ?></span>
								<span class="ncx-build-stat-value ncx-build-current-url current-page"><?php esc_html_e( 'Initializing…', 'nexora-engine' ); ?></span>
							</div>
							<div class="ncx-build-info-pill">
								<svg width="15" height="15" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="6" stroke="#0252FA" stroke-width="1.2"/><path d="M7 6v4M7 4.5v.5" stroke="#0252FA" stroke-width="1.2" stroke-linecap="round"/></svg>
								<?php esc_html_e( 'Pages process one at a time via background cron — zero server strain.', 'nexora-engine' ); ?>
							</div>
							<div class="ncx-build-breakdown" id="ncx-build-breakdown" hidden>
								<div class="ncx-build-breakdown-chip">
									<span class="ncx-bd-label"><?php esc_html_e( 'Content pages', 'nexora-engine' ); ?></span>
									<strong class="ncx-bd-posts">—</strong>
								</div>
								<div class="ncx-build-breakdown-chip">
									<span class="ncx-bd-label"><?php esc_html_e( 'Archive pages', 'nexora-engine' ); ?></span>
									<strong class="ncx-bd-archives">—</strong>
								</div>
							</div>
							<p class="ncx-build-queue-note" id="ncx-build-queue-note" hidden></p>
							<div class="ncx-build-recovery" id="ncx-build-recovery" hidden>
								<strong><?php esc_html_e( 'Build appears stuck', 'nexora-engine' ); ?></strong>
								<p><?php esc_html_e( 'Progress has not changed for a while. You can stop the queue safely — pages already captured stay live.', 'nexora-engine' ); ?></p>
								<div class="ncx-build-recovery-actions">
									<button type="button" class="ncx-wiz-btn ncx-wiz-btn--outline" id="ncx-wizard-stop-build"><?php esc_html_e( 'Stop Build', 'nexora-engine' ); ?></button>
									<button type="button" class="ncx-wiz-btn ncx-wiz-btn--ghost" id="ncx-wizard-continue-anyway"><?php esc_html_e( 'Continue Setup Anyway', 'nexora-engine' ); ?></button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div><!-- /#step-4 -->

			<!-- ══ Step 5: Live! ══════════════════════════════════════════════════ -->
			<div class="ncx-wizard-step" id="step-5">
				<div class="ncx-wiz-step-inner ncx-wiz-step-inner--center">

					<div class="ncx-wiz-launch-hero">
						<div class="ncx-wiz-launch-icon">
							<div class="ncx-wiz-launch-ring" id="ncx-launch-icon-wrap">
								<svg width="72" height="72" viewBox="0 0 80 80" fill="none"><circle cx="40" cy="40" r="40" fill="#EDFDF4"/><path d="M24 40l11 11 21-21" stroke="#22c55e" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</div>
						</div>
						<h2 class="ncx-wiz-title ncx-wiz-title--live" id="ncx-launch-title"><?php esc_html_e( "You're Live!", 'nexora-engine' ); ?></h2>
						<p class="ncx-wiz-subtitle" id="ncx-launch-subtitle"><?php esc_html_e( 'Your WordPress site is now a high-performance static platform managed by Nexora Engine.', 'nexora-engine' ); ?></p>
					</div>

					<div class="ncx-wiz-http-auth-notice" id="ncx-wiz-http-auth-notice" hidden>
						<span class="dashicons dashicons-lock" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e( 'HTTP authentication blocked page capture', 'nexora-engine' ); ?></strong>
							<p><?php esc_html_e( 'Your staging site requires a username and password before pages load. Save the same credentials you use in the browser popup, then retry failed pages from Build Control.', 'nexora-engine' ); ?></p>
							<div class="ncx-wiz-staging-auth-fields ncx-wiz-staging-auth-fields--inline">
								<input type="text" class="ncx-wiz-auth-user-input" id="ncx-wiz-auth-user-step5" value="<?php echo esc_attr( $http_auth_user ); ?>" placeholder="<?php esc_attr_e( 'Staging username', 'nexora-engine' ); ?>" autocomplete="off">
								<input type="password" class="ncx-wiz-auth-pass-input" id="ncx-wiz-auth-pass-step5" value="<?php echo esc_attr( $http_auth_pass ); ?>" placeholder="<?php esc_attr_e( 'Staging password', 'nexora-engine' ); ?>" autocomplete="new-password">
								<button type="button" class="ncx-wiz-btn ncx-wiz-btn--outline ncx-wiz-save-auth-btn"><?php esc_html_e( 'Save & retry later', 'nexora-engine' ); ?></button>
							</div>
							<p class="ncx-wiz-http-auth-footnote"><?php esc_html_e( 'After saving, use Retry Failed Pages in Mirror Build Control (top of any Nexora page).', 'nexora-engine' ); ?></p>
						</div>
					</div>

					<!-- CTA buttons — top of content, immediately visible -->
					<div class="ncx-wiz-step5-actions">
						<button class="ncx-wiz-btn ncx-wiz-btn--primary ncx-wiz-btn--xl" id="btn-finish-wizard">
							<span><?php esc_html_e( 'Go to Dashboard', 'nexora-engine' ); ?></span>
							<svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M8 3L13 8L8 13M13 8H3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</button>
						<a href="<?php echo esc_url( home_url() ); ?>" target="_blank" rel="noopener" class="ncx-wiz-btn ncx-wiz-btn--ghost ncx-wiz-btn--xl">
							<?php esc_html_e( 'View My Site ↗', 'nexora-engine' ); ?>
						</a>
					</div>

					<!-- Metrics -->
					<div class="ncx-launch-metrics">
						<div class="ncx-launch-metric">
							<div class="ncx-launch-metric-icon">⚡</div>
							<div class="ncx-launch-metric-val" id="ncx-metric-files"><?php echo esc_html( $files_count > 0 ? number_format( $files_count ) : '…' ); ?></div>
							<div class="ncx-launch-metric-label" id="ncx-metric-files-label"><?php esc_html_e( 'Static Files on Disk', 'nexora-engine' ); ?></div>
							<div class="ncx-launch-metric-sub" id="ncx-metric-files-sub"><?php esc_html_e( 'From this and prior builds', 'nexora-engine' ); ?></div>
						</div>
						<div class="ncx-launch-metric-divider"></div>
						<div class="ncx-launch-metric">
							<div class="ncx-launch-metric-icon">🏎</div>
							<div class="ncx-launch-metric-val" id="ncx-metric-ttfb">…</div>
							<div class="ncx-launch-metric-label"><?php esc_html_e( 'Est. TTFB', 'nexora-engine' ); ?></div>
						</div>
						<div class="ncx-launch-metric-divider"></div>
						<div class="ncx-launch-metric" title="<?php esc_attr_e( 'Static HTML is served directly — no PHP execution and no database queries on every page load.', 'nexora-engine' ); ?>">
							<div class="ncx-launch-metric-icon">🗄️</div>
							<div class="ncx-launch-metric-val">0</div>
							<div class="ncx-launch-metric-label"><?php esc_html_e( 'DB Queries', 'nexora-engine' ); ?></div>
							<div class="ncx-launch-metric-sub"><?php esc_html_e( 'Static — no DB on serve', 'nexora-engine' ); ?></div>
						</div>
						<div class="ncx-launch-metric-divider"></div>
						<div class="ncx-launch-metric">
							<div class="ncx-launch-metric-icon">🌐</div>
							<div class="ncx-launch-metric-val" id="ncx-metric-cdn-val"><?php esc_html_e( '…', 'nexora-engine' ); ?></div>
							<div class="ncx-launch-metric-label" id="ncx-metric-cdn-lbl"><?php esc_html_e( 'CDN', 'nexora-engine' ); ?></div>
						</div>
					</div>

					<!-- Tier card -->
					<div class="ncx-wiz-tier-card ncx-wiz-tier-card--final" id="ncx-final-tier">
						<div class="ncx-tier-icon-wrap"><span class="tier-icon">⚡</span></div>
						<div class="ncx-tier-body">
							<div class="ncx-tier-badge"><?php esc_html_e( 'Detecting…', 'nexora-engine' ); ?></div>
							<p class="ncx-tier-desc" id="ncx-final-tier-desc"><?php esc_html_e( 'Loading serve mode information…', 'nexora-engine' ); ?></p>
						</div>
						<div class="ncx-tier-ttfb-block">
							<div class="ncx-tier-ttfb-big" id="ncx-final-tier-ttfb">…</div>
							<div class="ncx-tier-ttfb-sub"><?php esc_html_e( 'Est. TTFB', 'nexora-engine' ); ?></div>
						</div>
					</div>

					<?php
					// ── Detect LocalWP and compute exact nginx config path ──────────
					$abspath_fwd   = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
					$is_localwp    = ( strpos( $abspath_fwd, '/Local Sites/' ) !== false );
					$nginx_conf    = '';
					$nginx_restart = '';

					if ( $is_localwp ) {
						// LocalWP structure: .../Local Sites/[site]/app/public
						// The location / block lives in the included wordpress-single conf.
						$site_root  = preg_replace( '#/app/public$#', '', $abspath_fwd );
						$conf_fwd   = $site_root . '/conf/nginx/includes/wordpress-single.conf.hbs';
						// Convert to OS path separator for display
						$nginx_conf    = str_replace( '/', DIRECTORY_SEPARATOR, $conf_fwd );
						$nginx_restart = 'localwp';
					} else {
						$nginx_conf    = '/etc/nginx/sites-available/' . sanitize_text_field( wp_parse_url( home_url(), PHP_URL_HOST ) );
						$nginx_restart = 'server';
					}

					$nginx_block = 'location / {' . "\n"
						. '    # Skip static cache for logged-in users (admin bar, Elementor editor, fresh nonces).' . "\n"
						. '    if ($http_cookie ~* "wordpress_logged_in_|wp-postpass_|comment_author_|woocommerce_items_in_cart|wp_woocommerce_session_") {' . "\n"
						. '        rewrite ^ /index.php last;' . "\n"
						. '    }' . "\n"
						. '    try_files /wp-content/uploads/nexora-static$uri/index.html' . "\n"
						. '              /wp-content/uploads/nexora-static$uri' . "\n"
						. '              $uri $uri/ /index.php$is_args$args;' . "\n"
						. '}';

					$full_context = 'server {' . "\n"
						. '    # ... your existing settings ...' . "\n\n"
						. '    # Replace (or add) the location / block with this:' . "\n"
						. '    ' . str_replace( "\n", "\n    ", $nginx_block ) . "\n\n"
						. '    # ... rest of server block ...' . "\n"
						. '}';

					$email_msg = "Hi,\n\nPlease add this Nginx rule to our site's config to enable full-speed static delivery (Nexora Engine).\n\nFile to edit: " . $nginx_conf . "\n\nReplace the existing location / block with:\n\n" . $nginx_block . ( $nginx_restart === 'server' ? "\n\nAfter saving, reload Nginx:\n  sudo nginx -t && sudo systemctl reload nginx" : '' );
					?>

					<!-- Build error box (shown by JS when captures had errors) -->
					<div id="ncx-build-errors" class="ncx-rp-result-box" style="display:none;margin-bottom:18px;"></div>

					<!-- Nginx rule already active (shown by JS when nexora-static rule detected in conf) -->
					<div id="ncx-nginx-confirmed" style="display:none;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #22c55e30;border-radius:8px;padding:14px 18px;margin-bottom:18px;">
						<span style="font-size:20px">✅</span>
						<div>
							<strong style="color:#15803d"><?php esc_html_e( 'Full speed is already active!', 'nexora-engine' ); ?></strong>
							<p style="margin:2px 0 0;color:#166534;font-size:13px"><?php esc_html_e( 'Your server is already configured to deliver pages directly — no PHP, no database, instant load times.', 'nexora-engine' ); ?></p>
						</div>
					</div>

					<!-- Nginx tip (shown only when Nginx rule is NOT yet configured) -->
					<div class="ncx-nginx-tip" id="ncx-nginx-tip" style="display:none">

						<div class="ncx-nginx-tip-head">
							<div class="ncx-nginx-tip-icon">⚡</div>
							<div>
								<strong><?php esc_html_e( 'One step to unlock full speed', 'nexora-engine' ); ?></strong>
								<span class="ncx-nginx-tip-badge"><?php esc_html_e( 'Optional · Nginx', 'nexora-engine' ); ?></span>
							</div>
						</div>

						<p><?php esc_html_e( 'Your site is already fast — the smart cache is running. To make it even faster, add one line to your server config so pages are served directly without running PHP at all (~5ms vs ~45ms).', 'nexora-engine' ); ?></p>

						<!-- File location pill -->
						<div class="ncx-nginx-file-location">
							<span class="ncx-nginx-file-label">
								<svg width="13" height="13" viewBox="0 0 14 14" fill="none"><path d="M2 3h10a1 1 0 011 1v7a1 1 0 01-1 1H2a1 1 0 01-1-1V4a1 1 0 011-1z" stroke="currentColor" stroke-width="1.3"/><path d="M1 6h12" stroke="currentColor" stroke-width="1.3"/></svg>
								<?php echo $is_localwp ? esc_html__( 'LocalWP config file:', 'nexora-engine' ) : esc_html__( 'Nginx config file (typical):', 'nexora-engine' ); ?>
							</span>
							<code class="ncx-nginx-file-path"><?php echo esc_html( $nginx_conf ); ?></code>
							<button class="ncx-nginx-copy-path" onclick="navigator.clipboard.writeText('<?php echo esc_js( $nginx_conf ); ?>').then(function(){this.textContent='✓';setTimeout(function(){this.textContent='Copy path';}.bind(this),1500);}.bind(this))">Copy path</button>
						</div>

						<!-- What to do -->
						<div class="ncx-nginx-steps">
							<div class="ncx-nginx-step">
								<span class="ncx-nginx-step-num">1</span>
								<div>
									<?php if ( $is_localwp ) : ?>
									<strong><?php esc_html_e( 'Open the config file', 'nexora-engine' ); ?></strong>
									<p><?php esc_html_e( 'Open this file in Notepad or any text editor:', 'nexora-engine' ); ?></p>
									<code class="ncx-nginx-inline-cmd">notepad "<?php echo esc_html( $nginx_conf ); ?>"</code>
									<?php else : ?>
									<strong><?php esc_html_e( 'Open your server config', 'nexora-engine' ); ?></strong>
									<p><?php esc_html_e( 'Log in via SSH and open the config file for this domain.', 'nexora-engine' ); ?></p>
									<?php endif; ?>
								</div>
							</div>
							<div class="ncx-nginx-step">
								<span class="ncx-nginx-step-num">2</span>
								<div>
									<strong><?php esc_html_e( 'Paste the code snippet below', 'nexora-engine' ); ?></strong>
									<p><?php esc_html_e( 'Find the existing location / { } section and replace it with the code below. Copy it with the button.', 'nexora-engine' ); ?></p>
								</div>
							</div>
							<div class="ncx-nginx-step">
								<span class="ncx-nginx-step-num">3</span>
								<div>
									<?php if ( $is_localwp ) : ?>
									<strong><?php esc_html_e( 'Save, then restart in LocalWP', 'nexora-engine' ); ?></strong>
									<p><?php esc_html_e( 'Save the file. In LocalWP, right-click your site and choose Restart.', 'nexora-engine' ); ?></p>
									<?php else : ?>
									<strong><?php esc_html_e( 'Save and apply', 'nexora-engine' ); ?></strong>
									<p><?php esc_html_e( 'Run this to apply without any downtime:', 'nexora-engine' ); ?></p>
									<code class="ncx-nginx-inline-cmd">sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>
									<?php endif; ?>
								</div>
							</div>
						</div>

						<!-- Code block -->
						<div class="ncx-code-block">
							<div class="ncx-code-block-label"><?php esc_html_e( 'Replace your location / { } block with this:', 'nexora-engine' ); ?></div>
							<button class="ncx-code-copy" onclick="navigator.clipboard.writeText(document.getElementById('ncx-nginx-code').textContent).then(function(){this.textContent='✓ Copied';setTimeout(function(){this.textContent='Copy';}.bind(this),1800);}.bind(this))"><?php esc_html_e( 'Copy', 'nexora-engine' ); ?></button>
							<pre><code id="ncx-nginx-code"><?php echo esc_html( $nginx_block ); ?></code></pre>
						</div>

						<!-- Actions -->
						<div class="ncx-nginx-tip-actions">
							<?php if ( ! $is_localwp ) : ?>
							<button class="ncx-wiz-btn ncx-wiz-btn--primary ncx-nginx-copy-btn" onclick="navigator.clipboard.writeText(<?php echo wp_json_encode( $email_msg ); ?>).then(function(){this.textContent='✓ Copied!';setTimeout(function(){this.textContent='Copy for your host';}.bind(this),2000);}.bind(this))">
								<svg width="14" height="14" viewBox="0 0 16 16" fill="none"><rect x="5" y="5" width="9" height="9" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M11 5V3a2 2 0 00-2-2H3a2 2 0 00-2 2v6a2 2 0 002 2h2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
								<?php esc_html_e( 'Copy for your hosting provider', 'nexora-engine' ); ?>
							</button>
							<span class="ncx-nginx-tip-note"><?php esc_html_e( 'Paste into a support ticket or email to your host', 'nexora-engine' ); ?></span>
							<?php endif; ?>
						</div>

						<div class="ncx-nginx-tip-footer">
							<svg width="15" height="15" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="#0252FA" stroke-width="1.4"/><path d="M8 7v4M8 5v.5" stroke="#0252FA" stroke-width="1.4" stroke-linecap="round"/></svg>
							<?php if ( $is_localwp ) : ?>
							<?php esc_html_e( 'Already applied this? Your speed cache is already running — this just takes it one step further.', 'nexora-engine' ); ?>
							<?php else : ?>
							<?php esc_html_e( 'Your site is already fast as-is. This is just the final step to make it even faster.', 'nexora-engine' ); ?>
							<?php endif; ?>
						</div>
					</div>

					<!-- Pro CTA / Pro active -->
					<?php if ( ! $is_pro ) : ?>
					<div class="ncx-wiz-pro-cta">
						<div class="ncx-wiz-pro-inner">
							<div class="ncx-wiz-pro-left">
								<span class="ncx-badge-pro-lg">PRO</span>
								<div>
									<strong><?php esc_html_e( 'Go completely invisible with WP Masking', 'nexora-engine' ); ?></strong>
									<p><?php esc_html_e( 'Strip all WordPress headers, generator tags, and fingerprints. Your site becomes unidentifiable — maximum security, zero traces.', 'nexora-engine' ); ?></p>
								</div>
							</div>
							<a href="<?php echo esc_url( $upgrade_url ); ?>" class="ncx-wiz-pro-btn" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro', 'nexora-engine' ); ?> →</a>
						</div>
					</div>
					<?php else : ?>
					<div class="ncx-wiz-pro-active">
						<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="9" r="9" fill="#22c55e"/><path d="M5.5 9l2.5 2.5 4.5-4.5" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<span><strong><?php esc_html_e( 'WP Masking Active', 'nexora-engine' ); ?></strong> — <?php esc_html_e( 'All WordPress fingerprints stripped from every response.', 'nexora-engine' ); ?></span>
					</div>
					<?php endif; ?>

					<!-- Incognito tip -->
					<div class="ncx-wiz-incognito-tip">
						<div class="ncx-wiz-incognito-icon">
							<svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="10" stroke="#0252FA" stroke-width="1.5"/><path d="M11 7v1M11 10v5" stroke="#0252FA" stroke-width="1.6" stroke-linecap="round"/></svg>
						</div>
						<div class="ncx-wiz-incognito-body">
							<strong><?php esc_html_e( 'Want to feel the speed yourself?', 'nexora-engine' ); ?></strong>
							<p><?php esc_html_e( 'Your browser loads WordPress directly while logged in — admin bar and nonces stay fresh. Open your site in a private / incognito window to experience exactly what visitors see.', 'nexora-engine' ); ?></p>
						</div>
						<a href="<?php echo esc_url( home_url() ); ?>" target="_blank" class="ncx-wiz-incognito-cta">
							<svg width="13" height="13" viewBox="0 0 14 14" fill="none"><path d="M2 7h10M8 3l4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
							<?php esc_html_e( 'Open Site', 'nexora-engine' ); ?>
						</a>
					</div>

					<!-- Step 5: Quick navigation pills — always visible at bottom -->
					<div class="ncx-step5-quick-nav">
						<a href="?page=ncx-headless" class="ncx-step5-nav-link">
							<span class="dashicons dashicons-cloud" aria-hidden="true"></span>
							<?php esc_html_e( 'Build Control', 'nexora-engine' ); ?>
						</a>
						<a href="?page=nexora" class="ncx-step5-nav-link">
							<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
							<?php esc_html_e( 'Analytics', 'nexora-engine' ); ?>
						</a>
						<a href="?page=ncx-settings" class="ncx-step5-nav-link">
							<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
							<?php esc_html_e( 'Settings', 'nexora-engine' ); ?>
						</a>
					</div>

				</div>
			</div><!-- /#step-5 -->

		</div><!-- /.ncx-wiz-body -->
		<aside class="ncx-wiz-context">
			<div class="ncx-wiz-context-card ncx-wiz-context-card--blue" id="ncx-wiz-sidebar-card">
				<span class="ncx-wiz-hero-kicker" id="ncx-wiz-sidebar-kicker"><?php esc_html_e( 'Setup Signals', 'nexora-engine' ); ?></span>
				<h2 id="ncx-wiz-sidebar-title"><?php esc_html_e( 'Live environment status', 'nexora-engine' ); ?></h2>
				<p id="ncx-wiz-sidebar-desc"><?php esc_html_e( 'Keep these signals visible while the wizard validates the environment and builds the mirror.', 'nexora-engine' ); ?></p>
					<div class="ncx-wiz-hero-signals" aria-label="<?php esc_attr_e( 'Setup signals', 'nexora-engine' ); ?>">
					<div class="ncx-wiz-signal">
						<span class="dashicons dashicons-admin-site-alt3" id="ncx-sig-1-icon" aria-hidden="true"></span>
						<div class="ncx-wiz-signal-copy">
							<strong id="ncx-sig-1-val"><?php echo esc_html( $server['tier_label'] ?? __( 'Server Ready', 'nexora-engine' ) ); ?></strong>
							<small id="ncx-sig-1-sub"><?php esc_html_e( 'Detected delivery mode', 'nexora-engine' ); ?></small>
						</div>
					</div>
					<div class="ncx-wiz-signal">
						<span class="dashicons dashicons-media-code" id="ncx-sig-2-icon" aria-hidden="true"></span>
						<div class="ncx-wiz-signal-copy">
							<strong id="ncx-sig-2-val"><?php echo esc_html( number_format_i18n( $files_count ) ); ?></strong>
							<small id="ncx-sig-2-sub"><?php esc_html_e( 'Static files currently built', 'nexora-engine' ); ?></small>
						</div>
					</div>
					<div class="ncx-wiz-signal">
						<span class="dashicons dashicons-shield-alt" id="ncx-sig-3-icon" aria-hidden="true"></span>
						<div class="ncx-wiz-signal-copy">
							<strong id="ncx-sig-3-val"><?php echo esc_html( (string) count( $conflicts ) ); ?></strong>
							<small id="ncx-sig-3-sub"><?php echo empty( $conflicts ) ? esc_html__( 'No plugin conflicts detected', 'nexora-engine' ) : esc_html__( 'Plugin compatibility notes', 'nexora-engine' ); ?></small>
						</div>
					</div>
				</div>
				<?php if ( ! empty( $conflicts ) ) : ?>
				<div class="ncx-wiz-compat-panel" id="ncx-wiz-compat-panel">
					<strong><?php esc_html_e( 'What we found & recommended actions', 'nexora-engine' ); ?></strong>
					<p><?php esc_html_e( 'These are advisory checks — only foreign cache drop-ins block setup. Review each item; most can stay enabled with the suggested tweak.', 'nexora-engine' ); ?></p>
					<ul class="ncx-wiz-compat-list">
						<?php foreach ( $conflicts as $conflict ) :
							$sev = $conflict['severity'] ?? 'low';
							$blocking = ( $conflict['slug'] ?? '' ) === 'foreign-dropin';
						?>
						<li class="ncx-wiz-compat-item severity-<?php echo esc_attr( $sev ); ?><?php echo esc_attr( $blocking ? ' is-blocking' : '' ); ?>">
							<div class="ncx-wiz-compat-head">
								<span class="ncx-wiz-compat-sev"><?php echo esc_html( strtoupper( $sev ) ); ?></span>
								<strong><?php echo esc_html( $conflict['name'] ?? __( 'Unknown plugin', 'nexora-engine' ) ); ?></strong>
							</div>
							<p><?php echo esc_html( $conflict['reason'] ?? '' ); ?></p>
							<p class="ncx-wiz-compat-fix"><span><?php esc_html_e( 'Recommendation', 'nexora-engine' ); ?>:</span> <?php echo esc_html( $conflict['fix'] ?? '' ); ?></p>
							<?php if ( $blocking ) : ?>
							<p class="ncx-wiz-compat-next"><?php esc_html_e( 'Next: resolve this in Step 3 (Conflicts) before the mirror can serve static files.', 'nexora-engine' ); ?></p>
							<?php endif; ?>
						</li>
						<?php endforeach; ?>
					</ul>
					<a class="ncx-wiz-compat-link" href="#step-3" data-goto-step="3"><?php esc_html_e( 'Open Conflicts step', 'nexora-engine' ); ?></a>
				</div>
				<?php else : ?>
				<p class="ncx-wiz-compat-clear"><?php esc_html_e( 'No known plugin conflicts. Continue to activation and your first mirror build.', 'nexora-engine' ); ?></p>
				<?php endif; ?>
			</div>
			<!-- Step-5 blocked-pages alert panel (hidden, shown by JS when build has errors) -->
			<div class="ncx-wiz-sidebar-alert" id="ncx-wiz-sidebar-alert" style="display:none">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<div class="ncx-wiz-sidebar-alert-body">
					<strong id="ncx-wiz-alert-title"></strong>
					<p id="ncx-wiz-alert-desc"></p>
				</div>
				<a href="?page=ncx-headless" class="ncx-wiz-alert-cta"><?php esc_html_e( 'Build Control', 'nexora-engine' ); ?></a>
			</div>
			<div class="ncx-wiz-overview" aria-label="<?php esc_attr_e( 'Setup overview', 'nexora-engine' ); ?>" id="ncx-wiz-overview">
				<div class="ncx-wiz-overview-card" id="ncx-wiz-ov-1">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Preflight', 'nexora-engine' ); ?></strong>
					<small><?php esc_html_e( 'Check server, filesystem, cache rules, and conflicts.', 'nexora-engine' ); ?></small>
				</div>
				<div class="ncx-wiz-overview-card" id="ncx-wiz-ov-2">
					<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Activation', 'nexora-engine' ); ?></strong>
					<small><?php esc_html_e( 'Enable the safest delivery mode available here.', 'nexora-engine' ); ?></small>
				</div>
				<div class="ncx-wiz-overview-card" id="ncx-wiz-ov-3">
					<span class="dashicons dashicons-media-code" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Mirror Build', 'nexora-engine' ); ?></strong>
					<small><?php esc_html_e( 'Build the first static mirror and verify delivery.', 'nexora-engine' ); ?></small>
				</div>
			</div>
		</aside>
	</div><!-- /.ncx-wiz-main -->
</div><!-- /.ncx-wizard-wrap -->
<?php endif; ?>

<?php ob_start(); ?>
*,*::before,*::after{box-sizing:border-box}

.ncx-wiz-inline-notice{margin:0;padding:12px 20px;background:#ecfdf5;color:#047857;font-size:13px;font-weight:600;border-bottom:1px solid #bbf7d0}
.ncx-wiz-inline-notice--warn{background:#fef3c7;color:#92400e;border-bottom-color:#fde68a}
.ncx-wizard-wrap:not(.ncx-js-ready) .ncx-wizard-step:not(.active){display:none!important}
.ncx-wizard-wrap:not(.ncx-js-ready) .ncx-wizard-step.active{display:block!important}

/* ── Wizard container ────────────────────────────────────────────────── */
.ncx-wizard-wrap{
	max-width:1100px;margin:0 auto;
	font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
	color:#0e1f3f;
	border-radius:22px;
	box-shadow:0 12px 48px rgba(2,82,250,.10),0 2px 8px rgba(0,0,0,.06);
	/* No fixed height — content determines height, page scrolls naturally.
	   overflow:hidden kept only to clip the sidebar/header backgrounds at the border-radius. */
	overflow:hidden;
}

/* ── Header ──────────────────────────────────────────────────────────── */
.ncx-wiz-header{
	background:linear-gradient(130deg,#0252FA 0%,#0842CC 55%,#0631A0 100%);
	border-radius:22px 22px 0 0;
	padding:16px 30px;
	display:flex;justify-content:space-between;align-items:center;gap:20px;
	flex-shrink:0;position:relative;overflow:hidden;
}
.ncx-wiz-header::after{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 80% 50%,rgba(255,255,255,.07) 0%,transparent 65%);pointer-events:none;}
.ncx-wiz-brand{display:flex;align-items:center;gap:13px;position:relative;z-index:1}
.ncx-wiz-logo{background:rgba(255,255,255,.15);border-radius:12px;padding:9px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.22)}
.ncx-wiz-brand-name{font-size:17px;font-weight:800;color:#fff;line-height:1.1;letter-spacing:-.01em}
.ncx-wiz-brand-sub{font-size:12px;color:rgba(255,255,255,.68);margin-top:3px;font-weight:500}
.ncx-wiz-step-counter{font-size:13px;font-weight:600;color:rgba(255,255,255,.82);background:rgba(255,255,255,.12);padding:6px 16px;border-radius:20px;border:1px solid rgba(255,255,255,.2);white-space:nowrap;position:relative;z-index:1}
#ncx-header-step-num{font-weight:800}

/* ── Main grid ───────────────────────────────────────────────────────── */
/* Grid naturally stretches both columns to the same height — sidebar fills without tricks */
.ncx-wiz-main{display:grid;grid-template-columns:210px 1fr}

/* ════════════════════════════════════════════════════════════════════════
   SIDEBAR — pure step navigator
════════════════════════════════════════════════════════════════════════ */
.ncx-wiz-sidebar{
	background:linear-gradient(180deg,#091929 0%,#0A1E35 100%);
	border-right:1px solid rgba(255,255,255,.06);
	display:flex;flex-direction:column;
	padding:28px 18px 24px;
	/* Grid stretches sidebar to full content height automatically */
}
.ncx-wiz-steps{flex:1;display:flex;flex-direction:column;gap:0}
.ncx-wiz-step-dot{display:flex;align-items:center;gap:12px;padding:6px 0;cursor:default}
.ncx-wiz-step-circle{
	width:34px;height:34px;flex-shrink:0;border-radius:50%;
	background:rgba(255,255,255,.07);border:1.5px solid rgba(255,255,255,.13);
	display:flex;align-items:center;justify-content:center;
	transition:all .3s ease;position:relative;
}
.ncx-wiz-step-num{font-size:13px;font-weight:700;color:rgba(255,255,255,.38);transition:opacity .2s}
.ncx-wiz-step-check{position:absolute;font-size:14px;color:#4ade80;opacity:0;transition:opacity .2s}
.ncx-wiz-step-text{display:flex;flex-direction:column;min-width:0}
.ncx-wiz-step-label{font-size:13px;font-weight:600;color:rgba(255,255,255,.38);white-space:nowrap;line-height:1.3;transition:color .2s}
.ncx-wiz-step-sublabel{font-size:11px;color:rgba(255,255,255,.22);margin-top:2px;white-space:nowrap;transition:color .2s}
.ncx-wiz-step-line{width:1.5px;height:20px;background:rgba(255,255,255,.1);margin:2px 0 2px 16px;border-radius:1px;flex-shrink:0;transition:background .3s}

/* Active step */
.ncx-wiz-step-dot.active .ncx-wiz-step-circle{background:#fff;border-color:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.12),0 2px 8px rgba(0,0,0,.2)}
.ncx-wiz-step-dot.active .ncx-wiz-step-num{color:#0252FA}
.ncx-wiz-step-dot.active .ncx-wiz-step-label{color:#fff;font-weight:700}
.ncx-wiz-step-dot.active .ncx-wiz-step-sublabel{color:rgba(255,255,255,.62)}

/* Completed step */
.ncx-wiz-step-dot.completed .ncx-wiz-step-circle{background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.4)}
.ncx-wiz-step-dot.completed .ncx-wiz-step-num{opacity:0}
.ncx-wiz-step-dot.completed .ncx-wiz-step-check{opacity:1}
.ncx-wiz-step-dot.completed .ncx-wiz-step-label{color:rgba(255,255,255,.62)}
.ncx-wiz-step-dot.completed .ncx-wiz-step-sublabel{color:rgba(255,255,255,.35)}
.ncx-wiz-step-dot.completed+.ncx-wiz-step-line{background:rgba(34,197,94,.28)}

/* ════════════════════════════════════════════════════════════════════════
   CONTENT AREA
════════════════════════════════════════════════════════════════════════ */
/* Body — no scroll, no overflow tricks. Page scrolls naturally */
.ncx-wiz-body{background:#fff;border-radius:0 0 22px 0}
.ncx-wizard-step{display:none}
/* Step is a plain block — no flex, no height constraint, no internal scroll */
.ncx-wizard-step.active{display:block;animation:ncxFadeIn .25s ease}
@keyframes ncxFadeIn{from{opacity:0}to{opacity:1}}

.ncx-wiz-step-inner{max-width:780px;margin:0 auto;padding:32px 36px 40px;width:100%}
/* Centered steps (2 & 4) — generous padding for visual breathing room */
.ncx-wiz-step-inner--center{text-align:center;padding-top:48px;padding-bottom:48px}

/* ── Step head: icon | title block | action button ───────────────────── */
.ncx-wiz-step-head{
	display:flex;gap:18px;align-items:flex-start;
	margin-bottom:24px;
	padding-bottom:22px;
	border-bottom:1px solid #f1f5f9;
}
.ncx-wiz-step-head--center{
	justify-content:center;text-align:center;
	flex-direction:column;align-items:center;
	border-bottom:none;padding-bottom:0;
}
.ncx-wiz-hero-icon{flex-shrink:0;margin-top:2px}
.ncx-wiz-step-title-block{flex:1;min-width:0}
.ncx-wiz-title{margin:0 0 8px;font-size:24px;font-weight:800;color:#0e1f3f;line-height:1.22;letter-spacing:-.025em}
.ncx-wiz-title--live{font-size:32px;background:linear-gradient(135deg,#0252FA,#22c55e);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.ncx-wiz-subtitle{margin:0;font-size:15px;color:#4b5e7a;line-height:1.65;max-width:520px}

/* Action column in step head */
.ncx-wiz-step-head-action{
	flex-shrink:0;
	display:flex;flex-direction:column;align-items:stretch;
	gap:7px;
	min-width:190px;
	max-width:210px;
	padding-left:16px;
	border-left:1px solid #f1f5f9;
}
.ncx-head-action-note{margin:0;font-size:12px;color:#94a3b8;text-align:center;line-height:1.4}
.ncx-note--warn{color:#f59e0b}

/* ── Two-col content ─────────────────────────────────────────────────── */
.ncx-wiz-two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
.ncx-wiz-section-label{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin:0 0 12px;display:flex;align-items:center;gap:6px}
.ncx-wiz-section-label::after{content:'';flex:1;height:1px;background:#f1f5f9}

/* Feature cards */
.ncx-wiz-feature-list{display:flex;flex-direction:column;gap:8px}
.ncx-wiz-feature{display:flex;gap:12px;align-items:flex-start;padding:13px 14px;background:#FAFBFF;border:1px solid #eef2ff;border-radius:12px;transition:border-color .2s,box-shadow .2s}
.ncx-wiz-feature:hover{border-color:#c7d7fd;box-shadow:0 2px 10px rgba(2,82,250,.05)}
.ncx-wiz-feature-icon{width:36px;height:36px;min-width:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.ncx-wiz-feature strong{display:block;font-size:14px;font-weight:700;color:#0e1f3f;margin-bottom:3px;line-height:1.3}
.ncx-wiz-feature p{margin:0;font-size:13px;color:#5a6f8a;line-height:1.55}

/* Check items */
.ncx-check-list{display:flex;flex-direction:column;gap:6px}
.ncx-check-item{display:flex;align-items:center;gap:11px;padding:11px 14px;background:#FAFBFD;border-radius:10px;border:1px solid #edf0f7;opacity:0;transform:translateX(-8px);transition:opacity .3s ease,transform .3s ease,border-color .2s}
.ncx-check-item.pass{border-color:#d1fae5;background:#FAFFFC}
.ncx-check-item.fail{border-color:#fef3c7;background:#FFFDF5}
.ncx-check-item.ready{opacity:1;transform:none}
.ncx-check-icon{flex-shrink:0;display:flex}
.ncx-check-body{flex:1;display:flex;justify-content:space-between;align-items:center;gap:8px;min-width:0}
.ncx-check-label{font-size:14px;font-weight:600;color:#1e3a5f;white-space:nowrap}
.ncx-check-value{font-size:12px;color:#5a6f8a;font-family:ui-monospace,monospace;background:#f1f5f9;padding:3px 8px;border-radius:5px;white-space:nowrap}

/* Tier card */
.ncx-wiz-tier-card{display:flex;align-items:center;gap:18px;padding:18px 22px;border:2px solid #e2e8f0;border-radius:16px;background:#fafbff;margin-bottom:18px;opacity:0;transform:translateY(6px);transition:opacity .4s ease,transform .4s ease}
.ncx-wiz-tier-card.visible{opacity:1;transform:none}
.ncx-tier-icon-wrap{width:50px;height:50px;border-radius:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ncx-tier-icon-wrap--lg{width:58px;height:58px;border-radius:16px;font-size:26px}
.ncx-tier-icon-wrap .dashicons{width:24px;height:24px;font-size:24px;line-height:1}
.ncx-tier-body{flex:1;min-width:0}
.ncx-tier-badge{display:inline-block;padding:5px 13px;border-radius:20px;font-size:13px;font-weight:700;margin-bottom:6px}
.ncx-tier-desc{margin:0;font-size:14px;color:#4b5e7a;line-height:1.55}
.ncx-tier-ttfb-block{text-align:center;flex-shrink:0}
.ncx-tier-ttfb-big{font-size:30px;font-weight:800;line-height:1;letter-spacing:-.02em}
.ncx-tier-ttfb-sub{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-top:3px}
.ncx-tier-action{display:flex;justify-content:flex-end;flex-shrink:0;margin-left:auto}
.ncx-wiz-tier-activate{min-height:44px;padding:12px 18px;border:1px solid rgba(2,82,250,.18);border-radius:10px;background:#0252FA;color:#fff;box-shadow:0 10px 22px rgba(2,82,250,.22)}
.ncx-wiz-tier-activate:hover{background:#063CE6;color:#fff;transform:translateY(-1px)}
.ncx-wiz-tier-activate.is-running,.ncx-wiz-tier-activate:disabled{background:#7897d7;border-color:#7897d7;color:#fff;box-shadow:none}
.ncx-wiz-tier-card--activated{opacity:1;transform:none;flex-direction:column;align-items:center;text-align:center;padding:24px;gap:12px}
.ncx-wiz-tier-card--activated .ncx-tier-icon-wrap{margin:0 auto}
.ncx-act-tier-title{display:block;font-size:18px;font-weight:800;color:#0e1f3f;margin:0;line-height:1.25}
.ncx-act-tier-desc{font-size:14px;color:#5a6f8a;margin:0;line-height:1.5}
.ncx-wiz-tier-card--activated .tier-icon{font-style:normal;font-size:26px;line-height:1;display:block}
.ncx-wiz-tier-card--final{opacity:1;transform:none}

/* Warning */
.ncx-wiz-warn{display:flex;gap:12px;align-items:flex-start;padding:14px 18px;background:#fffbeb;border:1px solid #fde68a;border-radius:11px;font-size:14px;color:#78350f}
.ncx-wiz-warn-icon{font-size:17px;flex-shrink:0;margin-top:1px}
.ncx-wiz-warn p{margin:0;line-height:1.55}

/* Activation checklist */
.ncx-act-checklist{border:1.5px solid #eef2f8;border-radius:14px;overflow:hidden;margin-bottom:20px;text-align:left;box-shadow:0 2px 10px rgba(2,82,250,.05)}
.ncx-act-item{display:flex;align-items:center;gap:14px;padding:15px 20px;background:#fff;border-bottom:1px solid #f5f7fb;transition:background .2s}
.ncx-act-item:last-child{border-bottom:none}
.ncx-act-item:hover{background:#FAFBFF}
.ncx-act-check{width:26px;height:26px;min-width:26px;background:#EDFDF4;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;color:#22c55e;font-weight:700;border:1.5px solid #bbf7d0}
.ncx-act-body{flex:1;min-width:0}
.ncx-act-label{display:block;font-size:15px;font-weight:700;color:#1e3a5f;line-height:1.3}
.ncx-act-desc{display:block;font-size:13px;color:#7d8fa8;margin-top:2px}
.ncx-act-status{font-size:12px;font-weight:700;padding:5px 12px;border-radius:20px;background:#f1f5f9;color:#64748b;white-space:nowrap}
.ncx-act-status--on{background:#DCFCE7;color:#15803d}
.ncx-act-status--pro{background:#EBF1FF;color:#0252FA}
.ncx-act-status--skip{background:#fef3c7;color:#92400e}

/* Auto-advance spinner */
.ncx-wiz-auto-advance{display:flex;align-items:center;justify-content:center;gap:11px;color:#94a3b8;font-size:14px;margin-top:20px}

/* Success state */
.ncx-success-state{text-align:center;padding:40px 32px;background:linear-gradient(150deg,#EBF1FF 0%,#F5F8FF 60%,#EEF6FF 100%);border:1.5px solid rgba(2,82,250,.14);border-radius:18px}
.ncx-success-glyph{margin-bottom:18px;display:flex;justify-content:center}
.ncx-success-glyph svg{filter:drop-shadow(0 6px 18px rgba(2,82,250,.2))}
.ncx-success-eyebrow{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#0252FA;margin-bottom:9px}
.ncx-success-state h3{font-size:22px;font-weight:800;color:#0e1f3f;margin:0 0 9px;letter-spacing:-.02em}
.ncx-success-state p{margin:0;font-size:15px;color:#4b5e7a;line-height:1.65;max-width:340px;margin-inline:auto}

/* Conflict list */
.ncx-conflict-list{display:flex;flex-direction:column;gap:13px}
.ncx-conflict-card{padding:18px 20px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;border-left:4px solid #ef4444}
.ncx-conflict-card.severity-medium{border-left-color:#f59e0b}
.ncx-conflict-head{display:flex;align-items:center;gap:9px;margin-bottom:10px}
.ncx-conflict-sev{font-size:11px;font-weight:800;letter-spacing:.07em;padding:3px 9px;border-radius:4px;background:#fee2e2;color:#991b1b}
.ncx-conflict-sev.sev-medium{background:#fef3c7;color:#92400e}
.ncx-conflict-head strong{font-size:15px;color:#0e1f3f}
.ncx-conflict-reason{margin:0 0 10px;font-size:14px;color:#4b5e7a;line-height:1.55}
.ncx-conflict-fix{display:block;font-size:12px;padding:9px 13px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;color:#0e1f3f;margin-bottom:12px;font-family:ui-monospace,monospace;line-height:1.5}

/* Build panel */
.ncx-build-panel{display:grid;grid-template-columns:160px 1fr;gap:28px;align-items:center;padding:28px;background:#fff;border:1.5px solid #eef2f8;border-radius:18px;margin:0 auto;text-align:left;box-shadow:0 4px 18px rgba(2,82,250,.06);max-width:580px;width:100%}
.ncx-build-ring-wrap{display:flex;justify-content:center}
.ncx-build-ring{position:relative;width:150px;height:150px;flex-shrink:0}
.ncx-build-ring svg{width:100%;height:100%}
.ncx-build-ring-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none}
.progress-percentage{font-size:28px;font-weight:800;color:#0252FA;line-height:1;letter-spacing:-.02em}
.progress-label{font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.06em;margin-top:3px}
.ncx-build-stats{display:flex;flex-direction:column;gap:15px}
.ncx-build-stat{display:flex;flex-direction:column;gap:4px}
.ncx-build-stat-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8}
.ncx-build-stat-value{font-size:22px;font-weight:800;color:#0e1f3f;letter-spacing:-.01em;overflow-wrap:anywhere;word-break:break-word}
.ncx-build-current-url{font-size:14px;font-weight:600;line-height:1.35}
.ncx-build-queue-note{margin:0;font-size:12px;line-height:1.45;color:#64748b}
.ncx-build-recovery{margin-top:14px;padding:14px;border-radius:12px;border:1px solid #fed7aa;background:#fffbeb;text-align:left}
.ncx-build-recovery strong{display:block;font-size:13px;color:#92400e;margin-bottom:6px}
.ncx-build-recovery p{margin:0 0 12px;font-size:12px;line-height:1.45;color:#b45309}
.ncx-build-recovery-actions{display:flex;flex-wrap:wrap;gap:10px}
.ncx-wiz-http-auth-notice{display:flex;gap:14px;align-items:flex-start;text-align:left;margin:0 0 18px;padding:16px 18px;border-radius:14px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a}
.ncx-wiz-http-auth-notice[hidden]{display:none!important}
.ncx-wiz-http-auth-notice .dashicons{width:22px;height:22px;font-size:22px;color:#2563eb;flex-shrink:0;margin-top:2px}
.ncx-wiz-http-auth-notice strong{display:block;font-size:15px;margin-bottom:6px;color:#1e40af}
.ncx-wiz-http-auth-notice p,.ncx-wiz-http-auth-notice ol{margin:0 0 12px;font-size:13px;line-height:1.5;color:#1d4ed8}
.ncx-wiz-http-auth-notice ol{padding-left:18px}
.ncx-launch-metric-sub{font-size:10px;color:#94a3b8;margin-top:2px;line-height:1.3}
.ncx-build-divider{font-size:18px;font-weight:400;color:#c0ccda;margin:0 3px}
.ncx-build-current-url{font-size:13px!important;font-weight:500!important;color:#5a6f8a!important;font-family:ui-monospace,monospace;word-break:break-all;letter-spacing:0!important}
.ncx-build-progress-bar{height:9px;background:#eef2f8;border-radius:999px;overflow:hidden}
.progress-fill{height:100%;background:linear-gradient(90deg,#0252FA,#38BDF8);width:0%;transition:width .5s ease;border-radius:999px}
.ncx-build-info-pill{display:flex;align-items:flex-start;gap:8px;padding:10px 14px;background:#EBF1FF;border-radius:10px;font-size:13px;color:#2d5fc9;line-height:1.55}

/* Step 5 launch */
.ncx-wiz-launch-hero{margin-bottom:10px}
.ncx-wiz-launch-icon{margin-bottom:16px}
.ncx-wiz-launch-ring{display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:50%;animation:ncxPulse 2.5s ease-in-out infinite}
@keyframes ncxPulse{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.3)}50%{box-shadow:0 0 0 14px rgba(34,197,94,0)}}

/* Step 5 action buttons — directly below hero */
.ncx-wiz-step5-actions{
	display:flex;gap:12px;justify-content:center;
	margin-bottom:22px;flex-wrap:wrap;
}
.ncx-wiz-btn--xl{padding:14px 28px;font-size:16px;border-radius:13px}

.ncx-launch-metrics{display:flex;justify-content:center;align-items:center;gap:0;margin-bottom:20px;background:#F9FAFB;border:1.5px solid #eef2f8;border-radius:16px;overflow:hidden;flex-wrap:wrap}
.ncx-launch-metric{flex:1;min-width:100px;text-align:center;padding:18px 12px}
.ncx-launch-metric-divider{width:1.5px;align-self:stretch;background:#eef2f8;flex-shrink:0}
.ncx-launch-metric-icon{font-size:22px;margin-bottom:6px;line-height:1}
.ncx-launch-metric-val{font-size:32px;font-weight:800;color:#0252FA;line-height:1;letter-spacing:-.03em}
.ncx-launch-metric-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#7d8fa8;margin-top:5px}
.ncx-launch-metric-sub{font-size:10px;color:#a0aec0;margin-top:3px;line-height:1.3}

/* Nginx tip */
.ncx-nginx-tip{background:#f8faff;border:1.5px solid #c7d7fd;border-radius:16px;padding:20px 22px;margin-bottom:18px;text-align:left}
.ncx-nginx-tip-head{display:flex;align-items:center;gap:12px;margin-bottom:10px}
.ncx-nginx-tip-icon{font-size:26px;line-height:1;flex-shrink:0}
.ncx-nginx-tip-head strong{font-size:15px;font-weight:800;color:#0e1f3f;display:block;margin-bottom:3px}
.ncx-nginx-tip-badge{font-size:11px;font-weight:700;background:#EBF1FF;color:#0252FA;padding:2px 9px;border-radius:20px;letter-spacing:.04em}
.ncx-nginx-tip>p{margin:0 0 14px;font-size:14px;color:#4b5e7a;line-height:1.65}

/* File location pill */
.ncx-nginx-file-location{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#0e1f3f;border-radius:10px;margin-bottom:16px;flex-wrap:wrap}
.ncx-nginx-file-label{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:rgba(255,255,255,.55);white-space:nowrap;flex-shrink:0}
.ncx-nginx-file-path{font-family:ui-monospace,monospace;font-size:12px;color:#7dd3fc;flex:1;word-break:break-all;background:none;padding:0;border:none}
.ncx-nginx-copy-path{flex-shrink:0;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);color:rgba(255,255,255,.75);border-radius:6px;padding:3px 10px;font-size:11px;font-weight:600;cursor:pointer;transition:background .15s;white-space:nowrap}
.ncx-nginx-copy-path:hover{background:rgba(255,255,255,.2);color:#fff}

/* Steps */
.ncx-nginx-steps{display:flex;flex-direction:column;gap:12px;margin-bottom:16px}
.ncx-nginx-step{display:flex;gap:13px;align-items:flex-start}
.ncx-nginx-step-num{width:24px;height:24px;min-width:24px;background:#0252FA;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;margin-top:1px}
.ncx-nginx-step strong{display:block;font-size:14px;font-weight:700;color:#0e1f3f;margin-bottom:3px}
.ncx-nginx-step p{margin:0 0 6px;font-size:13px;color:#4b5e7a;line-height:1.55}
.ncx-nginx-inline-cmd{display:block;font-family:ui-monospace,monospace;font-size:12px;padding:7px 11px;background:#0e1f3f;color:#7dd3fc;border-radius:7px;margin-top:5px;word-break:break-all}

/* Code block label */
.ncx-code-block-label{font-size:11px;font-weight:700;color:rgba(255,255,255,.45);letter-spacing:.05em;text-transform:uppercase;margin-bottom:8px}

.ncx-nginx-tip-actions{display:flex;align-items:center;gap:14px;margin-top:14px;flex-wrap:wrap;min-height:0}
.ncx-nginx-copy-btn{font-size:14px;padding:10px 18px}
.ncx-nginx-tip-note{font-size:12px;color:#7d8fa8;line-height:1.45}
.ncx-nginx-tip-footer{display:flex;align-items:flex-start;gap:8px;margin-top:14px;padding:11px 14px;background:#EBF1FF;border-radius:10px;font-size:13px;color:#2d5fc9;line-height:1.55}
.ncx-code-block{position:relative;background:#0e1f3f;border-radius:10px;padding:16px}
.ncx-code-block pre{margin:0;overflow-x:auto}
.ncx-code-block code{font-size:13px;color:#e2e8f0;font-family:ui-monospace,monospace;white-space:pre;line-height:1.6}
.ncx-code-copy{position:absolute;top:10px;right:10px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff;border-radius:7px;padding:4px 11px;font-size:12px;cursor:pointer;transition:background .15s}
.ncx-code-copy:hover{background:rgba(255,255,255,.2)}

/* Pro CTA */
.ncx-wiz-pro-cta{background:linear-gradient(130deg,#0252FA,#0631A0);border-radius:15px;padding:2px;margin-bottom:18px}
.ncx-wiz-pro-inner{display:flex;align-items:center;gap:20px;padding:18px 22px;background:linear-gradient(130deg,#EBF1FF 0%,#F8FAFF 100%);border-radius:13px;flex-wrap:wrap}
.ncx-wiz-pro-left{display:flex;align-items:flex-start;gap:14px;flex:1;min-width:160px}
.ncx-wiz-pro-left strong{display:block;font-size:15px;font-weight:800;color:#0e1f3f;margin-bottom:4px;line-height:1.3}
.ncx-wiz-pro-left p{margin:0;font-size:13px;color:#4b5e7a;line-height:1.55}
.ncx-wiz-pro-btn{background:#0252FA;color:#fff!important;padding:12px 20px;border-radius:11px;font-size:14px;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .2s,transform .2s;display:inline-block;box-shadow:0 4px 14px rgba(2,82,250,.32)}
.ncx-wiz-pro-btn:hover{background:#063CE6;transform:translateY(-1px);color:#fff!important}
.ncx-wiz-pro-active{display:flex;align-items:center;gap:11px;padding:14px 18px;background:#EDFDF4;border:1.5px solid #A7F3D0;border-radius:12px;font-size:15px;color:#065F46;margin-bottom:18px}

/* Incognito tip */
.ncx-wiz-incognito-tip{display:flex;align-items:flex-start;gap:15px;padding:16px 20px;background:#F5F8FF;border:1.5px solid #c7d7fd;border-radius:14px;text-align:left}
.ncx-wiz-incognito-icon{flex-shrink:0;width:38px;height:38px;background:#EBF1FF;border-radius:11px;display:flex;align-items:center;justify-content:center}
.ncx-wiz-incognito-body{flex:1;min-width:0}
.ncx-wiz-incognito-body strong{display:block;font-size:14px;font-weight:700;color:#0e1f3f;margin-bottom:4px}
.ncx-wiz-incognito-body p{margin:0;font-size:13px;color:#4b5e7a;line-height:1.65}
.ncx-wiz-incognito-cta{flex-shrink:0;align-self:center;display:inline-flex;align-items:center;gap:7px;padding:9px 16px;background:#0252FA;color:#fff!important;border-radius:9px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .18s}
.ncx-wiz-incognito-cta:hover{background:#0640D0;color:#fff!important}

/* Spinner */
.ncx-wiz-spinner{width:18px;height:18px;border:2.5px solid #e2e8f0;border-top-color:#0252FA;border-radius:50%;animation:ncxSpin .8s linear infinite}
@keyframes ncxSpin{to{transform:rotate(360deg)}}

/* ── Buttons ──────────────────────────────────────────────────────────── */
.ncx-wiz-btn{display:inline-flex;align-items:center;gap:9px;padding:12px 22px;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:all .2s ease;line-height:1.2}
.ncx-wiz-btn--lg{padding:13px 24px;font-size:15px}
.ncx-wiz-btn--xl{padding:14px 28px;font-size:16px;border-radius:13px}
.ncx-wiz-btn--fill{width:100%;justify-content:center}
.ncx-wiz-btn--primary{background:linear-gradient(135deg,#0252FA,#0640D0);color:#fff;box-shadow:0 4px 16px rgba(2,82,250,.30)}
.ncx-wiz-btn--primary:hover{transform:translateY(-1px);box-shadow:0 7px 22px rgba(2,82,250,.40);color:#fff}
.ncx-wiz-btn--outline{background:#fff;color:#334155;border:1.5px solid #dde4ef}
.ncx-wiz-btn--outline:hover{border-color:#0252FA;color:#0252FA;background:#f7f9ff}
.ncx-wiz-btn--ghost{background:transparent;color:#4b5e7a;border:1.5px solid #dde4ef}
.ncx-wiz-btn--ghost:hover{background:#f7f9ff;color:#0e1f3f;border-color:#c7d7fd}
.ncx-wiz-btn:disabled{opacity:.55;cursor:not-allowed;transform:none!important;box-shadow:none!important}

/* Badges */
.ncx-badge-pro{background:linear-gradient(135deg,#0252FA,#0640D0);color:#fff;font-size:10px;font-weight:800;letter-spacing:.07em;padding:2px 7px;border-radius:4px;vertical-align:middle;margin-left:4px}
.ncx-badge-pro-lg{background:linear-gradient(135deg,#0252FA,#0640D0);color:#fff;font-size:11px;font-weight:800;letter-spacing:.06em;padding:4px 10px;border-radius:5px;white-space:nowrap;flex-shrink:0}

/* ── Responsive ───────────────────────────────────────────────────────── */
@media(max-width:900px){
	.ncx-wizard-wrap{border-radius:16px}
	.ncx-wiz-main{grid-template-columns:1fr}
	.ncx-wiz-sidebar{flex-direction:row;flex-wrap:wrap;gap:14px;padding:14px 18px;border-right:none;border-bottom:1px solid rgba(255,255,255,.08);border-radius:0}
	.ncx-wiz-steps{flex-direction:row;flex:1;gap:0;align-items:center;flex-wrap:wrap}
	.ncx-wiz-step-dot{flex-direction:column;gap:4px;min-width:52px;text-align:center;padding:3px 0}
	.ncx-wiz-step-text{align-items:center}
	.ncx-wiz-step-sublabel{display:none}
	.ncx-wiz-step-line{width:20px;height:1.5px;margin:15px 2px 0}
	.ncx-wiz-body{border-radius:0 0 16px 16px}
}
@media(max-width:680px){
	.ncx-wiz-step-head:not(.ncx-wiz-step-head--center){flex-wrap:wrap;align-items:flex-start}
	.ncx-wiz-step-head-action{width:100%;max-width:none;min-width:unset;border-left:none;border-top:1px solid #f1f5f9;padding-left:0;padding-top:14px;flex-direction:column;align-items:stretch;gap:12px}
	.ncx-head-action-note{text-align:left}
	.ncx-wiz-btn--fill{width:100%;justify-content:center}
	.ncx-wiz-compat-panel{padding:12px}
}
@media(max-width:580px){
	.ncx-wiz-header{padding:12px 18px}
	.ncx-wiz-step-counter{display:none}
	.ncx-wiz-step-inner{padding:22px 18px}
	.ncx-wiz-two-col{grid-template-columns:1fr}
	.ncx-wiz-tier-card{flex-wrap:wrap}
	.ncx-tier-action{width:100%;margin-left:0}
	.ncx-wiz-tier-activate{width:100%;justify-content:center}
	.ncx-build-panel{grid-template-columns:1fr;justify-items:center;gap:20px;padding:20px}
	.ncx-launch-metrics{flex-direction:column}
	.ncx-launch-metric-divider{width:auto;height:1px;align-self:auto;flex:none}
	.ncx-wiz-pro-inner{flex-direction:column}
	.ncx-wiz-incognito-tip{flex-wrap:wrap}
	.ncx-wiz-incognito-cta{width:100%;justify-content:center}
	.ncx-wiz-title{font-size:20px}
	.ncx-wiz-title--live{font-size:26px}
	.ncx-wiz-step5-actions{flex-direction:column;align-items:stretch}
	.ncx-step5-quick-nav{gap:6px}
	.ncx-step5-nav-link{padding:7px 12px;font-size:12px}
}

/* ── Step 5 — sidebar blocked-pages alert ───────────────────────────── */
.ncx-wiz-sidebar-alert{display:flex;align-items:flex-start;gap:10px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px 14px;margin-top:10px}
.ncx-wiz-sidebar-alert>.dashicons{width:18px;height:18px;font-size:18px;color:#d97706;flex-shrink:0;margin-top:2px}
.ncx-wiz-sidebar-alert-body{flex:1;min-width:0}
.ncx-wiz-sidebar-alert-body strong{display:block;font-size:12px;color:#7c2d12;margin-bottom:2px;font-weight:700}
.ncx-wiz-sidebar-alert-body p{margin:0;color:#b45309;font-size:11px;line-height:1.4}
.ncx-wiz-alert-cta{font-size:11px;font-weight:700;color:#d97706;text-decoration:none;white-space:nowrap;align-self:center;flex-shrink:0}
.ncx-wiz-alert-cta:hover{color:#b45309;text-decoration:underline}

/* ── Step 5 — quick navigation pills ───────────────────────────────── */
.ncx-step5-quick-nav{display:flex;justify-content:center;align-items:center;gap:8px;margin-top:22px;padding-top:18px;border-top:1px dashed #e2e8f0;flex-wrap:wrap}
.ncx-step5-nav-link{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:#f4f7ff;border:1.5px solid #c7d7fd;border-radius:999px;font-size:13px;font-weight:600;color:#0252FA;text-decoration:none;transition:all .15s ease}
.ncx-step5-nav-link:hover{background:#e8f0ff;border-color:#a5b4fd;box-shadow:0 2px 8px rgba(2,82,250,.1);color:#0341cc}
.ncx-step5-nav-link .dashicons{font-size:15px;width:15px;height:15px;color:inherit}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>

<?php if ( ! $wizard_completed_only ) : ?>
<?php ob_start(); ?>
document.addEventListener('DOMContentLoaded', function () {
    // Keep header step counter in sync with goToStep()
    if (window.MutationObserver) {
        var obs = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                if (m.attributeName === 'class') {
                    var el = m.target;
                    if (el.classList.contains('active') && el.id && el.id.indexOf('step-') === 0) {
                        var counter = document.getElementById('ncx-header-step-num');
                        if (counter) counter.textContent = el.id.replace('step-', '');
                        // Scroll content to top on step transition
                        var body = document.querySelector('.ncx-wiz-body');
                        if (body) body.scrollTop = 0;
                    }
                }
            });
        });
        document.querySelectorAll('.ncx-wizard-step').forEach(function (s) {
            obs.observe(s, { attributes: true });
        });
    }
});
<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
<?php endif; ?>
