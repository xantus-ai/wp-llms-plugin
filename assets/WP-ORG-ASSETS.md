# WordPress.org Asset Brief

This directory holds the marketing assets for the `llms-txt` listing on
WordPress.org. On submission day, these files are copied into the SVN
repo's `/assets/` directory (which is a **sibling** of `/trunk/`, not
inside it — they don't ship in the plugin zip and never reach end users'
WordPress installs).

```
plugins.svn.wordpress.org/llms-txt/
├── trunk/         # plugin code, mirror of plugin/
├── tags/          # tagged releases (tags/0.1.8/, etc.)
└── assets/        # what this directory becomes
    ├── icon-128x128.png
    ├── icon-256x256.png
    ├── banner-772x250.png
    ├── banner-1544x500.png
    ├── screenshot-1.png
    ├── screenshot-2.png
    └── ...
```

---

## 1. Icon

**Required:** `icon-128x128.png`
**Strongly recommended (retina):** `icon-256x256.png`
**Optional:** `icon.svg` (WP.org will render it for any size)

### Constraints

- Square, transparent background **not** allowed — use a solid fill
- Must read clearly at 64x64 (it appears at that size in the WP admin plugin list)
- No fine lines, gradients, or photographic detail — they mud at small sizes
- No "WordPress" branding, no "WP" prefix in any wordmark
- File size under 1 MB

### Concept directions (pick one)

**Concept A — Stylized .txt extension**
The wordmark `.txt` set in a bold geometric typeface (Inter Black, Söhne
Heavy, Geist Black, or similar). The leading period is a bright accent
color to signal a "file" while staying typographic. Conveys: "this is a
file format". Cheapest to produce, most recognizable to developers.

```
┌────────────────────┐
│                    │
│     ┌──────────┐   │
│     │ .txt     │   │
│     └──────────┘   │
│                    │
└────────────────────┘
```

**Concept B — "llms" letterform with file-corner motif**
The letters `llms` in a tall, condensed typeface, with a folded-corner
"document" motif on the bottom-right (the classic 📄 visual reduced to a
single corner triangle). Conveys: "llms + document".

```
┌────────────────────┐
│                    │
│   l l m s          │
│            ◢       │
│                    │
└────────────────────┘
```

**Concept C — Monogram "L"**
A single bold "L" with a small dot or sparkle, used as a brand monogram.
Conveys: "this is a product / brand". Best long-term if you plan to grow
into a multi-product line under Xantus AI.

### Color palette (placeholder — designer to confirm)

The Xantus AI brand color should drive this. As a starting point if
nothing is locked in:

| Role            | Hex                     | Notes                                      |
|-----------------|-------------------------|--------------------------------------------|
| Primary fill    | `#6D7380` (Slate Grey)  | Deep neutral, reads as serious/technical   |
| Accent          | `#10B981` (Mint Leaf)   | AI/tech association, pairs well with slate |
| Text on primary | `#F8FAFC` (Bright Snow) | Off-white, easier on eyes than pure white  |

Alternates worth considering: carbon black (`#18181B`) with cool steel (`#6D7380`).

