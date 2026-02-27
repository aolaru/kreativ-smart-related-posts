=== Kreativ Smart Related Posts ===
Contributors: andreiolaru
Tags: related posts, internal links, ai, openai, seo
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display related posts based on shared categories/tags, with optional AI re-ranking.

== Description ==

Kreativ Smart Related Posts helps you keep visitors engaged by showing relevant articles below your content.

Features:
- Automatic related posts on single posts
- Shortcode support: `[smart_related_posts]`
- Dynamic block for the block editor
- Multiple layouts: Grid, List, Minimal
- Optional excerpt display
- Optional AI re-ranking via OpenAI (disabled by default)
- Built-in caching for performance

Backwards compatibility:
- Also supports legacy shortcode `[kreativ_related_articles]`.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **Kreativ Smart Related Posts** through the Plugins screen.
3. Go to `Settings > Kreativ Smart Related Posts` and configure options.

== Frequently Asked Questions ==

= Does this plugin require OpenAI? =
No. Core related-post matching works without OpenAI. AI re-ranking is optional.

= What data is sent to OpenAI? =
Only when AI re-ranking is enabled and an API key is set. The plugin sends:
- Source post title and excerpt
- Candidate post IDs, titles, and excerpts

= Does the plugin create custom database tables? =
No.

== External Services ==

This plugin can connect to OpenAI only if you enable AI re-ranking and provide an API key.

Service: OpenAI Chat Completions API  
Endpoint: `https://api.openai.com/v1/chat/completions`  
Purpose: Re-rank candidate related posts by semantic relevance  
Data sent: Post titles and excerpts (source + candidates)

Policies:
- https://openai.com/policies/privacy-policy
- https://openai.com/policies/terms-of-use

== Changelog ==

= 1.1.0 =
* Rebranded to Kreativ Smart Related Posts for broad public use.
* Added strict option sanitization.
* Added external services disclosure in settings/readme.
* Added dynamic block namespace update.
* Added uninstall cleanup for options and transients.
* Added backward-compatible shortcode alias.

= 1.0.0 =
* Initial release.
