=== llms.txt for WordPress ===
Contributors: xantusai
Tags: llms.txt, ai search, ai seo, llms, ai discoverability
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.14
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-generate and maintain llms.txt for your WordPress site. Audit content for AI-search readiness and serve per-page markdown variants.

== Description ==

AI-powered search (ChatGPT, Claude, Perplexity, Google AI Overviews) is rapidly becoming a primary discovery channel. This plugin makes your WordPress site discoverable by AI by generating and maintaining a curated `llms.txt` file.

`llms.txt` is an emerging web standard (see llmstxt.org) that provides a markdown-formatted index of a website's most important content, designed for consumption by large language models. Over 844,000 websites have adopted it, including Anthropic, Cloudflare, and Stripe.

**What it does:**

* Auto-generates `llms.txt` and `llms-full.txt` from your WordPress content
* Per-page `.md` endpoints serve markdown variants of any post or page
* Setup wizard detects your site's content structure and proposes sections
* Curation UI: organize posts into named sections, override titles and descriptions, set sort order
* Search-and-select post picker: find content by title instead of hunting for post IDs
* 12-rule quality auditor flags missing H1s, thin meta descriptions, duplicate titles, generic headings, boilerplate intros, stale content, and more
* `robots.txt` integration adds llms.txt and missing sitemap references
* `<link rel="llms">` injection in `<head>` for AI-bot discoverability
* WP Engine / Kinsta support: auto-detects managed hosts that block dynamic `.txt` serving and writes physical files instead
* Instant updates: section edits, settings changes, and post saves immediately regenerate the file

**How it works:**

1. On activation, a setup wizard scans your site and suggests an initial section structure
2. On every post save, the cache is invalidated and the file is regenerated
3. Daily via cron, a full-site audit runs and both `llms.txt` and `llms-full.txt` are refreshed
4. On demand, you can edit sections, reorder content, and trigger manual regeneration

The description for each entry is resolved through a 10-priority chain: manual override, Yoast/RankMath/AIOSEO/SEOPress meta description, post excerpt, first sentence of content — each passing through quality gates that reject boilerplate, sales pitches, and too-short text.