**Avoid:** WordPress blue (`#21759b`), bright greens (overused by SEO
plugins), and pure-white backgrounds (icon disappears against the WP
admin's white plugin list rows).

---

## 2. Banner

**Required:** `banner-772x250.png`
**Strongly recommended (retina):** `banner-1544x500.png`

### Constraints

- Aspect ratio is fixed at 772:250 (~3.09:1)
- Renders at the top of the plugin listing page on wordpress.org
- Mobile WP.org users see a cropped version — keep critical content
  centered horizontally
- Text must remain legible at 50% scale (mobile)
- No "WordPress" logo, no Anthropic/OpenAI/etc. logos

### Layout

```
┌──────────────────────────────────────────────────────────────────────┐
│                                                                      │
│   [icon mark]   llms.txt for WordPress                               │
│                 Make your WordPress site discoverable by AI.         │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

### Required elements

1. The plugin wordmark — `llms.txt for WordPress` set in display type
2. A one-line tagline — pick from below
3. The icon mark (smaller version of the plugin icon, left-aligned)

### Tagline options (pick one)

- "Make your WordPress site discoverable by AI." *(recommended — broad, benefit-led)*
- "Auto-generated llms.txt for WordPress."
- "Curate, audit, and serve llms.txt — automatically."
- "The llms.txt standard, built for WordPress."

### Background

Solid color from the palette above, or a very subtle radial/linear
gradient. **Do not** use stock photos or AI-generated illustrations.
A subtle background pattern (dots, fine grid, faint markdown syntax)
is acceptable if it doesn't compete with the text.

---

## 3. Screenshots

**Required:** at least one. **Recommended:** 5–7.
**Naming:** `screenshot-1.png`, `screenshot-2.png`, … (numbers match the
order of captions in `readme.txt` under `== Screenshots ==`).

### Capture constraints

- Consistent width across all shots (recommend **1440px**, matching the
  WordPress admin viewport at standard desktop resolution)
- Crop tightly — the WP.org page rescales and pads, so wasted whitespace
  reads as low effort
- Use a clean WordPress install with **no third-party admin notices**
  (deactivate other plugins or hide notices for the capture)
- For shots involving real content, use safe demo content (no client
  data, no internal docs)
- Save as PNG (not JPG — text artifacts), under 1 MB each

### Shot list (matches readme captions)

| # | What to capture                                       | State / setup                                                                                                                                     |
|---|-------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| 1 | **Dashboard**                                         | Setup wizard completed, llms.txt URL row showing the green checkmark, audit issue counts populated (a few of each severity), serving mode visible |
| 2 | **Sections list**                                     | 3–5 sections configured with varied types (manual, post_type), each showing its rule summary                                                      |
| 3 | **Section editor with search picker**                 | Mid-search state: query typed, dropdown showing 3–4 matched posts, 2–3 already-selected posts visible below                                       |
| 4 | **Audit page**                                        | Issues table populated, severity badges visible, mix of Critical/Warning/Info, the "Run audit now" button visible                                 |
| 5 | **Setup wizard step 2 (detection)**                   | The detected-content step showing post types, SEO plugin found, builder found, found pages — the "we know your site" moment                       |
| 6 | *(optional)* **The generated /llms.txt in a browser** | Browser tab open to `/llms.txt`, showing the rendered file with H1, blockquote summary, and the section list                                      |
| 7 | *(optional)* **Settings page**                        | The full settings form, scrolled to show brand voice + integrations sections                                                                      |

### Pre-capture checklist

- [ ] WordPress admin theme is the default light theme (not dark mode)
- [ ] Browser at standard zoom (100%), 1440px viewport width
- [ ] No browser bookmarks bar, no dev tools open
- [ ] Other plugin admin notices dismissed or hidden via CSS
- [ ] User menu / "Howdy, X" obscured or shows a neutral name
- [ ] Site name in admin bar is neutral (e.g., "My Site", not a real client)

---

## 4. Designer hand-off package

When you hand this off, include:

1. This file (`WP-ORG-ASSETS.md`)
2. The current `plugin/readme.txt` (so they understand the product positioning)
3. Brand color hex codes (confirm or override the placeholders above)
4. Any existing Xantus AI brand assets (logo, typeface, brand sheet)
5. A reference set — 3–4 plugin icons + banners from WP.org that you
   admire (e.g., Yoast SEO, WP Rocket, Akismet — open each in a browser
   to see their banner/icon treatment)

---

## 5. Export checklist

When the designer delivers final files:

- [ ] `icon-128x128.png` — PNG-24, sRGB, no alpha channel
- [ ] `icon-256x256.png` — PNG-24, sRGB, no alpha channel
- [ ] `banner-772x250.png` — PNG-24, sRGB
- [ ] `banner-1544x500.png` — PNG-24, sRGB
- [ ] Each file under 1 MB (run through TinyPNG / Squoosh if needed)
- [ ] Visual smoke test: drop each PNG into a 64x64 square preview to
      confirm the icon still reads at small sizes
- [ ] Commit to this directory in git for version control
- [ ] On submission, copy to the SVN `/assets/` folder (see SVN section
      of the main README for the submission workflow)
