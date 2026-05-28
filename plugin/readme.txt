=== llms.txt for WordPress ===
Contributors: xantusai
Tags: llms.txt, ai search, ai seo, llms, ai discoverability
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.8
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

== Changelog ==

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

= 0.1.8 =
User-facing plugin rename to "llms.txt for WordPress". Internal identifiers unchanged - no migration required.

= 0.1.7 =
Dashboard cleanup. Safe to upgrade.
