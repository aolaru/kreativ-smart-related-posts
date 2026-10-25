=== Kreativ Smart Related Posts ===
Contributors: andreiolaru
Tags: related posts, internal links, ai, openai, seo
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display related posts based on shared categories/tags, with optional AI re-ranking.

== Description ==

Kreativ Smart Related Posts helps you keep visitors engaged by showing relevant articles below your content.

It works out of the box with taxonomy-based related posts and can optionally use OpenAI to re-rank the candidate posts for stronger semantic relevance.

Features:
- Automatic related posts on single posts
- Shortcode support: `[smart_related_posts]`
- Dynamic block for the block editor
- Multiple layouts: Grid, List, Minimal
- Style controls for accent color, thumbnails, image ratio, grid columns, and card radius
- Optional excerpt display
- Optional post date and category display
- Configurable fallback posts when no taxonomy match exists
- Manual include/exclude picker per post
- Optional AI re-ranking via OpenAI (disabled by default)
- Built-in caching for performance

Backwards compatibility:
- Also supports legacy shortcode `[kreativ_related_articles]`.

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

= Does this plugin require OpenAI? =
No. Core related-post matching works without OpenAI. AI re-ranking is optional.

= What data is sent to OpenAI? =
Only when AI re-ranking is enabled and an API key is set. The plugin sends:
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

This plugin can connect to OpenAI only if you enable AI re-ranking and provide an API key.

Service: OpenAI Chat Completions API  
Endpoint: `https://api.openai.com/v1/chat/completions`  
Purpose: Re-rank candidate related posts by semantic relevance  
Data sent: Source post title and excerpt, plus candidate post IDs, titles, and excerpts  
When sent: In a scheduled background task after you enable AI re-ranking, save an API key, and enable caching. Only published source posts are ranked. Visitors receive taxonomy-based results while the task is pending.

Policies:
- https://openai.com/policies/privacy-policy
- https://openai.com/policies/terms-of-use

== Changelog ==

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
