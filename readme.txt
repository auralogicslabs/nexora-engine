=== Nexora Engine ===
Contributors: auralogics
Tags: performance, cache, static-site, headless, security
Requires at least: 6.1
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise WordPress Infrastructure Platform. Static delivery, Ghost Protocol fingerprint cloaking, and intelligent automation — in one plugin.

== Description ==

Nexora Engine transforms WordPress into a modern infrastructure platform without requiring a separate Node.js server or CDN configuration. It delivers three core capabilities:

**Speed Infrastructure**
Pre-rendered HTML snapshots served from `advanced-cache.php` before WordPress boots — achieving 22ms TTFB on any shared host (Apache, Nginx, LiteSpeed, IIS). Zero PHP execution on cache hits.

**Ghost Protocol**
Strips every WordPress fingerprint from HTML, headers, and JavaScript. Generator meta tags, REST API discovery links, `window.wp`, and `X-Powered-By: PHP` are all removed or rewritten. Wappalyzer reports Nginx, not WordPress.

**Intelligent Automation**
Content changes trigger selective snapshot regeneration — only affected pages rebuild, with a 30-second debounce to coalesce bulk edits. Asset references are validated before serving.

= Free Features =
* Static HTML delivery with universal drop-in cache
* SPA client-side navigation between static pages
* Elementor & Gutenberg compatibility
* Delivery diagnostics (SSG status, serve-rule checker)
* Cache hit tracking and basic analytics
* Security hardening: REST user-enumeration block, author-enumeration block, XML-RPC disable, WordPress version removal, masked login errors
* Ghost Protocol WP masking: removes generator tags, REST discovery links and other WordPress signals from every response
* XML sitemap and the SEO & Metadata report

There is no page limit, no time limit, and no feature that stops working. Every
page on your site can be served statically on the free version, for as long as
you use it.

= Pro Features =
* Advanced Ghost Protocol: Stealth Proxy asset-path rewriting and JS namespace cloaking
* Smart automation: auto-rebuild on publish/update, debounced regeneration, conflict detection
* Scheduled regeneration cron
* Core Web Vitals tracking (LCP, INP, CLS) from real-user field data
* SEO intelligence and on-page scoring
* PDF infrastructure reports
* Edge CDN purge for Cloudflare and Bunny
* White-label admin branding
* Portal connectivity for auralogicslabs.com/portal
* Multisite fleet orchestration: network-wide SSG enable/disable and fleet dashboard

= Privacy & WP.org Compliance =
* Analytics are opt-in — disabled by default on new installs (see Settings → Neural Pulse)
* IP addresses are anonymized before storage (MD5 hash, non-reversible)
* No data is sent to external servers without explicit user action
* No obfuscated code. Full GPL source.
* Cleanup on deletion — removes the plugin's options, database tables, post and user meta, scheduled events, and the cache drop-in. The generated static files in /wp-content/uploads/nexora-static/ are kept, since they are your rendered content; delete them from Tools before removing the plugin if you want them gone.

== External services ==

This plugin's static-delivery features run entirely on your own server. To build the static cache it makes requests **to your own website** (loopback requests to your own pages) so it can capture their rendered HTML. Those are requests to your site, not to any third party.

Beyond that, this plugin contacts outside servers in the following cases, and only these:

**1. Freemius — licensing and updates**
Freemius provides the licence check, plugin update channel, and (if you choose to purchase) checkout.

The plugin starts in anonymous mode: no account is created, no opt-in prompt is shown, and no diagnostic data is sent. Every feature of the free version works in this state and you never have to connect anything.

Data is only shared if you choose to act:

* Activating a licence sends your site URL, WordPress and PHP version, plugin version and licence key, so the licence can be validated and updates delivered.
* Opting in to diagnostics from the Account screen additionally shares your email address and a list of active plugins and themes. This is entirely optional and can be revoked from the same screen.

Contact with Freemius happens when you activate a licence, when the plugin checks for an update to a licensed copy, and when you open the licensing screens.
Service provided by Freemius, Inc. — terms of service: https://freemius.com/terms/ — privacy policy: https://freemius.com/privacy/

**2. Broken-link checking — the sites you link to**
When you run a link scan, the plugin sends an HTTP HEAD request to each external URL found in your own posts and pages, to see whether the link still resolves. Only the URL is requested; no data about you or your visitors is transmitted. The destinations are entirely determined by the links in your content — this plugin does not choose them, and the scan only runs when you start it.

**3. Revalidation webhook (optional, off by default)**
If — and only if — you enter a webhook URL in the plugin's settings (for example to tell a headless front end that a page changed), the plugin sends a POST request to that URL when a post is updated. It contains the changed post's ID and URL plus a signature generated from a secret you supply. Nothing is sent until you configure this URL, and the destination is entirely your choice — this plugin does not provide or control it.

This plugin does not phone home, send analytics, or transmit any data to Auralogics Labs servers.