**Source code:** [github.com/xantus-ai/wp-llms-plugin](https://github.com/xantus-ai/wp-llms-plugin)

== Installation ==

1. Upload the plugin via **Plugins → Add New → Upload Plugin**, or copy the folder to `/wp-content/plugins/llms-txt`
2. Activate through the WordPress **Plugins** menu
3. Open **llms.txt** in the admin sidebar and run the setup wizard
4. Visit `/llms.txt` on your site to verify the file is generated

== Frequently Asked Questions ==

= What is llms.txt? =

llms.txt is an emerging web specification (llmstxt.org) that provides a curated, markdown-formatted index of a website's most important content, purpose-built for consumption by large language models.

= Does this require an API key or account? =

No. The plugin is fully functional with no external account required. Everything runs locally on your WordPress site.

= Will this conflict with Yoast SEO, RankMath, AIOSEO, or SEOPress? =

No. The plugin reads from these SEO plugins (using their meta descriptions when set) and complements them. They focus on traditional search; this plugin focuses on AI-search.

= Does it work with page builders like Elementor, Divi, or Beaver Builder? =

Yes. The content extraction pipeline handles Gutenberg, Elementor, Divi, Beaver Builder, the classic editor, and sites with no builder at all.

= My host (WP Engine / Kinsta) returns 404 for /llms.txt. Why? =

Some managed hosts have nginx rules that intercept `.txt` URLs before they reach WordPress, so dynamic rewrite rules never fire. The plugin auto-detects these hosts and writes a physical `llms.txt` file to the document root so it can be served directly.

= How do I remove all plugin data on uninstall? =

In **llms.txt → Settings**, enable "Remove all plugin data when the plugin is uninstalled" before deleting the plugin from the Plugins page.

== Screenshots ==

1. Dashboard — see your llms.txt status, audit summary, and serving mode at a glance.
2. Sections list — organize content into named groups that map to llms.txt sections.
3. Search-and-select post picker — find content by title instead of hunting for post IDs.
4. Audit page — 12 quality rules flag missing H1s, thin meta descriptions, generic headings, and more.
5. Setup wizard — detects your site's content structure and proposes a starting llms.txt configuration.
6. The generated /llms.txt file, served at your site root.
7. Settings — configure brand voice, integrations, audit threshold, and uninstall behavior.

== Changelog ==

= 0.1.14 =
* Changed: `missing_h1` is now opt-in for every post type. The H1 on a rendered page can come from many sources the audit can't see from inside the admin (theme templates, Elementor Theme Builder, custom hooks), so the rule defaulted to false positives on real sites. Enable it for specific post types via the `wpllms_missing_h1_force_check_post_types` filter.

= 0.1.13 =
* Fixed: `missing_h1` rule no longer flags custom post types whose theme template renders the title as `<h1>` (Videos, Member Reviews, custom CPTs, etc.). Default behavior inverted: non-builder posts skip the in-content H1 check.
* Fixed: Rendered-content caches are now versioned, so plugin upgrades that change the rendered output (e.g., v0.1.12 ACF append) take effect on already-cached unmodified posts.
* Added: `wpllms_missing_h1_force_check_post_types` filter for opting specific post types back into the in-content H1 check.

= 0.1.12 =
* Fixed: ACF content is now read by both the audit and the llms.txt generator. Custom post types whose content lives in ACF fields (Courses, Topics, etc.) no longer get false-positive audit issues and no longer produce empty llms.txt entries or `.md` endpoints.
* Added: `wpllms_acf_content_excluded_fields` filter to exclude specific field names from the audit and llms.txt output.

= 0.1.11 =
* Fixed: `missing_h1` rule no longer flags WooCommerce product pages where the product title is rendered as `<h1>` by WooCommerce's template.
* Added: `wpllms_missing_h1_template_post_types` filter for registering custom post types whose templates render the title as H1.

= 0.1.10 =
* Fixed: Full-site audit no longer times out (502) on builder-heavy sites. The audit now runs in chunks with a per-call time budget, persisting progress between requests. The audit page shows progress and a Continue button until the run is complete.
* Added: Shared rendered-content cache so the same Elementor render isn't repeated across 12 rules. Repeat audits are dramatically faster.
* Changed: Cron's daily tick uses the chunked audit too — if a tick can't finish, a single follow-up tick is scheduled 5 minutes later so progress doesn't sit idle until tomorrow.

= 0.1.9 =
* Fixed: Audit rules now read Elementor-rendered content correctly. The `missing_h1` rule no longer reports false positives on Elementor posts after the user has added an H1 widget. Also fixes stale H1 frequency data in the `generic_h1` rule and ensures the llms.txt generator picks up Elementor widget content even when `post_content` is non-empty.

= 0.1.8 =
* Changed: Renamed the plugin to "llms.txt for WordPress" (WP.org slug `llms-txt`). Surface-level only — internal identifiers, DB tables, and option keys are unchanged.

= 0.1.7 =
* Removed: License tier row from the dashboard (no paid tier in this build).

= 0.1.6 =
* Fixed: Physical llms.txt now always rewritten when the file already exists on disk, even if managed-host detection fails in admin-post context.

= 0.1.5 =
* Fixed: Post search dropdown now appears reliably when typing in the section editor.

= 0.1.4 =
* Maintenance release.

= 0.1.3 =
* Changed: Section edit page replaces the manual post-ID textarea with a search-by-title picker.
* Changed: Audit page post links now open in a new tab.
* Fixed: Section edits, section deletes, and settings changes now immediately rewrite the physical llms.txt file on WP Engine/Kinsta.

= 0.1.2 =
* Added: WP Engine and Kinsta support via physical-file mode.
* Added: HostDetector, PhysicalFileWriter, host-aware serving mode setting.
* Added: Per-page `.md` endpoints for every post and page.
* Added: Setup wizard, dashboard, audit page, sections list and editor, settings.
* Added: Generator engine (extractor, description resolver, title resolver, llms.txt + llms-full.txt assembly).
* Added: 12-rule audit engine with per-post and full-site modes.
* Fixed: Activation flush now correctly registers rewrite rules so /llms.txt resolves immediately.

= 0.1.0 =
* Initial scaffold release.

== Upgrade Notice ==

= 0.1.14 =
`missing_h1` is now opt-in. Re-run the audit after upgrading to clear stale findings.

= 0.1.13 =
Fixes missing-H1 false positives on most custom post types and invalidates stale render caches from earlier versions. Re-run the audit after upgrading.

= 0.1.12 =
ACF field content is now included in the audit and the llms.txt output. Re-run the audit and re-save any ACF-driven posts after upgrading.

= 0.1.11 =
Fixes missing-H1 false positives on WooCommerce product pages. Re-run the audit after upgrading.

= 0.1.10 =
Fixes the 502 timeout when running the full-site audit on builder-heavy sites. The audit now chunks itself across multiple requests. Click Continue if the audit pauses.

= 0.1.9 =
Fixes false positives in the audit for Elementor posts. Re-run the audit after upgrading.

= 0.1.8 =
User-facing plugin rename to "llms.txt for WordPress". Internal identifiers unchanged - no migration required.

= 0.1.7 =
Dashboard cleanup. Safe to upgrade.
