=== Kreativ Smart Related Posts ===
Contributors: anolaru
Tags: related posts, internal links, ai, seo
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display related posts based on shared categories/tags, with optional AI re-ranking.

== Description ==

Kreativ Smart Related Posts helps you keep visitors engaged by showing relevant articles below your content.

It works out of the box with taxonomy-based related posts and can optionally use the WordPress AI Client to re-rank candidates for stronger semantic relevance.

Features:
- Automatic related posts on single posts
- Shortcode support: `[kreasmre_related_posts]`
- Dynamic block for the block editor
- Multiple layouts: Grid, List, Minimal
- Style controls for accent color, thumbnails, image ratio, grid columns, and card radius
- Optional excerpt display
- Optional post date and category display
- Configurable fallback posts when no taxonomy match exists
- Manual include/exclude picker per post
- Optional AI re-ranking via the WordPress AI Client (disabled by default)
- Built-in caching for performance

Backwards compatibility:
- Existing installations retain the legacy shortcodes `[smart_related_posts]` and `[kreativ_related_articles]` when those names are not already registered by another plugin.
- Sites previously using direct OpenAI must configure a WordPress AI provider and explicitly re-enable AI re-ranking. Older WordPress versions continue to display taxonomy-based recommendations.

== Source Code ==

All plugin source files, including the block editor JavaScript and CSS, are included in the plugin. No build step is required. The maintained source is available at https://github.com/aolaru/kreativ-smart-related-posts.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **Kreativ Smart Related Posts** through the Plugins screen.
3. Go to `Settings > Kreativ Smart Related Posts` and configure options.

== Screenshots ==

1. Plugin settings screen with AI, layout, display, fallback, and cache controls.
2. Related posts displayed in grid layout below a post.
3. Block editor preview and inspector controls.
4. Manual include/exclude picker in the post editor.

== Frequently Asked Questions ==

= Does this plugin require AI? =
No. Core related-post matching works without AI. Optional AI re-ranking requires WordPress 7.0 or later and a configured text-generation provider for the WordPress AI Client.

= What data is sent to the configured AI provider? =
Only when AI re-ranking is enabled and a provider is configured, the plugin sends:
- Source post title and excerpt
- Candidate post IDs, titles, and excerpts

= Does the plugin cache results? =
Yes. Related post results are cached with WordPress transients to reduce repeated database lookups and API calls. Cache duration is configurable, and caches are cleared when posts or category/tag assignments change.

= Does AI ranking slow down page loads? =
Visitors receive taxonomy-based recommendations immediately. When enabled, AI ranking refreshes in the background through WP-Cron, and later visits use the cached AI results. Caching must be enabled for background AI ranking; with caching disabled, only taxonomy-based results are used. Sites with WP-Cron disabled need a server cron job to process scheduled WordPress events.

= What happens when a post has no related category or tag matches? =
You can choose to show nothing, latest posts, or posts from the same author.

= Does the plugin create custom database tables? =
No.

== External Services ==

When you enable AI re-ranking, source post titles and excerpts and candidate IDs, titles and excerpts are sent through the WordPress AI Client to the provider configured for your site. Consult that provider's privacy policy and terms before enabling it. This requires WordPress 7.0 or later, a configured text-generation provider, and caching. Requests run in a scheduled background task for published posts. No external AI request is made by default.

== Changelog ==

= 1.2.3 =
* Removed the direct OpenAI integration. AI re-ranking now uses the WordPress AI Client only.
* Existing direct OpenAI users must configure a WordPress AI provider and explicitly re-enable AI re-ranking.

= 1.2.2 =
* Added distinctive prefixes and backwards-compatible migration of existing settings and overrides.
* Added optional WordPress AI Client integration on WordPress 7.0 and later.
* Provided readable block editor CSS and documented the public source repository.
* Corrected the WordPress.org contributor username.

= 1.2.1 =
* Restricted post search to published posts and protected saved unpublished selections with per-post permission checks.
* Fixed cache invalidation for persistent object caches and rechecked publication status on cache hits.
* Shared block attribute defaults between PHP and JavaScript using block.json.
* Added block API v3 support for current editors, with v2 compatibility for older WordPress versions.
* Moved AI ranking to WP-Cron with a worker lock, retry cooldown, and stale-job protection.
* Prevented duplicate auto-insertion alongside a shortcode or block and excluded secondary content loops.
* Ranked all taxonomy matches by relevance before applying the candidate limit.
* Limited block and shortcode output to 12 posts and cleaned up background tasks on deactivation.
* Improved output escaping and translator guidance for submission checks.

= 1.2.0 =
* Added searchable manual include/exclude post picker in the post editor.
* Added display controls for accent color, thumbnails, image ratio, grid columns, and card border radius.
* Added fallback modes for posts with no category/tag matches.
* Added display options for link target, date, category, and heading tag.
* Improved block editor preview to show styled grid/list/minimal previews.
* Extended shortcode and block attributes for the new display options.

= 1.1.0 =
* Rebranded to Kreativ Smart Related Posts for broad public use.
* Added strict option sanitization.
* Added external services disclosure in settings/readme.
* Added dynamic block namespace update.
* Added uninstall cleanup for options and transients.
* Added readable block editor script file instead of inline compressed block code.
* Added cache invalidation when posts or terms change.
* Improved AI re-ranking fallback to preserve the requested number of posts.
* Added translation bootstrap for PHP and block editor strings.
* Added backward-compatible shortcode alias.

= 1.0.0 =
* Initial release.