Note: features that integrate with Google Search Console or with CDN providers (Cloudflare, BunnyCDN) are **not part of this plugin**. They are not included in this download and no code for them ships here.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/nexora-engine/`.
2. Activate **Nexora Engine** through the Plugins menu in WordPress.
3. The Setup Wizard will launch automatically on first activation.
4. Follow the wizard: enable Static Delivery, install the drop-in cache, and verify the setup.

== Screenshots ==

1. Dashboard — cache hit ratio, real time-to-first-byte, Core Web Vitals, static file count, mirror freshness, and the live Stealth Score at a glance.
2. Static Delivery — the core engine: per-page capture status, delivery mode, and the Mirror Build Control panel with live build progress.
3. Security — the Stealth Score breakdown showing exactly which WordPress fingerprints are hidden, plus the free hardening guards.
4. Tools — system status, rewrite-rule flush, configuration export/import, and a guided factory reset.
5. Setup Wizard — one-click guided setup that verifies compatibility, enables static delivery, builds the first mirror, and confirms serving.

== Frequently Asked Questions ==

= Does this replace my theme? =
No. Nexora Engine sits on top of WordPress. Your theme, Elementor layouts, Gutenberg blocks, and page builders are unchanged — the plugin just captures their output as static HTML.

= Do I need a separate Node.js server? =
No. Static files are served directly from `wp-content/uploads/nexora-static/` via `advanced-cache.php` or Apache/Nginx rewrites. No external server required.

= Is it compatible with Elementor, Divi, and WPBakery? =
Yes. Nexora Engine captures the rendered HTML output after page builders run. Editor experience is completely unchanged.

= When I edit a page, post, category, or tag, does the static mirror update automatically? =
On the free version, any change to a page, post, or public category/tag is tracked and the affected pages are added to a Pending list — you rebuild the mirror with one click from the Static Delivery screen (or the "Build pending" button), keeping you fully in control of when the static site refreshes. The Pro version adds automatic rebuild on publish/update, so every change is mirrored in the background with no manual step.

= Is the free version limited in how many pages it can serve? =
No. The free version has no page cap, no time limit, and no trial period. Every
page on your site can be served statically, indefinitely.

What Pro adds is automation and scale: rebuilds happen by themselves on publish,
asset paths are cloaked, metadata is editable per page, and multisite networks
are managed centrally. A small site is well served by the free version for as
long as it stays small; a site that publishes daily is where the automation
starts paying for itself.

If a limit is ever introduced on the free tier, it will be stated in this readme
before it ships — never discovered by running into it.

= What happens if I deactivate the plugin? =
On deactivation, the `advanced-cache.php` drop-in is removed and all WordPress rewrite rules are flushed. Your site returns to normal PHP rendering instantly.

== Source code & development ==

Nexora Engine is fully open. The admin interface is a React + TypeScript application; the human-readable source lives in the `frontend/` directory that ships inside the plugin, and it is compiled with Vite to `assets/dist/nexora-engine.js`. Nothing is obfuscated or minified beyond a standard production build.

The complete, unminified source — including the build tooling and instructions — is maintained publicly at:

https://github.com/auralogicslabs/nexora-engine

To build the compiled assets from source:

1. `npm install` — install the build dependencies.
2. `npm run build` — compile `frontend/` to `assets/dist/nexora-engine.js` with Vite.

The PHP is shipped as-is (no build step) and is directly readable in the `includes/`, `app/`, and `admin/` directories.

== Changelog ==

= 1.0.0 =
First public release of Nexora Engine.
* Static HTML delivery: pre-rendered page snapshots served from a universal `advanced-cache.php` drop-in before WordPress boots, for fast time-to-first-byte on any host (Apache, Nginx, LiteSpeed, IIS).
* SPA-style client navigation between static pages, with full Elementor and Gutenberg compatibility — your theme and page builders are captured exactly as rendered.
* Setup Wizard: one-click guided setup that enables static delivery, installs the drop-in, builds the first mirror, and verifies serving.
* Mirror Build Control: build, pause, resume, and refresh changed pages, with live progress and clear per-page error reporting.
* Delivery diagnostics: SSG status, serve-rule checker, and a self-test so you can confirm static delivery is active.
* Ghost Protocol WP masking: removes WordPress fingerprints — generator meta tags, version strings, and REST API discovery links — from every response, not just the static output.
* Security hardening (free): block REST and URL user enumeration, disable XML-RPC, remove the WordPress version string, and basic login protection.
* Cache hit tracking and basic delivery analytics (opt-in, IP-anonymized).
* WooCommerce-aware: cart, checkout, and account pages are automatically excluded from static capture.
* Optional revalidation webhook to notify an external host/front end when a page changes.
* Deleting the plugin removes the drop-in, flushes rewrite rules, and cleans up all plugin data, options, and tables.

The Pro version adds Stealth Proxy asset cloaking, smart auto-rebuild on publish, per-page SEO metadata editing, advanced security guards, Core Web Vitals tracking, infrastructure reports, the Redirect Manager, scheduled regeneration, portal connectivity, and multisite fleet orchestration. See the Description above.

The Pro code is not part of this download. Those features live in separate files
that are removed from the WordPress.org build, so nothing here is present-but-
disabled — what ships is what runs.

== Upgrade Notice ==

= 1.0.0 =
First public release. Run the Setup Wizard after activation to enable static delivery and build your first mirror.
