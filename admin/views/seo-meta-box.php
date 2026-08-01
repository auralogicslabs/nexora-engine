<?php
/**
 * SEO Meta Box View
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$og_title = $seo_data['og_title'] ?? '';
$og_desc  = $seo_data['og_desc'] ?? '';
$og_image = $seo_data['og_image'] ?? '';
$schema_type = $seo_data['schema_type'] ?? 'Article';
$schema_custom = $seo_data['schema_custom'] ?? '';
?>

<div class="ncx-seo-metabox">
    <!-- Tabs -->
    <div class="ncx-seo-tabs">
        <button type="button" class="ncx-tab-btn active" data-tab="social">Social Pulse</button>
        <button type="button" class="ncx-tab-btn" data-tab="schema">Schema</button>
    </div>

    <div class="ncx-seo-content">
        <!-- Social Pulse Tab -->
        <div id="ncx-tab-social" class="ncx-tab-panel active">
            <div class="ncx-seo-grid">
                <div class="ncx-seo-fields">
                    <div class="ncx-field-group">
                        <label>OG Title</label>
                        <input type="text" name="nexeng_og_title" value="<?php echo esc_attr( $og_title ); ?>" placeholder="Leave empty for post title" onkeyup="updateSocialPreview()">
                    </div>
                    <div class="ncx-field-group">
                        <label>OG Description</label>
                        <textarea name="nexeng_og_desc" rows="3" placeholder="Leave empty for excerpt" onkeyup="updateSocialPreview()"><?php echo esc_textarea( $og_desc ); ?></textarea>
                    </div>
                    <div class="ncx-field-group">
                        <label>OG Image URL</label>
                        <div class="ncx-image-input">
                            <input type="text" name="nexeng_og_image" id="nexeng_og_image" value="<?php echo esc_url( $og_image ); ?>" onchange="updateSocialPreview()">
                            <button type="button" class="ncx-btn ncx-btn-sm" onclick="ncxOpenMedia('nexeng_og_image')">Select</button>
                        </div>
                    </div>
                </div>

                <div class="ncx-seo-preview">
                    <span class="ncx-preview-label">Social Preview</span>
                    <div class="ncx-social-card">
                        <div class="ncx-social-image" id="ncx-preview-image" style="background-image: url('<?php echo esc_url( $og_image ?: get_the_post_thumbnail_url( $post->ID, 'large' ) ); ?>')">
                            <?php if ( ! $og_image && ! has_post_thumbnail() ): ?>
                                <div class="ncx-no-image">No Image Selected</div>
                            <?php endif; ?>
                        </div>
                        <div class="ncx-social-content">
                            <div class="ncx-social-site"><?php echo esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div>
                            <div class="ncx-social-title" id="ncx-preview-title"><?php echo esc_html( $og_title ?: get_the_title() ); ?></div>
                            <div class="ncx-social-desc" id="ncx-preview-desc"><?php echo esc_html( $og_desc ?: wp_trim_words( get_the_excerpt(), 20 ) ); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schema Tab -->
        <div id="ncx-tab-schema" class="ncx-tab-panel">
            <div class="ncx-field-group">
                <label>Schema Type</label>
                <select name="nexeng_schema_type">
                    <option value="Article" <?php selected($schema_type, 'Article'); ?>>Article</option>
                    <option value="Product" <?php selected($schema_type, 'Product'); ?>>Product</option>
                    <option value="FAQPage" <?php selected($schema_type, 'FAQPage'); ?>>FAQ Page</option>
                    <option value="Recipe" <?php selected($schema_type, 'Recipe'); ?>>Recipe</option>
                    <option value="Event" <?php selected($schema_type, 'Event'); ?>>Event</option>
                </select>
                <p class="description">Nexora will automatically generate JSON-LD based on this type.</p>
            </div>
            <div class="ncx-field-group">
                <label>Custom JSON-LD (Advanced)</label>
                <textarea name="nexeng_schema_custom" rows="8" placeholder='{"@context": "https://schema.org", ...}'><?php echo esc_textarea( $schema_custom ); ?></textarea>
            </div>
        </div>
    </div>
</div>

<?php ob_start(); ?>
.ncx-seo-metabox { background: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px solid #e2e2e2; }
.ncx-seo-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #e2e2e2; padding-bottom: 10px; }
.ncx-tab-btn { background: none; border: none; padding: 8px 16px; cursor: pointer; font-weight: 600; color: #666; transition: all 0.2s; border-radius: 4px; }
.ncx-tab-btn.active { background: var(--ncx-primary, #0252FA); color: #fff; }
.ncx-tab-panel { display: none; }
.ncx-tab-panel.active { display: block; }
.ncx-seo-grid { display: grid; grid-template-columns: 1fr 320px; gap: 30px; }
.ncx-field-group { margin-bottom: 15px; }
.ncx-field-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #333; }
.ncx-field-group input, .ncx-field-group textarea, .ncx-field-group select { width: 100%; border: 1px solid #ddd; border-radius: 4px; padding: 8px; }
.ncx-image-input { display: flex; gap: 10px; }

/* Social Card Preview */
.ncx-preview-label { font-size: 11px; text-transform: uppercase; color: #999; margin-bottom: 10px; display: block; font-weight: 700; }
.ncx-social-card { background: #fff; border: 1px solid #e2e2e2; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.ncx-social-image { height: 160px; background-size: cover; background-position: center; background-color: #eee; display: flex; align-items: center; justify-content: center; }
.ncx-no-image { color: #999; font-size: 12px; }
.ncx-social-content { padding: 12px; }
.ncx-social-site { font-size: 11px; color: #999; text-transform: uppercase; margin-bottom: 4px; }
.ncx-social-title { font-size: 14px; font-weight: 700; color: #1a1a1a; margin-bottom: 4px; line-height: 1.3; }
.ncx-social-desc { font-size: 12px; color: #666; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>

<?php ob_start(); ?>
function updateSocialPreview() {
    const title = document.querySelector('input[name="nexeng_og_title"]').value || '<?php echo esc_js(get_the_title()); ?>';
    const desc = document.querySelector('textarea[name="nexeng_og_desc"]').value || '<?php echo esc_js(wp_trim_words(get_the_excerpt(), 20)); ?>';
    const image = document.getElementById('nexeng_og_image').value;

    document.getElementById('ncx-preview-title').innerText = title;
    document.getElementById('ncx-preview-desc').innerText = desc;
    if (image) {
        document.getElementById('ncx-preview-image').style.backgroundImage = `url('${image}')`;
        document.getElementById('ncx-preview-image').innerHTML = '';
    }
}

function ncxOpenMedia(targetId) {
    const frame = wp.media({
        title: 'Select Social Image',
        multiple: false,
        library: { type: 'image' }
    });
    frame.on('select', function() {
        const attachment = frame.state().get('selection').first().toJSON();
        document.getElementById(targetId).value = attachment.url;
        updateSocialPreview();
    });
    frame.open();
}

document.querySelectorAll('.ncx-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ncx-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.ncx-tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(`ncx-tab-${btn.dataset.tab}`).classList.add('active');
    });
});
<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
