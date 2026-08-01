<?php
/**
 * Nexora Engine — Security Hardening View
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_pro = NEXENG_Licence::is_pro();

$security_options = [

	// ── Free features ────────────────────────────────────────────────────────
	[
		'id'    => 'secure_users_api',
		'label' => 'Block User Enumeration (REST)',
		'desc'  => 'Restricts /wp-json/wp/v2/users to authenticated requests only, preventing automated username harvesting.',
		'icon'  => 'dashicons-id-alt',
		'pro'   => false,
	],
	[
		'id'    => 'secure_author_enum',
		'label' => 'Block Author Enumeration (URL)',
		'desc'  => 'Returns 404 for ?author=N requests used to map valid usernames via author archive redirects.',
		'icon'  => 'dashicons-hidden',
		'pro'   => false,
	],
	[
		'id'    => 'secure_xmlrpc',
		'label' => 'Disable XML-RPC',
		'desc'  => 'Turns off the legacy XML-RPC protocol — a common amplification vector for brute-force attacks. <strong style="color:#92400E;">Note:</strong> the Jetpack plugin and the WordPress mobile app rely on XML-RPC; disable only if you don\'t use them.',
		'icon'  => 'dashicons-networking',
		'pro'   => false,
	],
	[
		'id'    => 'secure_remove_version',
		'label' => 'Remove WordPress Version',
		'desc'  => 'Strips the WP version from the <code>&lt;meta name=generator&gt;</code> tag and RSS / Atom feeds. (Safe for frontend caching — does not touch <code>?ver=</code> cache-busters.)',
		'icon'  => 'dashicons-visibility',
		'pro'   => false,
	],
	[
		'id'    => 'secure_login_errors',
		'label' => 'Mask Login Error Messages',
		'desc'  => 'Replaces specific "Invalid username" / "Incorrect password" messages with a single generic response so attackers can\'t tell which field was wrong.',
		'icon'  => 'dashicons-warning',
		'pro'   => false,
	],

	// ── Pro features ─────────────────────────────────────────────────────────
	[
		'id'    => 'secure_rest_tighten',
		'label' => 'Tighten REST API Access',
		'desc'  => 'Requires authentication for the comments and media REST endpoints. <strong style="color:#92400E;">Advanced:</strong> test before enabling — public comment forms or front-end image galleries that fetch these endpoints will break.',
		'icon'  => 'dashicons-lock',
		'pro'   => true,
	],
	[
		'id'    => 'secure_rate_limit',
		'label' => 'Login Rate Limiting',
		'desc'  => 'Locks out an IP address for 15 minutes after 5 consecutive failed login attempts.',
		'icon'  => 'dashicons-shield',
		'pro'   => true,
	],
	[
		'id'    => 'secure_strong_pass',
		'label' => 'Force Strong Passwords',
		'desc'  => 'Enforces 12+ characters with uppercase, number, and symbol on profile updates, password resets, and registration.',
		'icon'  => 'dashicons-forms',
		'pro'   => true,
	],
	[
		'id'          => 'secure_login_rename',
		'label'       => 'Rename Login URL',
		'desc'        => 'Moves <code>wp-login.php</code> to a custom path. Direct access to <code>wp-login.php</code> returns 404. <strong style="color:#92400E;">Important:</strong> save your new URL before logging out — losing the slug means database-level recovery.',
		'icon'        => 'dashicons-migrate',
		'pro'         => true,
		'has_input'   => 'secure_login_slug',
		'placeholder' => 'e.g. my-secure-login',
		'input_label' => 'Login slug',
	],
	[
		'id'    => 'secure_disable_file_edit',
		'label' => 'Disable Theme/Plugin Editor',
		'desc'  => 'Removes the Appearance → Theme File Editor and Plugins → Plugin File Editor from wp-admin. Prevents code injection through a compromised admin account.',
		'icon'  => 'dashicons-editor-code',
		'pro'   => true,
	],
	[
		'id'    => 'secure_headers',
		'label' => 'Security Response Headers',
		'desc'  => 'Sends <code>X-Frame-Options</code>, <code>X-Content-Type-Options</code>, and <code>Referrer-Policy</code> on every PHP-rendered response. (These headers are <em>always</em> sent on SSG cached pages — this toggle extends them to PHP fallback responses.)',
		'icon'  => 'dashicons-shield-alt',
		'pro'   => true,
	],
];
?>

<div class="ncx-header">
	<div class="ncx-header-title">
		<h1><?php esc_html_e( 'Security Hardening', 'nexora-engine' ); ?></h1>
		<p><?php esc_html_e( 'PHP-only guards — active on Apache, Nginx, LiteSpeed, and every other server without .htaccess changes.', 'nexora-engine' ); ?></p>
	</div>
	<div class="ncx-header-actions">
		<button class="ncx-btn ncx-btn-primary" id="ncxSecuritySaveBtn" onclick="ncxSaveSecuritySettings()">
			<span class="dashicons dashicons-saved"></span>
			<?php esc_html_e( 'Save Rules', 'nexora-engine' ); ?>
		</button>
	</div>
</div>

<div class="ncx-dashboard-grid" style="grid-template-columns:1fr;">
	<div class="ncx-section">

		<div class="ncx-section-header">
			<h2><?php esc_html_e( 'Active Protection Modules', 'nexora-engine' ); ?></h2>
			<div style="display:flex;gap:8px;align-items:center;">
				<?php if ( $is_pro ) : ?>
					<span class="ncx-badge ncx-badge-info"><?php esc_html_e( 'Pro — All modules available', 'nexora-engine' ); ?></span>
				<?php else : ?>
					<span class="ncx-badge" style="background:rgba(100,116,139,.1);color:#64748b;">
						<?php esc_html_e( 'Free Tier — Pro features locked', 'nexora-engine' ); ?>
					</span>
				<?php endif; ?>
			</div>
		</div>

		<!-- Free guards -->
		<div class="ncx-security-group">
			<div class="ncx-security-group-label"><?php esc_html_e( 'Essential Guards', 'nexora-engine' ); ?></div>
			<?php foreach ( $security_options as $opt ) :
				if ( ! empty( $opt['pro'] ) ) continue;
			?>
			<div class="ncx-security-row">
				<div class="ncx-security-info">
					<span class="dashicons <?php echo esc_attr( $opt['icon'] ); ?>"></span>
					<div class="ncx-security-text">
						<label for="ncx-<?php echo esc_attr( $opt['id'] ); ?>"><?php echo esc_html( $opt['label'] ); ?></label>
						<p><?php echo wp_kses( $opt['desc'], [ 'strong' => [ 'style' => [] ], 'em' => [], 'code' => [] ] ); ?></p>
					</div>
				</div>
				<div class="ncx-security-action">
					<label class="ncx-switch">
						<input type="checkbox"
							   id="ncx-<?php echo esc_attr( $opt['id'] ); ?>"
							   <?php checked( get_option( 'nexeng_' . $opt['id'] ), 'on' ); ?>>
						<span class="ncx-slider"></span>
					</label>
				</div>
			</div>
			<?php endforeach; ?>
		</div>

		<!-- Pro guards -->
		<div class="ncx-security-group ncx-security-group--pro <?php echo esc_attr( $is_pro ? '' : 'ncx-security-group--locked' ); ?>">
			<div class="ncx-security-group-label">
				<?php esc_html_e( 'Advanced Guards', 'nexora-engine' ); ?>
				<span class="ncx-sec-pro-tag">
					<svg width="9" height="9" viewBox="0 0 9 9" fill="none"><path d="M4.5 1L5.7 3.3L8.1 3.7L6.3 5.4L6.7 7.9L4.5 6.7L2.3 7.9L2.7 5.4L.9 3.7L3.3 3.3L4.5 1Z" fill="currentColor"/></svg>
					PRO
				</span>
				<?php if ( ! $is_pro ) : ?>
					<a href="<?php echo esc_url( function_exists('NexoraEngine\\get_upgrade_url') ? \NexoraEngine\get_upgrade_url('pro') : '#' ); ?>"
					   class="ncx-sec-upgrade-link" target="_blank">
						<?php esc_html_e( 'Upgrade to unlock →', 'nexora-engine' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php foreach ( $security_options as $opt ) :
				if ( empty( $opt['pro'] ) ) continue;
				$locked = ! $is_pro;
			?>
			<div class="ncx-security-row <?php echo esc_attr( $locked ? 'ncx-security-row--locked' : '' ); ?>">
				<div class="ncx-security-info">
					<span class="dashicons <?php echo esc_attr( $opt['icon'] ); ?>"></span>
					<div class="ncx-security-text">
						<label for="ncx-<?php echo esc_attr( $opt['id'] ); ?>">
							<?php echo esc_html( $opt['label'] ); ?>
						</label>
						<p><?php echo wp_kses( $opt['desc'], [ 'strong' => [ 'style' => [] ], 'em' => [], 'code' => [] ] ); ?></p>

						<?php if ( ! empty( $opt['has_input'] ) ) : ?>
						<div class="ncx-security-slug-wrap" id="ncx-slug-wrap-<?php echo esc_attr( $opt['id'] ); ?>"
							 style="<?php echo esc_attr( ( get_option( 'nexeng_' . $opt['id'] ) === 'on' && ! $locked ) ? '' : 'display:none;' ); ?>">
							<span class="ncx-slug-prefix"><?php echo esc_html( home_url( '/' ) ); ?></span>
							<input type="text"
								   id="ncx-<?php echo esc_attr( $opt['has_input'] ); ?>"
								   class="ncx-input ncx-slug-input"
								   placeholder="<?php echo esc_attr( $opt['placeholder'] ?? '' ); ?>"
								   value="<?php echo esc_attr( get_option( 'nexeng_' . $opt['has_input'], '' ) ); ?>"
								   <?php disabled( $locked ); ?>>
							<p class="ncx-slug-hint"><?php esc_html_e( '⚠ Note your new URL before saving. Incorrect slug can lock you out.', 'nexora-engine' ); ?></p>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<div class="ncx-security-action">
					<label class="ncx-switch <?php echo esc_attr( $locked ? 'ncx-switch--disabled' : '' ); ?>">
						<?php
						// Stamp the saved state onto the checkbox so the JS save gate
						// can tell whether the user changed it during this visit.
						$_saved_on   = get_option( 'nexeng_' . $opt['id'] ) === 'on';
						$_saved_slug = ! empty( $opt['has_input'] ) ? get_option( 'nexeng_' . $opt['has_input'], '' ) : '';
						?>
						<input type="checkbox"
							   id="ncx-<?php echo esc_attr( $opt['id'] ); ?>"
							   data-was-on="<?php echo esc_attr( $_saved_on ? '1' : '0' ); ?>"
							   <?php if ( ! empty( $opt['has_input'] ) ) : ?>
							   data-was-slug="<?php echo esc_attr( $_saved_slug ); ?>"
							   <?php endif; ?>
							   <?php checked( $_saved_on ); ?>
							   <?php disabled( $locked ); ?>
							   <?php if ( ! empty( $opt['has_input'] ) ) : ?>
							   onchange="ncxToggleSlugInput('<?php echo esc_js( $opt['id'] ); ?>', this.checked)"
							   <?php endif; ?>>
						<span class="ncx-slider"></span>
					</label>
				</div>
			</div>
			<?php endforeach; ?>
		</div>

	</div>
</div>

<?php
// ── Post-save success banner ──────────────────────────────────────────────────
// When a save just applied a login-rename change, surface the new URL
// prominently with a copy button so the user can bookmark it before logging
// out. The `nexeng_login_rename_just_saved` transient is set by save_settings.
$_just_renamed = get_transient( 'nexeng_login_rename_just_saved' );
if ( $_just_renamed && is_array( $_just_renamed ) ) {
	delete_transient( 'nexeng_login_rename_just_saved' );
	$_new_url = isset( $_just_renamed['url'] ) ? (string) $_just_renamed['url'] : '';
?>
<div class="ncx-login-rename-banner" role="alert">
	<div class="ncx-lrb-icon">🛡️</div>
	<div class="ncx-lrb-body">
		<strong><?php esc_html_e( 'Login URL changed — save this link now', 'nexora-engine' ); ?></strong>
		<p><?php esc_html_e( 'Your wp-login.php page now returns 404. Bookmark the new URL below before you log out, or you\'ll need database access to recover.', 'nexora-engine' ); ?></p>
		<div class="ncx-lrb-url-row">
			<code id="ncx-new-login-url"><?php echo esc_html( $_new_url ); ?></code>
			<button type="button" class="ncx-btn ncx-btn-primary ncx-btn-sm" onclick="ncxCopyNewLoginUrl()">
				<span class="dashicons dashicons-clipboard"></span>
				<?php esc_html_e( 'Copy URL', 'nexora-engine' ); ?>
			</button>
			<a href="<?php echo esc_url( $_new_url ); ?>" target="_blank" rel="noopener" class="ncx-btn ncx-btn-outline ncx-btn-sm">
				<span class="dashicons dashicons-external"></span>
				<?php esc_html_e( 'Open in new tab', 'nexora-engine' ); ?>
			</a>
		</div>
	</div>
	<button type="button" class="ncx-lrb-close" onclick="this.closest('.ncx-login-rename-banner').remove();" title="<?php esc_attr_e( 'Dismiss', 'nexora-engine' ); ?>">&times;</button>
</div>
<?php } ?>

<!-- ── Login Rename Confirmation Modal ─────────────────────────────────────── -->
<div id="ncx-login-rename-modal" class="ncx-modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ncx-lrm-title">
	<div class="ncx-modal">
		<div class="ncx-modal-icon">⚠️</div>
		<h3 class="ncx-modal-title" id="ncx-lrm-title"><?php esc_html_e( 'Confirm new login URL', 'nexora-engine' ); ?></h3>
		<p class="ncx-modal-desc">
			<?php esc_html_e( 'After saving, your old wp-login.php page will return 404. The only way to reach the WordPress login screen will be the new URL below — write it down or bookmark it before continuing.', 'nexora-engine' ); ?>
		</p>
		<div class="ncx-modal-url-box">
			<span class="ncx-modal-url-label"><?php esc_html_e( 'New login URL', 'nexora-engine' ); ?></span>
			<code id="ncx-lrm-url-display">—</code>
		</div>
		<div class="ncx-modal-checklist">
			<label>
				<input type="checkbox" id="ncx-lrm-ack-saved">
				<span><?php esc_html_e( 'I have copied or bookmarked this URL', 'nexora-engine' ); ?></span>
			</label>
			<label>
				<input type="checkbox" id="ncx-lrm-ack-risk">
				<span><?php esc_html_e( 'I understand that losing this URL requires database recovery', 'nexora-engine' ); ?></span>
			</label>
		</div>
		<div class="ncx-modal-actions">
			<button type="button" class="ncx-btn ncx-btn-outline" onclick="ncxHideLoginRenameModal()">
				<?php esc_html_e( 'Cancel', 'nexora-engine' ); ?>
			</button>
			<button type="button" class="ncx-btn ncx-btn-primary" id="ncx-lrm-confirm" disabled onclick="ncxConfirmLoginRename()">
				<?php esc_html_e( 'Apply login URL change', 'nexora-engine' ); ?>
			</button>
		</div>
	</div>
</div>

<?php ob_start(); ?>
/* ── Security groups ───────────────────────────────────────────────────────── */
.ncx-security-group { margin-top: 24px; }
.ncx-security-group-label {
	display: flex; align-items: center; gap: 8px;
	font-size: 11px; font-weight: 700; text-transform: uppercase;
	letter-spacing: .07em; color: var(--ncx-muted, #6B7280);
	margin-bottom: 12px; padding-bottom: 8px;
	border-bottom: 1px solid var(--ncx-border, #E5E7EB);
}
.ncx-sec-pro-tag {
	display: inline-flex; align-items: center; gap: 3px;
	padding: 2px 7px; border-radius: 10px;
	background: rgba(2,82,250,.1); color: #0252FA;
	font-size: 10px; font-weight: 800; letter-spacing: .06em;
}
.ncx-sec-upgrade-link {
	font-size: 11px; font-weight: 700; color: #0252FA;
	text-decoration: none; margin-left: auto;
}
.ncx-sec-upgrade-link:hover { text-decoration: underline; }

/* Pro group locked state */
.ncx-security-group--locked { opacity: .65; }

/* ── Rows ──────────────────────────────────────────────────────────────────── */
.ncx-security-row {
	display: flex; justify-content: space-between; align-items: flex-start;
	padding: 16px 0; border-bottom: 1px solid var(--ncx-border, #E5E7EB);
	gap: 16px;
}
.ncx-security-row:last-child { border-bottom: none; }
.ncx-security-row--locked .ncx-switch { cursor: not-allowed; }
.ncx-security-info { display: flex; gap: 14px; align-items: flex-start; flex: 1; }
.ncx-security-info .dashicons { font-size: 22px; width: 22px; height: 22px; color: var(--ncx-blue, #0252FA); flex-shrink: 0; margin-top: 2px; }
.ncx-security-text label { display: block; font-weight: 600; font-size: 14px; color: #1a1a1a; margin-bottom: 3px; cursor: pointer; }
.ncx-security-text > p { margin: 0; font-size: 12px; color: #6B7280; line-height: 1.5; }
.ncx-security-action { flex-shrink: 0; padding-top: 2px; }

/* ── Login slug inline input ───────────────────────────────────────────────── */
.ncx-security-slug-wrap {
	display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
	margin-top: 10px; padding: 10px 12px;
	background: rgba(2,82,250,.04); border: 1px solid rgba(2,82,250,.15);
	border-radius: 8px;
}
.ncx-slug-prefix { font-size: 12px; color: #6B7280; white-space: nowrap; }
.ncx-slug-input { width: 180px !important; font-size: 13px !important; padding: 6px 10px !important; }
.ncx-slug-hint { width: 100%; margin: 4px 0 0 !important; font-size: 11px !important; color: #b45309 !important; }

/* ── Disabled switch ───────────────────────────────────────────────────────── */
.ncx-switch--disabled { opacity: .5; cursor: not-allowed; pointer-events: none; }

/* ── Input ─────────────────────────────────────────────────────────────────── */
.ncx-input {
	padding: 8px 12px; border: 1px solid var(--ncx-border, #E5E7EB);
	border-radius: var(--ncx-radius-sm, 6px); font-size: 13px;
}

/* ── Login Rename — confirmation modal ─────────────────────────────────────── */
.ncx-modal-backdrop {
	position: fixed; inset: 0;
	background: rgba(17,24,39,.6);
	backdrop-filter: blur(3px);
	z-index: 100000;
	display: flex; align-items: center; justify-content: center;
	padding: 20px;
	animation: ncxModalFade .18s ease;
}
@keyframes ncxModalFade { from { opacity:0 } to { opacity:1 } }
.ncx-modal {
	background: #fff; border-radius: 14px; max-width: 480px; width: 100%;
	box-shadow: 0 24px 48px rgba(0,0,0,.18);
	padding: 28px 28px 22px;
	animation: ncxModalSlide .22s cubic-bezier(.4,0,.2,1);
}
@keyframes ncxModalSlide { from { transform:translateY(12px); opacity:0 } to { transform:translateY(0); opacity:1 } }
.ncx-modal-icon { font-size: 36px; line-height: 1; text-align: center; margin-bottom: 10px; }
.ncx-modal-title { margin: 0 0 8px; font-size: 18px; font-weight: 700; color: #111827; text-align: center; }
.ncx-modal-desc { margin: 0 0 16px; font-size: 13px; line-height: 1.6; color: #4B5563; text-align: center; }
.ncx-modal-url-box {
	background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 10px;
	padding: 12px 14px; margin-bottom: 16px;
}
.ncx-modal-url-label {
	display: block; font-size: 10px; font-weight: 700; text-transform: uppercase;
	letter-spacing: .07em; color: #6B7280; margin-bottom: 4px;
}
.ncx-modal-url-box code {
	display: block; font-size: 13px; font-weight: 600; color: #0252FA;
	background: none; padding: 0; word-break: break-all;
}
.ncx-modal-checklist {
	display: flex; flex-direction: column; gap: 10px;
	background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px;
	padding: 12px 14px; margin-bottom: 18px;
}
.ncx-modal-checklist label {
	display: flex; align-items: flex-start; gap: 9px;
	font-size: 12px; line-height: 1.5; color: #78350F; cursor: pointer;
}
.ncx-modal-checklist input[type=checkbox] {
	margin: 2px 0 0; flex-shrink: 0;
}
.ncx-modal-actions {
	display: flex; gap: 10px; justify-content: flex-end;
}
.ncx-modal-actions .ncx-btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Post-save success banner ──────────────────────────────────────────────── */
.ncx-login-rename-banner {
	display: flex; gap: 14px; align-items: flex-start;
	margin-bottom: 22px; padding: 16px 18px;
	background: linear-gradient(135deg,#ECFDF5,#D1FAE5);
	border: 1px solid #6EE7B7; border-left: 4px solid #059669;
	border-radius: 12px;
	animation: ncxBannerPop .35s cubic-bezier(.4,0,.2,1);
}
@keyframes ncxBannerPop { from { transform:translateY(-4px); opacity:0 } to { transform:translateY(0); opacity:1 } }
.ncx-lrb-icon { font-size: 26px; line-height: 1; flex-shrink: 0; margin-top: 2px; }
.ncx-lrb-body { flex: 1; min-width: 0; }
.ncx-lrb-body strong { display: block; font-size: 14px; color: #064E3B; margin-bottom: 3px; }
.ncx-lrb-body p { margin: 0 0 10px; font-size: 12px; color: #065F46; line-height: 1.55; }
.ncx-lrb-url-row {
	display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
	background: #fff; border: 1px solid #6EE7B7; border-radius: 8px;
	padding: 8px 10px;
}
.ncx-lrb-url-row code {
	flex: 1; min-width: 200px;
	font-size: 13px; font-weight: 600; color: #0252FA;
	background: none; padding: 0; word-break: break-all;
}
.ncx-btn-sm { padding: 5px 10px !important; font-size: 12px !important; }
.ncx-btn-sm .dashicons { font-size: 13px !important; width: 13px !important; height: 13px !important; margin-right: 4px; }
.ncx-lrb-close {
	background: none; border: none; cursor: pointer;
	font-size: 20px; line-height: 1; color: #059669;
	padding: 0 4px; flex-shrink: 0;
}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>

<?php ob_start(); ?>
function ncxToggleSlugInput(optId, checked) {
	var wrap = document.getElementById('ncx-slug-wrap-' + optId);
	if (wrap) wrap.style.display = checked ? '' : 'none';
}

// ── Login-rename confirmation modal state ─────────────────────────────────────
// Set to true after the user clicks "Apply login URL change" inside the modal,
// so the save function re-entered after the modal closes proceeds without
// re-prompting.
var ncxLoginRenameConfirmed = false;

function ncxShowLoginRenameModal(newUrl) {
	var modal   = document.getElementById('ncx-login-rename-modal');
	var display = document.getElementById('ncx-lrm-url-display');
	var confirm = document.getElementById('ncx-lrm-confirm');
	var ack1    = document.getElementById('ncx-lrm-ack-saved');
	var ack2    = document.getElementById('ncx-lrm-ack-risk');
	if (!modal) return;
	if (display) display.textContent = newUrl;
	if (ack1) ack1.checked = false;
	if (ack2) ack2.checked = false;
	if (confirm) confirm.disabled = true;

	// Both ack checkboxes must be ticked to enable the confirm button.
	function syncBtn() {
		if (confirm) confirm.disabled = !( ack1 && ack1.checked && ack2 && ack2.checked );
	}
	if (ack1) ack1.onchange = syncBtn;
	if (ack2) ack2.onchange = syncBtn;
	modal.style.display = 'flex';
}

function ncxHideLoginRenameModal() {
	var modal = document.getElementById('ncx-login-rename-modal');
	if (modal) modal.style.display = 'none';
	// Re-enable the save button so the user can retry.
	var btn = document.getElementById('ncxSecuritySaveBtn');
	if (btn) ncxSetLoading(btn, false);
}

function ncxConfirmLoginRename() {
	ncxLoginRenameConfirmed = true;
	var modal = document.getElementById('ncx-login-rename-modal');
	if (modal) modal.style.display = 'none';
	// Re-trigger the save with the confirmation flag set.
	ncxSaveSecuritySettings();
}

function ncxCopyNewLoginUrl() {
	var el = document.getElementById('ncx-new-login-url');
	if (!el) return;
	var text = el.textContent.trim();
	if (navigator.clipboard && window.isSecureContext) {
		navigator.clipboard.writeText(text).then(function() {
			ncxToast('<?php echo esc_js( __( 'Login URL copied to clipboard.', 'nexora-engine' ) ); ?>', 'success');
		});
	} else {
		var tmp = document.createElement('textarea');
		tmp.value = text;
		document.body.appendChild(tmp);
		tmp.select();
		try { document.execCommand('copy'); ncxToast('<?php echo esc_js( __( 'Login URL copied.', 'nexora-engine' ) ); ?>', 'success'); } catch(e){}
		document.body.removeChild(tmp);
	}
}

function ncxSaveSecuritySettings() {
	var btn = document.getElementById('ncxSecuritySaveBtn');

	// ── Login-rename safety gate ──────────────────────────────────────────────
	// Renaming the login URL is the single most lock-out-prone setting in this
	// panel. Three hard guards before save proceeds:
	//   1. Slug must not be empty.
	//   2. Slug must not collide with a reserved WP path (wp-admin, wp-login.php,
	//      wp-content, wp-json, etc) — collision = users locked out immediately.
	//   3. User must explicitly confirm via a modal that names the new URL,
	//      describes the consequence, and offers to copy the URL to clipboard.
	// Was state changed since the page loaded?
	var renameEl  = document.getElementById('ncx-secure_login_rename');
	var slugEl    = document.getElementById('ncx-secure_login_slug');
	var wasOn     = renameEl ? renameEl.dataset.wasOn === '1' : false;
	var wasSlug   = renameEl ? (renameEl.dataset.wasSlug || '') : '';
	var slugVal   = slugEl ? slugEl.value.trim() : '';

	if (renameEl && renameEl.checked) {
		if (!slugVal) {
			ncxToast('<?php echo esc_js( __( 'Set a login slug before enabling Login URL Rename.', 'nexora-engine' ) ); ?>', 'error');
			slugEl.focus();
			return;
		}
		// Block reserved WP paths to prevent self-lockout.
		var reserved = ['wp-admin','wp-login','wp-login.php','wp-content','wp-includes','wp-json','admin','login','xmlrpc.php','wp-cron.php','feed','sitemap.xml','robots.txt'];
		var lower = slugVal.toLowerCase().replace(/^\/+|\/+$/g, '');
		if (reserved.indexOf(lower) !== -1) {
			ncxToast('<?php echo esc_js( __( 'That slug conflicts with a reserved WordPress path. Pick something unique like "secure-portal" or "site-access".', 'nexora-engine' ) ); ?>', 'error');
			slugEl.focus();
			return;
		}
		// Slug format: 3-32 chars, letters/digits/hyphens only.
		if (!/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/i.test(lower)) {
			ncxToast('<?php echo esc_js( __( 'Slug must be 3–32 characters: letters, digits, and hyphens only.', 'nexora-engine' ) ); ?>', 'error');
			slugEl.focus();
			return;
		}
		// Confirmation modal — only when state actually changed (newly enabling
		// or changing the slug). No nag-prompt on unchanged re-saves.
		var newUrl   = '<?php echo esc_js( home_url( '/' ) ); ?>' + lower + '/';
		var stateChange = (!wasOn) || (lower !== wasSlug.toLowerCase());
		if (stateChange && !ncxLoginRenameConfirmed) {
			ncxShowLoginRenameModal(newUrl);
			return; // user clicks "I understand — apply" inside modal to proceed
		}
	}

	// NOTE: secure_files removed in 2026-05 audit — Apache/Nginx serve readme.html
	// directly before PHP loads, so the PHP filter never ran. False security promise.
	var toggleIds = [
		'secure_users_api', 'secure_author_enum', 'secure_xmlrpc',
		'secure_remove_version', 'secure_login_errors',
		'secure_rest_tighten', 'secure_rate_limit', 'secure_strong_pass',
		'secure_login_rename', 'secure_disable_file_edit', 'secure_headers'
	];

	var settings = {};
	toggleIds.forEach(function(id) {
		var el = document.getElementById('ncx-' + id);
		if (el) settings[id] = el.checked ? 'on' : 'off';
	});

	// Text input: login slug
	if (slugEl) {
		settings['secure_login_slug'] = slugEl.value.trim();
	}

	ncxSetLoading(btn, true);

	ncxCall('save_settings', { settings: settings }).then(function(res) {
		if (res.success) {
			ncxToast('<?php echo esc_js( __( 'Security rules saved.', 'nexora-engine' ) ); ?>', 'success');
			setTimeout(function() { location.reload(); }, 1400);
		} else {
			ncxToast((res.data && res.data.message) || '<?php echo esc_js( __( 'Failed to save rules.', 'nexora-engine' ) ); ?>', 'error');
			ncxSetLoading(btn, false);
		}
	});
}
<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
