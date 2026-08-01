<?php
/**
 * Nexora Engine — Issue Library
 * Contains plain-english definitions for all audit issues.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NEXENG_Issue_Library {
    public static function get( string $issue_key ): array {
        $library = [

            'nexeng_missing_h1' => [
                'what' => 'This page has no main heading.',
                'why'  => 'Search engines use the H1 heading to understand what your page is about. Without it, your page is much harder to rank in Google.',
                'fix'  => "1. Open this page in your editor\n2. Add a Heading block at the top\n3. Set the heading level to H1\n4. Write your page title as the heading\n5. Save the page",
                'severity' => 'high',
                'module'   => 'SEO',
            ],

            'nexeng_multiple_h1' => [
                'what' => 'This page has more than one H1 heading.',
                'why'  => 'Each page should have exactly one H1. Multiple H1s confuse search engines about what the page is actually about.',
                'fix'  => "Keep only one H1 — your main page title. Change all other large headings to H2 or H3.",
                'severity' => 'medium',
                'module'   => 'SEO',
            ],

            'nexeng_missing_meta_desc' => [
                'what' => 'This page has no meta description.',
                'why'  => 'Google shows this text under your page title in search results. Without it, Google picks random text from your page which often looks unprofessional.',
                'fix'  => "1. Open this page in your editor\n2. Scroll to the SEO panel (Yoast or RankMath)\n3. Click Edit snippet\n4. Write 1-2 sentences (120-160 characters)\n5. Save the page",
                'severity' => 'high',
                'module'   => 'SEO',
            ],

            'nexeng_short_meta_desc' => [
                'what' => 'Your meta description is too short (under 120 characters).',
                'why'  => 'A short description does not give Google enough context and wastes valuable space in search results.',
                'fix'  => 'Edit your meta description and expand it to 120-160 characters. Describe what the page offers.',
                'severity' => 'low',
                'module'   => 'SEO',
            ],

            'nexeng_thin_content' => [
                'what' => 'This page has very little content (under 300 words).',
                'why'  => 'Google tends to rank pages with thin content lower because they provide less value to readers.',
                'fix'  => 'Add more useful content to this page. Aim for at least 300 words. Answer questions your visitors likely have.',
                'severity' => 'medium',
                'module'   => 'SEO',
            ],

            'nexeng_images_missing_alt' => [
                'what' => 'Some images on this page have no ALT text.',
                'why'  => 'ALT text tells search engines what an image shows. Missing ALT text hurts your SEO and accessibility.',
                'fix'  => "1. Click the image in your editor\n2. Find the Alt text field in the sidebar\n3. Describe what the image shows\n4. Save the page",
                'severity' => 'medium',
                'module'   => 'SEO',
            ],

            'nexeng_large_image' => [
                'what' => 'One or more images on this page are too large.',
                'why'  => 'Large images slow down your page. Slow pages lose visitors and rank lower in Google.',
                'fix'  => "Compress your images before uploading. Aim for under 200KB per image. Use Squoosh.app or a plugin like Smush.",
                'severity' => 'high',
                'module'   => 'Performance',
            ],

            'nexeng_xmlrpc_enabled' => [
                'what' => 'The XML-RPC feature of WordPress is accessible.',
                'why'  => 'Hackers use XML-RPC to attempt thousands of password guesses per request. Most modern sites do not need it.',
                'fix'  => "Install the 'Disable XML-RPC' plugin or block access in your .htaccess file.",
                'severity' => 'high',
                'module'   => 'Security',
            ],

            'nexeng_user_enum_exposed' => [
                'what' => 'Your WordPress usernames are publicly visible.',
                'why'  => 'Anyone can see your admin username via a public URL, making brute-force attacks much easier.',
                'fix'  => "Add a filter to your theme functions.php to disable the user REST endpoint.",
                'severity' => 'critical',
                'module'   => 'Security',
            ],

            'nexeng_orphan_page' => [
                'what' => 'No other pages on your site link to this page.',
                'why'  => 'Orphan pages are hard for Google to find. They also get no authority passed from other pages.',
                'fix'  => 'Find 2-3 related pages on your site and add a link to this page within their content.',
                'severity' => 'medium',
                'module'   => 'Links',
            ],

            'nexeng_broken_link' => [
                'what' => 'This page has a link that leads to a 404 error.',
                'why'  => 'Broken links frustrate visitors and signal to Google that your site is poorly maintained.',
                'fix'  => "Find the broken link in your editor and either update the URL or remove the link.",
                'severity' => 'high',
                'module'   => 'Links',
            ],

        ];

        return $library[ $issue_key ] ?? [
            'what' => 'An issue was detected on this page.',
            'why'  => 'This issue may affect your site performance or search ranking.',
            'fix'  => 'Review the page details and take appropriate action.',
            'severity' => 'medium',
            'module'   => 'General',
        ];
    }
}
