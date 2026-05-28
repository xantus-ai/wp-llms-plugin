# WPSearch - AI Search Optimization for WordPress

Make your WordPress site discoverable by AI. Auto-generate and maintain `llms.txt` files, audit content for AI-search readiness, and serve per-page markdown variants - all from a single plugin with no external dependencies.

**WordPress.org slug:** `wpsearch-ai`
**Requires:** WordPress 6.0+ / PHP 8.1+
**License:** GPL v2 or later

---

## What is llms.txt?

[llms.txt](https://llmstxt.org) is an emerging web standard that provides a curated, markdown-formatted index of a website's most important content, designed for consumption by large language models (ChatGPT, Claude, Perplexity, Google AI Overviews). Over 844,000 websites have adopted it, including Anthropic, Cloudflare, and Stripe.

WordPress sites need a way to generate and maintain this file automatically as content changes. That's what WPSearch does.

## Features

- **Auto-generates `llms.txt` and `llms-full.txt`** from your WordPress content
- **Per-page `.md` endpoints** - serve markdown variants of any post or page
- **Setup wizard** detects your site's content structure and proposes sections
- **Curation UI** - organize posts into named sections, override titles and descriptions, set sort order
- **Search-and-select post picker** - find content by title instead of hunting for post IDs
- **12-rule quality auditor** - flags missing H1s, thin meta descriptions, duplicate titles, generic headings, boilerplate intros, stale content, and more
- **robots.txt integration** - adds `llms.txt` and missing sitemap references
- **`<link rel="llms">` injection** in `<head>` for AI-bot discoverability
- **WP Engine / Kinsta support** - auto-detects managed hosts that block dynamic `.txt` serving and writes physical files instead
- **Instant updates** - section edits, settings changes, and post saves immediately regenerate the file

### How it works

WPSearch hooks into WordPress's content lifecycle:

1. **On activation**, a setup wizard scans your site and suggests an initial section structure
2. **On every post save**, the llms.txt cache is invalidated and the file is regenerated
3. **Daily via cron**, a full-site audit runs and both `llms.txt` and `llms-full.txt` are refreshed
4. **On demand**, you can edit sections, reorder content, and trigger manual regeneration

The description for each entry is resolved through a 10-priority chain: manual override, Yoast/RankMath/AIOSEO/SEOPress meta description, post excerpt, first sentence of content - each passing through quality gates that reject boilerplate, sales pitches, and too-short text.

## Installation

### From a zip (current method)

```bash
git clone https://github.com/xantus-ai/wp-llms-plugin.git
cd wp-llms-plugin
./build.sh
```

Upload the resulting `wpsearch-ai.zip` via **WP Admin → Plugins → Add New → Upload Plugin**.

### Manual

1. Copy the `plugin/` directory to `wp-content/plugins/wpsearch-ai/`
2. Run `composer install --no-dev` inside the plugin directory
3. Activate through the WordPress Plugins menu

### After activation

1. Go to **WPSearch** in the admin sidebar
2. Run the setup wizard
3. Visit `/llms.txt` on your site to verify

## Repository layout

```
├── README.md                    # This file
├── generator-spec.md            # Algorithm specification for the generator/audit engines
├── build.sh                     # Builds uploadable wpsearch-ai.zip
├── bump-version.sh              # Bumps version across all source-of-truth files
└── plugin/                      # WordPress plugin source
    ├── wpsearch-ai.php          # Bootstrap + WP plugin header
    ├── composer.json             # PSR-4 autoload + league/html-to-markdown
    ├── readme.txt               # WordPress.org listing format
    ├── uninstall.php            # Cleanup on plugin delete
    ├── CHANGELOG.md             # Keep a Changelog format
    └── src/                     # PHP source (namespace WPSearch\)
        ├── Plugin.php           # Singleton, registers all hooks
        ├── Activator.php        # Activation: tables + options + cron + rewrite flush
        ├── Deactivator.php      # Deactivation cleanup
        ├── Admin/               # Admin menu + page renderers
        ├── Audit/               # 12 audit rules + orchestrator + persistence
        ├── Cron/                # Daily scheduler
        ├── Frontend/            # File serving, host detection, robots.txt, head injection
        ├── Generator/           # llms.txt/llms-full.txt/md generation engine
        ├── Setup/               # Wizard, site detector, section suggester
        └── Storage/             # DB schema, repositories, options wrapper
```

## Tech stack

| Component | Choice |
|---|---|
| Language | PHP 8.1+ |
| WordPress | 6.0+ |
| Autoloading | PSR-4 via Composer (with fallback) |
| Markdown conversion | league/html-to-markdown |
| Storage | Custom MySQL tables + wp_options |
| Admin UI | Server-rendered HTML + WordPress admin styles |
| Cron | WordPress wp-cron |

## Development

### Build the zip

```bash
./build.sh
```

Runs `composer install --no-dev`, checks PHP syntax, and produces `wpsearch-ai.zip` ready for WordPress upload.

### Bump version

```bash
./bump-version.sh 0.2.0
```

Updates the version in `plugin/wpsearch-ai.php` (header + constant), `plugin/readme.txt`, and `plugin/CHANGELOG.md`.

### Lint

```bash
find plugin/src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

Empty output means clean. The build script runs this automatically.

## Conventions

| Thing | Pattern |
|---|---|
| PHP namespace | `WPSearch\` |
| Composer package | `xantus/wpsearch` |
| Hook/option/transient prefix | `wpsearch_` |
| DB table prefix | `{$wpdb->prefix}wpsearch_` |
| Constants prefix | `WPSEARCH_` |
| Filter names | `wpsearch_*` |

## The audit rules

| Rule | Severity | What it checks |
|---|---|---|
| `missing_h1` | Critical | No `<h1>` in page body |
| `multiple_h1` | Warning | More than one `<h1>` |
| `generic_h1` | Warning | H1 matches site tagline or appears on multiple pages |
| `short_title` | Warning | Title under 30 characters |
| `long_title` | Info | Title over 65 characters |
| `thin_meta` | Warning | Meta description missing or under 70 characters |
| `duplicate_title` | Warning | Title matches another post (exact or Levenshtein > 0.85) |
| `thin_content` | Warning | Page with under 300 words |
| `boilerplate_intro` | Warning | Opening text contains CTA phrases or matches site tagline |
| `stale_content` | Info | Not modified in over 24 months |
| `no_internal_links` | Info | Zero internal links in content |
| `no_h2_headings` | Info | Over 800 words with no H2 subheadings |

## Contributing

Contributions are welcome. Please open an issue first to discuss what you'd like to change.

The algorithm specification in `generator-spec.md` documents the contracts for the generator and audit engines - read it before modifying those components.

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

Built by [Xantus AI](https://xant.us).
