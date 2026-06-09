# Changelog

All notable changes to the llms.txt for WordPress plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.18] - 2026-06-09

### Added
- **CSV export on the audit page.** Button next to "Run full-site audit now". Exports all currently-visible issues (respects the active severity / rule filters) as a UTF-8 CSV with headers `Severity, Rule, Post ID, Post title, Post URL, Message, Suggested fix, Detected at`. UTF-8 BOM is written so Excel opens the file in the correct encoding. Filename includes the active filters and date for easy archiving (`llms-txt-audit-warning-thin_content-2026-06-09.csv`). Backed by new `IssuesRepository::unresolved_filtered_all()` and the `wpllms_export_audit` admin-post action.

### Fixed
- **Audit no longer shows issues for non-published posts.** Two paths into this state:
  - When a previously-published post transitioned to draft / pending / trash, `Plugin::on_save_post()` skipped the audit (correct) but didn't clear the post's existing issue rows (wrong) — so the table kept showing stale findings for content that was no longer indexed. Now clears issues on transition away from publish.
  - Bulk edits, REST API writes, and any other path that doesn't go through `save_post` could leave issue rows for posts that quietly changed status. The audit page queries (`unresolved_filtered`, `unresolved_count_filtered`, `unresolved_filtered_all`, `distinct_unresolved_rules`, `counts_by_severity`) now JOIN against `wp_posts` and filter on `post_status = 'publish'`, so non-published posts are hidden regardless of how their status changed.

### Notes
- The dashboard's severity counts and the audit page's tab counts now reflect "open issues on currently-published posts" rather than "rows in the table." For most sites this means counts go down on upgrade — that's the bug fix, not a regression.

## [0.1.17] - 2026-06-09

### Fixed
- Sections with intro text but no posts now render. Both `LlmsTxtGenerator::render_section()` and `LlmsFullTxtGenerator::render_section()` were short-circuiting on zero entries and returning an empty string, discarding the section's H2 heading and its intro text along with the (non-existent) link list. Use case: a standalone descriptive section like "Areas of Expertise" where the intro text *is* the value — a bullet list, a paragraph of context — and there are no per-post entries to link to. Same fix applied to `render_optional_block()` sub-sections under the "## Optional" wrapper.
- The new rule: a section renders if it has either intro text or resolvable entries (or both). A section is skipped only when both are empty.

## [0.1.16] - 2026-06-09

### Fixed
- **Elementor CSS no longer leaks into llms.txt descriptions, llms-full.txt content, or per-page `.md` endpoints.** Elementor's `get_builder_content_for_display()` embeds per-post inline `<style>` blocks containing element selectors (e.g., `.elementor-101102 .elementor-element-f59338d > .elementor-container { max-width: 1003px; }`) directly in the rendered HTML. The generator's `wp_kses()` step stripped the `<style>` tags but preserved their text content, so the CSS rules survived as raw text and ended up as the fallback "first sentence of content" description for any post without a meta description or excerpt. Result: ~60+ entries on builder-heavy sites had selectors-and-declarations as their llms.txt description. Latent since v0.1.9 (which switched the Extractor to always render Elementor for builder posts); only visible to sites with meaningful Elementor adoption + missing/empty SEO meta descriptions.

### Changed
- `Generator\Extractor::stage_3_sanitize()` now strips `<style>` and `<script>` blocks completely (tag + contents) before `wp_kses` runs. Uses the same regex `wp_strip_all_tags()` uses internally. Caught by sanitize stage, so it covers anything stages 1–2 might surface — Elementor renders, ACF wysiwyg with embedded styles, the_content filter additions, etc.
- Bumped `Generator\Extractor::CACHE_VERSION` from 2 to 3 so unmodified posts pick up the new clean output on the next read.

### Notes
- `Audit\RenderedContentCache` does not have the same bug — the audit's downstream rules use `wp_strip_all_tags()` (which does strip style/script content) for word counts and text inspection, so audit results were already clean.

## [0.1.15] - 2026-06-09

### Changed
- Audit page issues table is now paginated and filterable. Sites with thousands of open issues were unreviewable — the previous render fetched a flat list of 200 with no way to drill into a specific severity or rule. The new UI adds:
  - **Severity filter tabs** (All / Critical / Warning / Info) using the WP-standard `subsubsub` list pattern, each with a count.
  - **Rule filter dropdown** populated dynamically from distinct rules with open findings; auto-submits on change with a noscript fallback.
  - **50 issues per page** with WordPress's `paginate_links()` UI rendered above and below the table.
