=== WPSearch - AI Search Optimization for WordPress ===
Contributors: xantusai
Tags: llms.txt, ai search, seo, ai seo, llms, chatgpt, claude, perplexity, ai discoverability, schema
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.6
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WordPress site discoverable by AI. Auto-generate llms.txt, audit content for AI-search readiness, generate schema, and track AI bot activity.

== Description ==

AI-powered search (ChatGPT, Claude, Perplexity, Google AI Overviews) is rapidly becoming a primary discovery channel. WPSearch helps your WordPress site get surfaced and recommended by these systems.

**What WPSearch does:**

* Auto-generates `llms.txt` and `llms-full.txt` from your WordPress content, regenerates on every publish
* Per-page `.md` variants of your most important pages
* Auto-updates `robots.txt` and injects `<link rel="llms">` for AI-bot discoverability
* Curation UI: organize content into sections, override descriptions, mark sections optional
* Quality auditor flags missing H1s, short titles, thin meta descriptions, generic H1s, boilerplate intros, stale content, duplicate titles
* JSON-LD schema generation for Article, Organization, FAQPage
* Setup wizard detects your site's content structure and proposes a starting llms.txt configuration

**Pro features (Powered by Xantus AI):**

* AI-powered description rewriting
* Brand voice trainer
* Semantic content uniqueness scoring
* AI bot analytics with industry benchmarks (GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended)
* Content gap detection
* Advanced schema (Course, Person, HowTo, BreadcrumbList)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wpsearch-ai`
2. Activate through the WordPress 'Plugins' menu
3. Run the setup wizard from the WPSearch admin menu
4. Visit `/llms.txt` on your site to verify generation

== Frequently Asked Questions ==

= What is llms.txt? =

llms.txt is an emerging web specification (llmstxt.org) that provides a curated, markdown-formatted index of a website's most important content, purpose-built for consumption by large language models. Over 844,000 websites have adopted it, including Anthropic, Cloudflare, and Stripe.

= Does this require an API key or account? =

No. The free tier is fully functional with no account required. Pro features require a license key from wpsear.ch.

= Will this conflict with Yoast SEO or RankMath? =

No. WPSearch reads from these plugins (using their meta descriptions when set) and complements them. We focus on AI-search; they focus on traditional search.

= Does it work with Elementor / page builders? =

Yes. WPSearch has a content extraction pipeline specifically designed to handle Elementor-built pages and other builder-heavy markup.

== Changelog ==

= 0.1.0 =
* Initial scaffold release.

== Upgrade Notice ==

= 0.1.0 =
First release.
