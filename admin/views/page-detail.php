<?php
/**
 * Nexora Engine — Page Detail View
 *
 * @var NEXENG_Database $db
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'edit_posts' ) ) {
	wp_die( esc_html__( 'Permission denied.', 'nexora-engine' ) );
}

$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
$post    = $post_id ? get_post( $post_id ) : null;

if ( ! $post instanceof WP_Post ) {
	echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Post not found. Please select a page from the dashboard.', 'nexora-engine' ) . '</p></div></div>';
	return;
}

$blog_id     = get_current_blog_id();
// NEXENG_White_Label is a __premium_only class — absent from the free build.
$brand       = class_exists( 'NEXENG_White_Label' ) ? NEXENG_White_Label::brand_name()  : 'Nexora Engine';
$brand_color = class_exists( 'NEXENG_White_Label' ) ? NEXENG_White_Label::brand_color() : '#2563eb';
$is_pro      = NEXENG_Licence::is_pro();

$row         = $db->get_page_score( $blog_id, $post_id );
$overall     = $row ? (int) $row['overall_score'] : 0;
$score_class = NEXENG_Scorer::get_score_class( $overall );
$score_label = NEXENG_Scorer::get_score_label_i18n( $overall );

$module_scores = [
	'seo'         => $row ? (int) $row['seo_score']         : 0,
	'performance' => $row ? (int) $row['performance_score'] : 0,
	'security'    => $row ? (int) $row['security_score']    : 0,
	'indexing'    => $row ? (int) $row['indexing_score']    : 0,
];

$issues = $db->get_issues( $blog_id, $post_id, [ 'status' => 'open' ] );

// Group issues by module prefix.
$grouped = [];
foreach ( $issues as $issue ) {
	$parts  = explode( '_', $issue['issue_key'], 3 );
	$module = $parts[1] ?? 'other';
	$grouped[ $module ][] = $issue;
}

// Scan history (PRO).
$history = [];
if ( $is_pro ) {
	$history = $db->get_scan_history( $blog_id, $post_id, 10 );
}
?>
<div class="ncx-wrap">

	<div class="ncx-header">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexora' ) ); ?>" class="ncx-back">&larr; <?php esc_html_e( 'Dashboard', 'nexora-engine' ); ?></a>
		<h1 class="ncx-page-heading" style="color:<?php echo esc_attr( $brand_color ); ?>">
			<?php echo esc_html( $post->post_title ?: __( '(no title)', 'nexora-engine' ) ); ?>
		</h1>
		<div class="ncx-header__meta">
			<span class="ncx-post-type"><?php echo esc_html( $post->post_type ); ?></span>
			<a href="<?php echo esc_url( get_permalink( $post_id ) ?: '' ); ?>" target="_blank" rel="noopener" class="ncx-view-link"><?php esc_html_e( 'View Page', 'nexora-engine' ); ?> &rarr;</a>
			<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ?: '' ); ?>" class="ncx-edit-link"><?php esc_html_e( 'Edit Post', 'nexora-engine' ); ?></a>
		</div>
	</div>

	<!-- Score Cards Row -->
	<div class="ncx-cards ncx-cards--detail">
		<div class="ncx-card ncx-card--score">
			<div class="ncx-score-ring ncx-score-ring--<?php echo esc_attr( $score_class ); ?>" id="ncx-overall-ring" data-score="<?php echo (int) $overall; ?>">
				<span class="ncx-score-number" id="ncx-overall-score"><?php echo (int) $overall; ?></span>
				<span class="ncx-score-label"><?php echo esc_html( $score_label ); ?></span>
			</div>
			<p class="ncx-card__title"><?php esc_html_e( 'Overall Score', 'nexora-engine' ); ?></p>
			<div class="ncx-card__actions">
				<button type="button" id="ncx-run-scan" class="button button-primary ncx-btn-scan" data-post-id="<?php echo (int) $post_id; ?>">
					<?php esc_html_e( 'Run Full Scan', 'nexora-engine' ); ?>
				</button>
				<?php if ( $is_pro ) : ?>
				<button type="button" id="ncx-download-pdf" class="button ncx-btn-pdf" data-post-id="<?php echo (int) $post_id; ?>">
					<?php esc_html_e( 'Download PDF', 'nexora-engine' ); ?>
				</button>
				<?php endif; ?>
			</div>
		</div>

		<?php
		$module_labels = [
			'seo'         => __( 'SEO', 'nexora-engine' ),
			'performance' => __( 'Performance', 'nexora-engine' ),
			'security'    => __( 'Security', 'nexora-engine' ),
			'indexing'    => __( 'Indexing', 'nexora-engine' ),
		];
		foreach ( $module_scores as $module => $score ) :
			$cls = NEXENG_Scorer::get_score_class( $score );
		?>
		<div class="ncx-card ncx-card--module">
			<div class="ncx-score-ring ncx-score-ring--sm ncx-score-ring--<?php echo esc_attr( $cls ); ?>">
				<span class="ncx-score-number"><?php echo (int) $score; ?></span>
			</div>
			<p class="ncx-card__title"><?php echo esc_html( $module_labels[ $module ] ); ?></p>
		</div>
		<?php endforeach; ?>
	</div>

	<!-- Issues -->
	<div class="ncx-section" id="ncx-issues-section">
		<div class="ncx-section__header">
			<h2><?php esc_html_e( 'Open Issues', 'nexora-engine' ); ?></h2>
			<span class="ncx-issue-total"><?php echo (int) count( $issues ); ?></span>
		</div>

		<?php if ( empty( $issues ) ) : ?>
			<div class="ncx-empty ncx-empty--success">
				<p><?php esc_html_e( 'No open issues found. Great job!', 'nexora-engine' ); ?></p>
			</div>
		<?php else :
			foreach ( $grouped as $module => $module_issues ) :
				$module_label = $module_labels[ $module ] ?? ucfirst( $module );
		?>
			<div class="ncx-issue-group">
				<h3 class="ncx-issue-group__title"><?php echo esc_html( $module_label ); ?></h3>
				<?php foreach ( $module_issues as $issue ) : ?>
				<div class="ncx-issue ncx-issue--<?php echo esc_attr( $issue['severity'] ); ?>" data-issue-key="<?php echo esc_attr( $issue['issue_key'] ); ?>">
					<div class="ncx-issue__header">
						<span class="ncx-severity-badge ncx-severity-badge--<?php echo esc_attr( $issue['severity'] ); ?>"><?php echo esc_html( ucfirst( $issue['severity'] ) ); ?></span>
						<strong class="ncx-issue__title"><?php echo esc_html( $issue['title'] ); ?></strong>
						<div class="ncx-issue__actions">
							<button type="button" class="button button-small ncx-btn-resolve" data-post-id="<?php echo (int) $post_id; ?>" data-issue-key="<?php echo esc_attr( $issue['issue_key'] ); ?>">
								<?php esc_html_e( 'Resolve', 'nexora-engine' ); ?>
							</button>
							<button type="button" class="button button-small ncx-btn-ignore" data-post-id="<?php echo (int) $post_id; ?>" data-issue-key="<?php echo esc_attr( $issue['issue_key'] ); ?>">
								<?php esc_html_e( 'Ignore', 'nexora-engine' ); ?>
							</button>
						</div>
					</div>
					<div class="ncx-issue__body">
						<p class="ncx-issue__explanation"><?php echo wp_kses_post( $issue['explanation'] ); ?></p>
						<?php if ( ! empty( $issue['fix'] ) ) : ?>
						<details class="ncx-issue__fix">
							<summary><?php esc_html_e( 'How to fix', 'nexora-engine' ); ?></summary>
							<div class="ncx-issue__fix-body"><?php echo wp_kses_post( $issue['fix'] ); ?></div>
						</details>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach;
		endif; ?>
	</div>

	<?php if ( $is_pro && ! empty( $history ) ) : ?>
	<!-- Scan History (PRO) -->
	<div class="ncx-section">
		<div class="ncx-section__header">
			<h2><?php esc_html_e( 'Score History', 'nexora-engine' ); ?></h2>
		</div>
		<table class="widefat ncx-table ncx-table--history">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'nexora-engine' ); ?></th>
					<th><?php esc_html_e( 'Site Score', 'nexora-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history as $snap ) : ?>
				<tr>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $snap['created_at'] ) ) ); ?></td>
					<td><span class="ncx-score-pill ncx-score-pill--<?php echo esc_attr( NEXENG_Scorer::get_score_class( (int) $snap['site_score'] ) ); ?>"><?php echo (int) $snap['site_score']; ?></span></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

</div>
