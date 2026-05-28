# Generator Algorithm Specification

**Component:** WP LLMS - file generation engine
**Status:** Draft for review
**Last updated:** 2026-05-05
This document specifies the algorithms that produce `llms.txt`, `llms-full.txt`, and per-page `.md` variants. The plugin works with any WordPress site - Gutenberg, Elementor, Divi, Beaver Builder, classic editor, or no builder at all.

---

## 1. Inputs & Outputs

### Inputs

| Source | Used for |
|---|---|
| `wpllms_settings` (option) | H1, summary, context paragraphs, toggles |
| `wpllms_sections` (table) | Section names, order, intro text, inclusion rules |
| `wpllms_overrides` (table) | Per-post custom title, description, exclusion |
| WP posts (all public types) | Source content |
| Yoast / RankMath / AIOSEO meta | Description fallback chain |
| Post excerpts and content | Description and full-text fallback |

### Outputs

| File | Path | Format | Trigger |
|---|---|---|---|
| llms.txt | `/llms.txt` | Markdown index | Hooks + cron |
| llms-full.txt | `/llms-full.txt` | Markdown w/ inline content | Hooks + cron |
| `{slug}.md` | `/{post-slug}.md` | Per-post markdown | On request, transient-cached |

All files served via WordPress rewrite rules + `template_redirect`, **not** written to disk in production. (See §8 for rationale.)

---

## 2. The Output Format (per spec)

`llms.txt` MUST follow this structure exactly. Anything else risks LLMs misparsing it.

```markdown
# {site_h1}

> {site_summary}

{site_context}

## {section.name}

{section.intro_text}

- [{title}]({url}): {description}
- [{title}]({url}): {description}

## {next_section.name}

...

## Optional

- [{title}]({url}): {description}
```

**Rules:**
- Exactly one `#` H1 at the top
- Blockquote `>` summary immediately after, on its own paragraph
- Context paragraphs are plain text, optional
- Each section is one `##` H2 followed by optional intro and a bulleted list of links
- "Optional" section, if used, is the last `##` and contains links the LLM can skip
- Two newlines between sections (renders as one blank line in markdown)
- No trailing whitespace on any line
- File ends with a single trailing newline

---

## 3. Description Resolution

The description is the *most important* part of each entry. A bad description means the LLM can't tell why a page matters. We must produce something useful for every included post.

### Resolution chain

For each post, walk this list in order. **Stop at the first source that returns a non-empty, non-trivial string** (≥ 50 characters after trimming).

| Priority | Source | Notes |
|---|---|---|
| 1 | Manual override (`wpllms_overrides.custom_description`) | Highest authority - user explicitly set this |
| 2 | AI-generated override (Pro tier, stored as override with flag) | Distinguishable in UI but treated same as manual |
| 3 | Yoast meta description (`_yoast_wpseo_metadesc`) | Most common SEO plugin |
| 4 | RankMath description (`rank_math_description`) | Second most common |
| 5 | AIOSEO description (`_aioseo_description`) | Third |
| 6 | SEOPress description (`_seopress_titles_desc`) | Fourth |
| 7 | Post excerpt (`get_the_excerpt()`, with `the_excerpt_rss` filter applied) | Author-curated |
| 8 | First sentence of rendered content | Post-render extraction |
| 9 | First 160 chars of stripped content | Last resort |
| 10 | **Skip the post entirely** | Never publish a meaningless entry |

### Trimming and normalization