- The standalone severity counts table is removed — the tabs at the top now serve that role and are also the navigation control.

### Added
- `Audit\IssuesRepository::unresolved_filtered($severity, $rule, $limit, $offset)` — paginated/filtered query used by the audit page.
- `Audit\IssuesRepository::unresolved_count_filtered($severity, $rule)` — pagination total for the same filter set.
- `Audit\IssuesRepository::distinct_unresolved_rules()` — distinct rule keys with open findings, used to populate the rule dropdown.

## [0.1.14] - 2026-06-08

### Changed
- `missing_h1` is now opt-in. The default skip set is **every post type**, including Elementor-built posts. After v0.1.13 inverted the default for non-builder post types, false positives kept surfacing on Elementor pages where the H1 is rendered by Elementor Theme Builder's single-page templates, Theme Builder headers, or other sources outside the post's own widget content. The audit only sees the post's own builder content via `get_builder_content_for_display()`, which is a strict subset of the rendered front-end HTML — there is no way to reliably detect a rendered H1 from inside the WordPress admin without fetching the front-end URL. Rather than continue chasing false positives, the rule now skips by default and is opt-in per post type via the existing `wpllms_missing_h1_force_check_post_types` filter. Set the filter to a list of post types whose templates you know will not render the title (custom landing-page CPTs, etc.) to enforce the in-content H1 check there.

