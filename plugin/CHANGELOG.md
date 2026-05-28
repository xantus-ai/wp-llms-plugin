# Changelog

All notable changes to the WPSearch plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- Bootstrap file (`wpsearch-ai.php`) with PHP/WP version guards and PSR-4 autoloader fallback
- Composer config with league/html-to-markdown and dev tools
- Plugin singleton (`WPSearch\Plugin`)
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
- `Admin\Menu` - top-level "WPSearch" menu with 5 sub-pages, dashboard surfaces issue counts and llms.txt URL
- All form processing goes through `admin-post.php` actions with nonce verification

**Cron**
- Daily tick now invalidates llms.txt cache and runs full-site audit, persisting last-run timestamp to `wpsearch_last_audit` option

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