After resolution, apply:
1. Strip all HTML tags
2. Decode HTML entities (`&amp;` → `&`)
3. Collapse whitespace runs to single spaces
4. Trim leading/trailing whitespace
5. Remove trailing periods that interrupt list flow only if needed (no - keep them, they're grammatical)
6. Truncate at the last word boundary before 200 characters, append `…` if truncated
7. Strip leading boilerplate phrases: `"Read more about "`, `"Learn how to "`, `"In this post, "`, etc. (configurable list)

### Quality gates

A description fails the gate (and we move to the next priority) if:
- Length < 50 chars after trimming
- Length > 500 chars (likely accidentally pasted full content)
- Consists only of stop words / dates / names with no verbs
- Matches the post title exactly (zero added information)
- Matches the site tagline exactly (boilerplate, not page-specific)
- **Contains 2+ CTA phrases** from a list (`subscribe`, `buy now`, `click here`, `join today`, `sign up`, `download`, dollar signs followed by digits) - this is sales pitch, not page description
- **Matches a "site-wide boilerplate" string** detected by appearing on > 3 pages with identical wording (computed during full-site audit) - added 2026-05-05

### Yoast + custom post types

On sites using Yoast, most posts will resolve at priority 3. Pages built in Elementor often have Yoast descriptions set. **Custom post types often don't** - these will fall through to excerpt or content extraction. Test extensively against these.

---

## 4. Title Resolution

Simpler than descriptions, but with the same override-first pattern:

| Priority | Source |
|---|---|
| 1 | Manual override (`wpllms_overrides.custom_title`) |
| 2 | Yoast SEO title (`_yoast_wpseo_title`), templated and resolved |
| 3 | Post title (`get_the_title()`) |

**Normalization:**
1. Strip HTML
2. Decode entities
3. Trim whitespace
4. Remove site name suffix patterns: ` | Site Name`, ` - Site Name`, ` - Site Name` (auto-detected from `get_bloginfo('name')`)
5. Truncate at 90 chars on word boundary if needed

---

## 5. Content Extraction Pipeline

This is the trickiest part. Page builders (Elementor, Divi, Beaver Builder, etc.) store content in non-standard formats - Elementor uses JSON in `_elementor_data`, Divi uses shortcodes, and so on. Standard `post_content` may be empty or contain builder markup rather than readable text. The extraction pipeline handles this by rendering through WordPress's content filters first, then cleaning up the output.

### Pipeline stages

```
WP Post
  └── Stage 1: Resolve content source
  └── Stage 2: Render to HTML
  └── Stage 3: Sanitize HTML
  └── Stage 4: Strip builder chrome
  └── Stage 5: Convert to markdown
  └── Stage 6: Post-process markdown
```

### Stage 1: Resolve content source

For most posts, `post_content` contains usable HTML. Page-builder posts are the exception - their `post_content` may be empty or contain builder-specific markup. We detect and handle these:

```php
function resolve_content_source(WP_Post $post): string {
    $content = $post->post_content;

    // Page builder detection: if post_content is empty or builder-only,
    // render through the builder's frontend renderer instead.
    if (empty(trim($content)) && self::is_elementor_post($post->ID)) {
        $content = self::render_elementor($post->ID);
    }
    // Gutenberg and classic editor posts render fine through the_content filter.

    return $content;
}
```

Currently supports Elementor detection explicitly. Gutenberg blocks, classic editor, and other builders that render through `the_content` work automatically via Stage 2.

### Stage 2: Render to HTML

Apply `the_content` filter to resolve shortcodes, oEmbeds, and Gutenberg blocks. **Suppress** these filters during our render to avoid noise:
- Sharing buttons (Jetpack, AddToAny, etc.)
- Related posts widgets
- Comment forms
- Author bio cards (we'll add author separately if needed)
- Newsletter signup forms (Gravity Forms shortcodes)

We do this with a filter-removal-then-restoration helper:

```php
function render_for_extraction(WP_Post $post): string {
    $remove = ['gform_shortcode', 'jp_sharing_buttons', /* ... */];
    foreach ($remove as $tag) remove_shortcode($tag); // best-effort

    $html = apply_filters('the_content', $post->post_content);

    // Restore (in production we'd snapshot and restore - see implementation)
    return $html;
}
```

### Stage 3: Sanitize HTML

Use `wp_kses` with a strict allowlist:
- **Keep:** `h1` (will demote), `h2`, `h3`, `h4`, `h5`, `h6`, `p`, `ul`, `ol`, `li`, `blockquote`, `a` (href), `strong`, `b`, `em`, `i`, `code`, `pre`, `img` (src, alt), `br`, `hr`, `table`, `tr`, `td`, `th`, `thead`, `tbody`
- **Strip:** Everything else, but keep inner text content (`wp_kses` default behavior)

### Stage 4: Strip builder chrome

After kses, the markup is mostly semantic, but page builders can leave empty wrappers, CTA buttons, popups, and form widgets in the output. Apply DOM-level cleanup:

**General cleanup:**
- Drop empty `<p>` and `<div>` elements (no text, no images)
- Drop `<a>` tags that wrap CTA-only text ("Subscribe", "Join", "Sign Up") with no surrounding context
- Drop form elements and form widget containers
- Demote any in-content `<h1>` to `<h2>` (the post title is the document H1)

**Elementor-specific strips** (when Elementor is active):
- Drop `<a>` tags whose href starts with `#elementor-action%3A` (popup triggers)
- Drop nodes inside `[data-elementor-type="popup"]`, `[data-elementor-type="header"]`, `[data-elementor-type="footer"]`
- Drop `[data-element_type="widget"][data-widget_type="form.default"]` (embedded forms)

```php
// Pseudocode using DOMDocument
function strip_builder_chrome(string $html): string {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);

    // General cleanup: remove empty paragraphs and divs
    foreach ($dom->getElementsByTagName('*') as $node) {
        if (in_array($node->nodeName, ['p', 'div'])
            && trim($node->textContent) === ''
            && $node->getElementsByTagName('img')->length === 0) {
            $node->parentNode->removeChild($node);
        }
    }

    // Demote any in-content h1 to h2 (the post title is the document h1)
    foreach ($dom->getElementsByTagName('h1') as $h1) {
        // rename to h2 - DOMDocument doesn't have rename, so swap manually
    }

    // Builder-specific cleanup (Elementor popups, header/footer, etc.)
    // See builder-specific strip rules above.

    return $dom->saveHTML();
}
```

### Stage 5: Convert to markdown

Use `league/html-to-markdown` with these options:
- `'strip_tags' => true`
- `'remove_nodes' => 'script style iframe form nav footer aside'`
- `'hard_break' => false`
- `'header_style' => 'atx'` (use `#` not `===`)
- `'italic_style' => '*'`
- `'bold_style' => '**'`
- `'list_item_style' => '-'`

### Stage 6: Post-process markdown

After markdown conversion:
1. Collapse 3+ consecutive newlines to 2
2. Strip lines containing only whitespace
3. Remove markdown links to internal admin URLs (`/wp-admin/*`, `?action=*`)
4. Convert relative URLs to absolute using `home_url()`
5. Remove image embeds if `strip_images_from_full_text` setting is on (default off)
6. Trim trailing whitespace per line
7. Ensure single trailing newline

### Caching

The output of stages 1–6 is expensive (runs full WP filters). Cache per-post:
- Key: `wpllms_extracted_{post_id}_{post_modified_gmt_timestamp}`
- TTL: 24 hours
- Storage: object cache (transient)
- Invalidation: automatic via timestamp in key

---

## 6. Section Rendering

### Inclusion rules

Each section in `wpllms_sections` has an `inclusion_rule_json` field. The rule is one of:

```json
// Manual list
{"type": "manual", "post_ids": [12, 34, 56]}

// Post type
{"type": "post_type", "post_type": "course", "limit": 50, "order_by": "date_desc"}

// Taxonomy term
{"type": "taxonomy", "taxonomy": "category", "term_ids": [5, 8]}

// Combined query
{
  "type": "query",
  "post_type": "post",
  "tax_query": [{"taxonomy": "category", "term_ids": [5]}],
  "meta_query": [{"key": "_wpllms_featured", "value": "1"}],
  "limit": 30,
  "order_by": "date_desc"
}
```

Resolution function returns an ordered list of WP_Post objects, deduped, then filtered through:
1. Status check: only `publish`
2. Visibility: drop `password` protected, drop `noindex` (Yoast/RankMath flag)
3. Override exclusion: drop posts with `wpllms_overrides.is_excluded = 1` for this section
4. Override sort: apply `wpllms_overrides.sort_order` (defaults to query order)

### Rendering a section

```
## {section.name}

{section.intro_text optional, plain paragraph(s)}

- [{title}]({permalink}): {description}
- [{title}]({permalink}): {description}
...
```

If `section.intro_text` is empty, render the H2 followed directly by the list (one blank line between).

If the resolved post list is empty, **skip the section entirely** - don't render an empty H2.

### Optional sections

Sections marked `is_optional = 1` are collected and rendered together at the end under a single `## Optional` heading. Within Optional, each original section appears as an H3:

```markdown
## Optional

### {original_section.name}

- [link]: desc
- [link]: desc

### {another_optional.name}

- [link]: desc
```

**Why:** The spec allows an Optional section but doesn't dictate sub-structure. Using H3s preserves user organization while marking the whole block as skippable.

---

## 7. Header Rendering

```
# {settings.site_h1}

> {settings.site_summary}

{settings.site_context}
```

### H1 resolution

1. `settings.site_h1` if set
2. `get_bloginfo('name')` as fallback

### Summary (blockquote)

`settings.site_summary` is a single paragraph, max 500 chars. Required (we'll force user to fill on first run via setup wizard). Renders as `> {text}`.

If summary is empty, fall back to `get_bloginfo('description')` (the WP tagline).

### Context paragraphs

`settings.site_context` is a multi-paragraph free-text block (markdown allowed). Rendered verbatim after the blockquote with one blank line separator. Optional.

---

## 8. File Serving Strategy

### Decision: serve via WP, not write to disk

Don't write `llms.txt` and `llms-full.txt` as physical files. Instead:

1. Register rewrite rules at `init`:
   ```
   ^llms\.txt$           → index.php?wpllms_file=llms
   ^llms-full\.txt$      → index.php?wpllms_file=full
   ^([^/]+)\.md$         → index.php?wpllms_md_slug=$matches[1]
   ```
2. Hook `template_redirect`. If `wpllms_file` query var present, render and `exit`.
3. Cache the rendered output in a transient (`wpllms_llms_txt_cache`) keyed by a hash of all relevant inputs.

**Why not write files:**
- WP filesystem permissions are unreliable across hosts
- Static files bypass WP filters → harder to invalidate consistently
- File caching (Cloudflare, WP Rocket) can serve a stale physical file forever
- Multisite gets complicated with shared paths
- Some managed hosts (WP Engine, Kinsta) have read-only roots

**Rendering at request time has tradeoffs** - slower first hit, but with object cache it's negligible (kilobytes of markdown, no DB queries on cache hit). Page caching plugins WILL cache `/llms.txt` responses, which is fine because we use `Last-Modified` and `ETag` headers.

### HTTP headers

```
Content-Type: text/markdown; charset=UTF-8
Cache-Control: public, max-age=300, must-revalidate
Last-Modified: {generated_at_gmt}
ETag: "{hash_of_content}"
X-Robots-Tag: all
```

Setting `text/markdown` (not `text/plain`) per the spec recommendation.

### .md endpoint behavior

Pattern: `/{slug}.md` resolves to a per-post markdown file.

Resolution:
1. Look up post by slug (any public post type)
2. Validate: status = publish, not password-protected, not noindex
3. Check transient cache
4. If miss: run extraction pipeline, render template, store in transient
5. Return with same headers as above

**Markdown template for `.md` endpoint:**

```markdown
# {title}

{description from priority chain}

**Published:** {date}
**Updated:** {modified_date}
**Categories:** {comma-separated}
**Author:** {display_name}
**Source:** {permalink}

---

{extracted markdown body}
```

Metadata block is optional (toggle in settings).

### 404 behavior

If `.md` requested for non-existent / private / draft post: return 404 with body `# Not Found\n\nThis page does not exist or is not publicly available.\n` and `Content-Type: text/markdown`.

---

## 9. llms-full.txt Generation

Same selection logic as `llms.txt`. For each included post:

1. Render header (site H1, summary, context) - same as llms.txt
2. For each section:
   - Render `## {section.name}` followed by intro
   - For each post:
     ```markdown
     ### {title}

     **Source:** {permalink}

     {extracted markdown body}

     ---
     ```
3. Concatenate all sections with blank-line separators

**Size guard:** If total output exceeds 5 MB (configurable), truncate the longest posts first and add `_(content truncated - see {permalink} for full)_` markers. Most LLMs choke on >2 MB inputs anyway.

**Performance:** llms-full.txt regeneration is the slowest operation. Always run async via:
- Action Scheduler (if available - many WP sites have it via WooCommerce)
- Fall back to WP-Cron with chunked processing (10 posts per run)

---

## 10. Regeneration Triggers

| Event | What happens |
|---|---|
| `save_post` (publish or update of public post type) | Set dirty flag for both files; queue async regen |
| `transition_post_status` (any → publish) | Same as above |
| `transition_post_status` (publish → trash/draft) | Set dirty; regen will exclude this post |
| `delete_post` | Set dirty |
| `created_term` / `edited_term` (in tracked taxonomies) | Set dirty |
| Section CRUD in admin | Set dirty + immediate regen |
| Setting change | Set dirty + immediate regen |
| Manual "Regenerate now" button | Immediate regen, ignore dirty flag |
| Daily cron `wpllms_daily_regen` | Regen if dirty flag set |
| License tier change | Set dirty (Pro features may add/change content) |

### Dirty flag

Single option `wpllms_dirty` with shape:
```php
['llms_txt' => 1715000000, 'llms_full' => 1715000000]
```

Each is a unix timestamp of when it became dirty. Cleared on successful regen.

### Async queueing

Don't regenerate inline on `save_post`. Schedule a one-off cron event for ~30 seconds in the future. If multiple saves happen in quick succession, they coalesce into a single regen. Reduces load during bulk imports.

```php
function queue_regen(string $which): void {
    if (!wp_next_scheduled('wpllms_regen_' . $which)) {
        wp_schedule_single_event(time() + 30, 'wpllms_regen_' . $which);
    }
}
```

---

## 11. Edge Cases & Behaviors

### Multilingual sites
- Phase 1: only generate for the site's default language. Detect WPML/Polylang and warn the user that translated content isn't included.
- Phase 4+: per-language llms.txt at `/{lang}/llms.txt`.

### Multisite networks
- Phase 1: per-site only. Each site in the network has its own llms.txt.

### Password-protected posts
- Excluded from all outputs.

### Private / draft / pending posts
- Excluded.

### Posts marked noindex
- Excluded by default (respects user intent). Setting toggle to override.

### Posts in trash
- Excluded.

### WooCommerce products
- Treated as a post type. By default, NOT auto-included (user must add a section with `post_type: product` rule). Reasoning: most stores have hundreds of products and listing them all bloats the file. We can add a "products" preset later.

### Custom post types from themes/plugins
- All publicly queryable post types are available as inclusion rule options.
- We expose a filter `wpllms_eligible_post_types` for site-specific overrides.

### Posts with shortcodes that emit forms
- Stripped during extraction (Stage 4). Forms are not useful in llms.txt.

### Pages that are pure landing pages with no text
- Will fail the description gate at every priority level.
- Plugin behavior: log to audit (`empty_content` rule), exclude from output.
- User can write a manual override to include anyway.

### Page builder content
- **Gutenberg (block editor):** Renders through `the_content` filter. No special handling needed.
- **Elementor:** Detected via `_elementor_edit_mode` post meta. Stage 1 renders through Elementor's frontend if `post_content` is empty. Theme Builder templates (`elementor_library` post type) are excluded by default.
- **Divi / Beaver Builder / Bricks:** Render through `the_content` filter like Gutenberg. Shortcode-based builders work automatically once shortcodes are registered.
- **Classic editor:** Standard `post_content` - simplest path.
- **Mixed (e.g., classic + Elementor on same site):** Resolved per-post in Stage 1 based on post meta.

### Imported / RSS-feed-style posts
- No special handling. Treated as normal posts.

### Posts with `<!--more-->` tag
- We use full content for description extraction (priority 8–9), not just the teaser.

### Very long posts (>20K words)
- For description extraction: only first 2K chars matter
- For llms-full.txt: full content, but flagged for size guard truncation

### Non-ASCII / RTL content
- UTF-8 throughout. No special handling needed.

### Disabled features / settings off
- If `update_robots_txt = false`, skip robots.txt modification entirely
- If `inject_link_tag = false`, skip `<link>` injection
- If `serve_md_variants = false`, return 404 for `.md` requests
- File generation never disabled - that's the core function

### Page has no body H1 (added 2026-05-05)
- Title resolution falls back to `get_the_title()` → still produces a usable entry
- Auditor flags `missing_h1` regardless - this is the issue Cognos-style audits surface
- The post is still included in llms.txt unless the user excludes it manually

### Stale content / abandoned post types (added 2026-05-05)
- Post types whose newest item is > 24 months old: surfaced in setup wizard for explicit inclusion/exclusion choice
- Individual posts not modified in > 24 months: included by default but flagged with `stale_content` audit rule (severity: info)
- User can configure threshold in settings (default 24 months, range 6–60)

### Post-type and page label collision (added 2026-05-05)
- When a custom post type with > 5 items shares a label with a page (e.g., `course` post type + `/courses/` page): setup wizard suggests choosing one as the section source, not both
- Without this, llms.txt has redundant entries pointing the LLM at the same content twice

---

## 12. Performance Targets

| Operation | Target | Example (~50 entries) |
|---|---|---|
| llms.txt regen (cached extracts) | < 200 ms | ~50 entries × ~3ms = 150ms |
| llms.txt regen (cold) | < 5 s | ~50 entries × ~80ms = 4s |
| llms-full.txt regen (cold) | Async, < 60 s wall time | ~50 posts × ~1s = 50s |
| .md endpoint cached | < 10 ms | Transient hit |
| .md endpoint cold | < 500 ms | Single post extraction |
| Audit run on save_post | < 100 ms | Per-rule sub-10ms |
| Initial activation scan | < 30 s for 1000 posts | ~300 posts → ~10s |

These are upper bounds. Optimize if we exceed them in real testing.

---

## 13. Failure Modes

| Failure | Behavior |
|---|---|
| Filesystem unwritable (we don't write, but transients may fail) | Fall back to in-memory rendering per request, log warning |
| Object cache disabled | Use `wp_options` for cache (slow but functional) |
| Elementor not installed but post claims `_elementor_data` | Skip Elementor render, fall back to post_content |
| Yoast not installed | Skip Yoast meta source, move to next priority |
| Cron not running | Surface admin notice "Regeneration is stale by N hours" |
| html-to-markdown library throws | Catch, log, fall back to `wp_strip_all_tags` |
| Post has malformed HTML | DOMDocument loads with warnings suppressed; output is best-effort |
| Memory limit hit during llms-full | Chunked regen via Action Scheduler, save progress |
| 50,000+ posts on one site | Inclusion rules cap at 1,000 per section, surface warning |

---

## 14. Spec Compliance Checklist

Before shipping, verify against the [llmstxt.org spec](https://llmstxt.org):

- [ ] Single H1 at top
- [ ] Blockquote summary immediately follows H1
- [ ] Context (if present) is plain text after blockquote
- [ ] Each section is a single H2
- [ ] Links are markdown format `[text](url)`
- [ ] Descriptions follow links separated by `:` (or `-`?  - spec uses `:`)
- [ ] Optional content lives under `## Optional`
- [ ] File served as `text/markdown`
- [ ] No HTML in output (markdown only)
- [ ] UTF-8 encoded
- [ ] LF line endings (not CRLF)

---

## 15. Resolved Spec Decisions

1. **`.md` endpoint metadata block:** included by default with toggle to disable.
2. **Optional section uses H3 for original section names** under a single `## Optional` H2.
3. **Don't write physical files** - serve via WP rewrite + `template_redirect`. (§8)
4. **Robots.txt update gated behind setup wizard** - never silent on activation. ✓ confirmed 2026-05-05
5. **WooCommerce products excluded by default** - store purchases handled by Woo, not surfaced in llms.txt. ✓ confirmed 2026-05-05
6. **Description trim length: 200 chars.** ✓ confirmed 2026-05-05
7. **Cap of 50 entries per section** - configurable, prevents accidental dumps.
8. **Ship empty, run a setup wizard on activation** - no auto-seeded sections. The wizard detects what's on the site and proposes a starting structure. ✓ confirmed 2026-05-05

---

## 16. Validation Walkthrough

To validate the spec against a real site:

1. Pull a sample of 10 real posts/pages across post types (post, page, and any custom post types)
2. Walk each through the extraction pipeline manually
3. Walk the section rendering with proposed default sections
4. Produce a draft llms.txt
5. Compare against expected output
6. Identify any spec gaps before writing implementation code

That walkthrough becomes the test fixture for Phase 1 development.

---

## 17. Setup Wizard

**Plugin ships empty.** No auto-seeded sections. On first activation (or via "Run Setup Wizard" button later), the user is walked through a 4-step wizard.

### Step 1: Brand voice

- **Site H1** - pre-filled with `get_bloginfo('name')`, editable
- **Summary** (blockquote) - required, 1–3 sentences, character counter, examples shown
- **Context paragraphs** - optional, free text with markdown allowed
- *(Pro tier inline upsell: "Let WP LLMS analyze your site and write this for you - Powered by Xantus AI" - runs the brand voice trainer if Pro license is active)*

### Step 2: Site detection

The plugin scans the site and reports back:

```
We detected:
  ✓ 7 public post types: post, page, course, challenge, video, member_story, topic
  ✓ 3 taxonomies you might want to use: category (18 terms), content_category (7 terms)
  ✓ Yoast SEO active - we'll use Yoast meta descriptions where set
  ✓ Yoast meta descriptions are set on N of M posts (the rest will use excerpts/content)
  ✓ Page builder detected (Elementor) - we'll extract clean content from your builder pages
  ✓ WooCommerce active - products will NOT be auto-included (you can add them later)
  ✗ No `<link rel="llms">` in your <head> yet - we can add this for you
  ✗ Your robots.txt does not reference llms.txt - we can fix this for you
```

Each "we can fix this for you" line has a checkbox (default checked). The user accepts or unchecks.

### Step 2.5: Stale content review (added 2026-05-05)

The plugin lists post types whose newest item is older than 24 months, plus individual posts last modified > 24 months ago in active post types.

```
The following content hasn't been updated in a while:

  Custom post types:
  ☐ ambassadors (last update: 2023-01) - 0 of 8 items < 24mo old
  ☐ ebooks (last update: 2022-10) - 0 of 1 items < 24mo old
  ☐ topics (last update: 2023-12) - 0 of 3 items < 24mo old

  By default, these are EXCLUDED from llms.txt. Check the box to include them
  anyway, or skip and revisit later in Settings.
```

For active post types with some stale individual posts, the wizard does NOT prompt at this step - those are handled per-post in audit and remain included unless the user excludes them.

### Step 3: Choose your sections

The wizard presents a list of **suggested sections** based on what was detected. The user accepts/rejects each one and can add their own.

**Suggestion logic:**

| Detection | Suggestion shown |
|---|---|
| Has `page` post type with about/our-story/team slugs | "About" section, manual list of those pages |
| Has any custom post type with > 5 published items AND newest < 24mo old | One section per post type, named from post type label |
| Has > 50 blog posts | "Blog Highlights" section, prompt user to hand-pick 20–30 |
| Has > 5 categories | "Blog Categories" section listing category archives |
| Has pages with FAQ-like slugs | "FAQ" section |
| Has pages with privacy/terms/contact slugs | "Legal & Contact" section, marked Optional |
| Has events/retreats slugs or category | "Events" section, marked Optional |
| Has free downloadable assets (PDFs in media library, ebook post type) | "Free Resources" section |

**Collision detection (added 2026-05-05):**
When a custom post type shares a label with a page (e.g., `course` post type AND a page titled "Courses"), the wizard shows:

> *We found both a `course` post type with 17 items AND a page at `/features/courses/`. Including both would create duplicate entries in your llms.txt. Which would you like to feature?*
> ○ The course post type (recommended - surfaces individual courses)
> ○ The "Courses" page (good if it's a curated overview)
> ○ Both (advanced - only if they describe different things)

For each suggestion, the user sees:
- Section name (editable)
- Number of items it would include
- Preview of the first 3 items
- Optional toggle (Optional vs. Required)
- "Skip this section" button

### Step 4: Preview & generate

- Live preview of the rendered llms.txt with all chosen sections
- Total entry count, file size estimate
- "Generate now" button → writes the file, finishes wizard

### Re-running the wizard

The wizard is also accessible from the admin menu after first run. Useful when:
- User installs a new plugin that adds post types
- User publishes content that suggests new sections
- User wants a fresh start

The wizard never overwrites existing sections without explicit confirmation.

### For sites with no detected content

If the site is brand new (< 10 published items total), the wizard skips Step 3 and shows a message:
> "Your site doesn't have much published content yet. Run the wizard again once you have more pages and posts."

User can still set up brand voice and proceed with an empty file.

---

## End

Review needed on §15 in particular. Once approved, this becomes the contract for `Generator/`, `Curation/`, and `Frontend/FileServer.php` implementations.