### Notes
- Existing `missing_h1` issues persist in the database until the next audit run clears them. Re-run the audit after upgrading to clean them out (the audit's per-post `clear_for_post()` step handles this automatically).
- A future release may add an opt-in "accurate mode" that fetches the front-end URL for each post and parses the actual rendered HTML for the H1. This is how professional SEO audit tools handle the problem and is the only way to know for certain. Not in scope for this release.

## [0.1.13] - 2026-06-08

### Fixed
- `missing_h1` rule no longer reports false positives on custom post types whose templates render the title as `<h1>` (Videos, Member Reviews, Topics, Courses — basically any CPT using WordPress's normal single-{type}.php template hierarchy). The previous default was to check every non-builder post type for an in-content H1, which was the wrong shape — most CPTs follow the post/page/product pattern where the H1 comes from the template, not the content. The default is now inverted: skip the in-content H1 check for non-builder posts, and let site owners opt specific types in via `wpllms_missing_h1_force_check_post_types` (default: `[]`). Builder-driven posts (Elementor, etc.) are always checked because the builder owns the rendered output.
- **Stale rendered-content caches no longer survive plugin upgrades.** Both `Audit\RenderedContentCache` and `Generator\Extractor` keyed their transients only on `post_modified_gmt`, so after upgrading from a version that produced different rendered output (e.g., v0.1.11 → v0.1.12 added ACF content), unmodified posts still hit the pre-upgrade cache and the new code's behavior was invisible. Added a `CACHE_VERSION` constant baked into both cache keys; bumping it on any output-shape change forces fresh renders on next read. Bumped both to v2 for this release.

### Removed
- `wpllms_missing_h1_template_post_types` filter (from v0.1.11). Now a no-op because the default skip set is "every non-builder post type." Replaced by the inverse-shape `wpllms_missing_h1_force_check_post_types` filter for opting specific types back into the check.

### Added
- `wpllms_missing_h1_force_check_post_types` filter for site owners who want the in-content H1 check enforced on specific custom post types (e.g., custom landing-page types whose templates intentionally don't render the title). Default: `[]`.

## [0.1.12] - 2026-06-08

### Fixed
- ACF (Advanced Custom Fields) content is now read by both the audit and the llms.txt generator. For ACF-heavy custom post types (Courses, Topics, Member Reviews, etc.) the "real" content lives in custom fields rather than `post_content`, so the audit was reporting `missing_h1`, `thin_content`, and `boilerplate_intro` false positives across every such post, and the generator was producing empty descriptions and empty `.md` endpoints. Now both pipelines append ACF field values (text, textarea, wysiwyg, plus recursion into repeaters, groups, and flexible content) to the rendered HTML.

### Added
- `Content\AcfContent::for_post(int)` — shared helper used by `Audit\RenderedContentCache` and `Generator\Extractor`. Calls ACF's `get_fields()` and recursively concatenates string values; non-string leaves (image IDs, file metadata arrays, taxonomy term arrays) are skipped.
- `wpllms_acf_content_excluded_fields` filter for site owners who want to keep specific field names (`internal_notes`, `admin_only_flag`, etc.) out of the audit and the llms.txt output. Default: `[]`.

### Notes
- For posts that use an Elementor template referencing ACF dynamic tags, the same content is now both inside the Elementor render and appended again from `AcfContent::for_post()`. This can produce false positives in `multiple_h1` for that specific combination — the excluded-fields filter is the escape hatch.
- The cache key for both pipelines is still `post_modified_gmt`, so ACF field updates via the editor invalidate the cache (save_post bumps post_modified). Programmatic `update_field()` calls don't bump post_modified, so explicit cache flush or post re-save is required after those.

## [0.1.11] - 2026-06-08

### Fixed
- `missing_h1` rule no longer reports false positives on WooCommerce product pages. WooCommerce's `single-product/title.php` template renders `<h1 class="product_title">` from `the_title()`, so the H1 lives outside `post_content` — same structural issue the rule already handled for blog posts. The `product` post type is now in the default skip list alongside `post`. Builder-driven products (Elementor templates) still have their content checked.

### Added
- `wpllms_missing_h1_template_post_types` filter so site owners can register custom post types whose templates render the title as H1 (default: `['post', 'product']`).

## [0.1.10] - 2026-06-08

### Fixed
- **Full-site audit no longer times out (502 Bad Gateway) on builder-heavy sites.** v0.1.9 made the audit always render Elementor content for accuracy, but on sites with many Elementor pages the cumulative `get_builder_content_for_display()` cost exceeded PHP-FPM's request timeout, returning 502 from Cloudflare/origin.

### Changed
- **Audit now runs in chunks with a per-call time budget.** `Auditor::audit_all()` accepts a `$max_seconds` argument (20s from the admin UI, 60s from cron) and persists progress in the `wpllms_audit_progress` option. Each request processes posts until the budget is exhausted, then returns; the next request resumes from where it left off. The audit page UI shows progress (X of Y posts, current phase) and a Continue/Cancel pair of buttons.
- Audit now runs in two phases. Phase 1 runs post-local rules (10 of 12) on each eligible post. Phase 2 runs site-context rules (`generic_h1`, `duplicate_title`) once, with `AuditContext` built cheaply from the cache warmed in phase 1. Both phases respect the time budget and resume across requests.
- Cron's daily tick now uses the chunked audit too. If a tick can't complete the audit, a one-time follow-up tick is scheduled 5 minutes later via `wpllms_audit_resume` so progress doesn't sit idle until the next day.

### Added
- `Audit\RenderedContentCache` — shared transient cache (keyed on `post_modified_gmt`) for rendered post HTML. Used by both `Audit\Rules\AbstractRule::rendered_content()` and `Audit\AuditContext::extract_h1()` so the same render isn't repeated across 12 rules × N posts. Cache hits dramatically reduce the cost of repeat audits.
- `Audit\IssuesRepository::clear_rule(string $rule)` — removes all unresolved issues for a single rule, used between audit phases to avoid stale site-context findings.
- `Audit\Auditor::get_progress()` and `clear_progress()` — public helpers for UI and tests.

## [0.1.9] - 2026-06-08

### Fixed
- Audit rules now correctly read Elementor-rendered content. Previously the audit fell back to Elementor only when `post_content` was empty, which is rarely the case in practice — Elementor stores its design in the `_elementor_data` meta but most posts have stub or migrated content in `post_content`. Result: the `missing_h1` rule (and any other rule that inspects rendered HTML) reported false positives on Elementor posts and stayed dirty even after the user added an H1 widget in Elementor. Fixed in `Audit\Rules\AbstractRule::rendered_content()`, `Audit\AuditContext::extract_h1()`, and `Generator\Extractor::stage_1_resolve_source()` — for Elementor posts, the renderer now always uses `Elementor\Frontend::get_builder_content_for_display()` first and only falls back to filtered `post_content` if the Elementor frontend is unavailable.

## [0.1.8] - 2026-05-28

### Changed
- Renamed the user-facing plugin to **llms.txt for WordPress** (WP.org slug `llms-txt`, text domain `llms-txt`). The WordPress trademark policy prohibits plugin names starting with "WP", so the previous name "WP LLMS" would have been rejected at WP.org review. Main PHP file renamed to `llms-txt.php`, build artifact renamed to `llms-txt.zip`, dashboard menu label is now "llms.txt".
- Internal PHP namespace (`WPLlms\`), constants (`WPLLMS_*`), hook prefixes (`wpllms_`), DB table names, and option keys are unchanged — this is a surface-level rename only, no migration required.
- `readme.txt` rewritten for the WordPress.org listing: synced changelog from CHANGELOG.md, removed stale references to a paid tier, updated description and FAQ.

## [0.1.7] - 2026-05-28

### Removed
- "License tier" row on the dashboard. No paid tier exists in the open-source build, so the row promised an upgrade path that wasn't there.

## [0.1.6] - 2026-05-27

### Fixed
- Physical llms.txt now always rewritten when the file already exists on disk, even if host detection fails. Prevents stale files when WP Engine detection doesn't trigger in admin-post context.

## [0.1.5] - 2026-05-27

### Fixed
- Post search dropdown now appears when typing. CSS `display:none` was overriding the JS visibility toggle.

## [0.1.4] - 2026-05-27

## [0.1.3] - 2026-05-27

### Changed
- Section edit page: replaced the manual post-ID textarea with a search-by-title picker. Type to search, click to add, remove individually. Existing selections are resolved to titles on load.
- Audit page: post links now open in a new tab.
- Build script: syntax check no longer false-positives on opcache.so warnings.

### Fixed
- Section edits, section deletes, and settings changes now immediately rewrite the physical llms.txt file on WP Engine/Kinsta. Previously only post saves triggered the rewrite, so admin changes were invisible until the next post save or daily cron.

## [0.1.2] - 2026-05-11

### Fixed
- **`/llms.txt` now resolves on WP Engine and Kinsta.** These hosts have nginx rules that intercept `.txt` URLs before they reach PHP, so WordPress rewrite rules never fire and the file 404s. Fix: write a physical `llms.txt` to the document root, so nginx serves it directly.

### Added
- `Frontend\HostDetector` - identifies WP Engine, Kinsta, Pantheon. Exposes `blocks_dynamic_txt()` which returns true for hosts known to intercept `.txt` URLs.
- `Frontend\PhysicalFileWriter` - writes `llms.txt` and `llms-full.txt` to the WordPress document root via atomic temp-file + rename. Returns structured error results on permission failures.
- Setting `serve_via_static_file` (tri-state: `null` = auto-detect by host, `true`/`false` = explicit override). When null and host blocks dynamic .txt, physical mode is enabled.
- Eager file writes wired into the lifecycle: setup wizard finalize writes immediately, `save_post` rewrites llms.txt (skips full version for performance), daily cron writes both, deactivation/uninstall delete the files so nothing stale is left behind.
- Dashboard now shows: serving mode (Static vs. Dynamic), file existence with size and age, host name when auto-detected, and a "Write llms.txt to disk" recovery button when the host needs it but no file exists yet. Warns clearly when document root is not writable.

### Fixed
- **Activation flush no longer misses our rewrite rules.** `register_activation_hook` fires after `init` (without our plugin loaded), so the `add_rewrite_rule()` calls in `FileServer::register_rewrite_rules()` weren't in the active set when `Activator::activate()` ran `flush_rewrite_rules()`. The cache rebuilt without our rules, leaving `/llms.txt` returning 404 until something else triggered a flush. Fixed by explicitly calling `register_rewrite_rules()` from the activator and the wizard finalize step before flushing.
- Dashboard now surfaces a recovery path when rules are missing: detects whether the `^llms\.txt$` rule is in the cached rewrite-rule set and shows a "Refresh permalinks" button that re-registers and re-flushes. Also detects "Plain" permalink structure (which breaks all rewrites) and links to Settings → Permalinks.
- Dashboard's llms.txt URL row now shows green checkmark when the rewrite rule is active, red cross with recovery hint when missing.

### Added

**Plugin scaffold**
- Bootstrap file (`wp-llms.php`) with PHP/WP version guards and PSR-4 autoloader fallback
- Composer config with league/html-to-markdown and dev tools
- Plugin singleton (`WP LLMS\Plugin`)
- Activator and Deactivator with rewrite-rule flushing
- Database schema (`Storage\Schema`) with four tables: `sections`, `overrides`, `audit_issues`, `bot_hits`
- Options wrapper (`Storage\Options`) with default settings and license stub
- Cron scheduler (`Cron\Scheduler`) registering daily regeneration tick
- Admin menu (`Admin\Menu`) with placeholder pages for Dashboard, Sections, Audit, Settings, License
- Uninstall handler honoring `remove_data_on_uninstall` setting
- WordPress.org `readme.txt`

**Generator engine**
- `Storage\SectionsRepository` and `Storage\OverridesRepository` for DB access
- `Generator\Extractor` - 6-stage content extraction pipeline (Elementor render → the_content → wp_kses → DOMDocument cleanup → markdown → post-process), with transient caching keyed on post-modified timestamp
- `Generator\DescriptionResolver` - 8-priority resolution chain with quality gates
- `Generator\TitleResolver` - manual override → Yoast templated → post title, with site-name suffix stripping
- `Generator\SectionResolver` - resolves manual / post_type / taxonomy / query inclusion rules
- `Generator\LlmsTxtGenerator` - assembles full file per spec §2, §6, §7
- `Frontend\FileServer` - serves `/llms.txt` and `/llms-full.txt` via WordPress rewrite rules
- `Frontend\HeadInjector` - injects `<link rel="llms">` into `<head>` (gated by setting)
- `Frontend\RobotsTxt` - appends llms.txt + sitemap reference to robots.txt (gated by setting)
- `Plugin` singleton invalidates file-server cache on `save_post` and `deleted_post`

**Audit engine**
- `Audit\AuditIssue` value object + `Audit\IssuesRepository` for persistence
- `Audit\AuditContext` for site-wide pre-computation (title and H1 frequency maps)
- `Audit\Auditor` orchestrator with default rule registry and per-post + full-site audit methods
- 12 audit rules per spec §13:
  - Critical: `missing_h1`
  - Warning: `multiple_h1`, `generic_h1`, `short_title`, `thin_meta`, `duplicate_title`, `thin_content`, `boilerplate_intro`
  - Info: `long_title`, `stale_content`, `no_internal_links`, `no_h2_headings`
- Per-post audit runs on `save_post` (skips site-context rules)
- Full-site audit runs on daily cron + manual button on Audit page
- Issues persisted with upsert (no duplicate rows for same post+rule)

**Setup wizard**
- `Setup\SiteDetector` - inspects post types, taxonomies, SEO plugin, builder, WooCommerce, robots.txt, meta-description coverage, and finds About/FAQ/Legal/Events pages by slug
- `Setup\SectionSuggester` - generates section proposals from detection report
- `Setup\Wizard` - 4-step state machine (brand voice → detect → sections → finalize), state persisted in option until completion
- `Admin\Pages\WizardPage` - multi-step form UI with step indicator, posts to `admin-post.php` for processing
- Finalization writes settings, creates accepted sections, marks setup complete, flushes rewrite rules

**Admin UI**
- `Admin\Pages\AuditPage` - issue dashboard with severity counts, "Run audit now" button, sortable issue table with edit-post links
- `Admin\Pages\SectionsPage` - list view of configured sections with delete action
- `Admin\Pages\SettingsPage` - full settings form (brand voice, integrations, audit threshold, data removal toggle)
- `Admin\Menu` - top-level "WP LLMS" menu with 5 sub-pages, dashboard surfaces issue counts and llms.txt URL
- All form processing goes through `admin-post.php` actions with nonce verification

**Cron**
- Daily tick now invalidates llms.txt cache and runs full-site audit, persisting last-run timestamp to `wpllms_last_audit` option

**Phase 1 polish**
- `Generator\HeaderRenderer` extracted from `LlmsTxtGenerator` so both llms.txt and llms-full.txt share the same H1+blockquote+context render
- `Generator\LlmsFullTxtGenerator` - full implementation. Same selection as llms.txt, but inlines extracted markdown body for each post. 5 MB size cap with hard-truncation footer. Cached for 1 hour (vs. 5 min for llms.txt - full file is more expensive to regenerate)
- `Generator\MdEndpointRenderer` - renders per-page `.md` content with title, description, optional metadata block (Published/Updated/Categories/Author/Source), and full markdown body
- `Frontend\FileServer` - `.md` rewrite rule + handler. Supports nested page paths (`/parent/child.md`). Resolves slug across all public post types. Filters out non-publish, password-protected, and noindex posts. Per-slug transient cache. Setting toggle to disable. Cache invalidation now wildcards all `.md` transients via direct option-table cleanup.
- `Admin\Pages\SectionEditPage` - full add/edit form for sections. Supports manual (post ID list) and post_type (with limit + order) inclusion rules. Linked from Sections list via "Add new" button and Edit links per row. Form validation, error transient round-trip.

**Documentation**
- `TESTING.md` - deployment testing runbook

## [0.1.0] - 2026-05-05

Initial scaffold release. Not yet functional - generator, audit, and curation UI land in subsequent releases.
