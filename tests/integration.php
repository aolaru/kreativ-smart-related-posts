<?php
/**
 * Run only against a disposable, installed WordPress site with this plugin active:
 * KSRP_WP_ROOT=/path/to/wordpress php tests/integration.php
 */
$root = getenv('KSRP_WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "Set KSRP_WP_ROOT to a disposable WordPress installation.\n");
    exit(1);
}
require $root . '/wp-load.php';
set_exception_handler(static function ($exception) {
    fwrite(STDERR, $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    exit(1);
});
if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Tests require WP_ENVIRONMENT_TYPE=local and create fixture content.');
}

$plugin = (new ReflectionClass(Kreativ_Smart_Related_Posts::class))->newInstanceWithoutConstructor();
$checks = [];
function kreasmre_check($condition, $label) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks[] = $label;
}
function kreasmre_call($method, ...$args) {
    global $plugin;
    $reflection = new ReflectionMethod($plugin, $method);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    return $reflection->invokeArgs($plugin, $args);
}
function kreasmre_key($post_id, $limit = 6) {
    global $plugin;
    return kreasmre_call('related_cache_key', $post_id, $limit, $plugin->settings(), kreasmre_call('get_manual_related_overrides', $post_id));
}
function kreasmre_post($title, $categories, $date = '2026-01-01 12:00:00', $status = 'publish', $author = 1) {
    $id = wp_insert_post([
        'post_title' => $title,
        'post_content' => 'An article used for related-post regression testing.',
        'post_excerpt' => 'A recognizable test excerpt.',
        'post_status' => $status,
        'post_author' => $author,
        'post_category' => $categories,
        'post_date' => $date,
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    return $id;
}

wp_set_current_user(1);
$migrated = $plugin->settings();
kreasmre_check($migrated['layout'] === 'list' && intval($migrated['posts_per']) === 4 && $migrated['api_key'] === 'legacy-test-key', 'Legacy settings migrate without losing values');
kreasmre_check($migrated['ai_provider'] === 'openai' && !kreasmre_call('ai_available', $migrated), 'Legacy provider selection is retained but cannot make AI requests');
kreasmre_check(shortcode_exists('kreasmre_related_posts') && shortcode_exists('smart_related_posts') && shortcode_exists('kreativ_related_articles'), 'New shortcode and legacy aliases are registered on upgraded sites');
$core_ai = $migrated;
$core_ai['enable_ai'] = 1;
$core_ai['ai_provider'] = 'wordpress';
kreasmre_check(!kreasmre_call('ai_available', $core_ai), 'WordPress AI client gracefully handles an unconfigured provider');
$initial = $plugin->settings();
$initial['enable_ai'] = 0;
$initial['cache_hours'] = 12;
update_option(Kreativ_Smart_Related_Posts::OPTION, $initial);
$suffix = wp_generate_password(8, false);
$cat = wp_insert_term('KSRP Main ' . $suffix, 'category')['term_id'];
$rare = wp_insert_term('KSRP Rare ' . $suffix, 'category')['term_id'];
$source = kreasmre_post('KSRP source ' . $suffix, [$cat, $rare]);
$strong = kreasmre_post('KSRP old strong ' . $suffix, [$cat, $rare], '2020-01-01 12:00:00');
update_post_meta($source, '_srp_manual_include_ids', (string) $strong);
kreasmre_check(kreasmre_call('get_manual_related_overrides', $source)['include'] === [$strong], 'Legacy post overrides remain available before editing');
$_POST = [
    'kreasmre_related_overrides_nonce' => wp_create_nonce('kreasmre_related_overrides_nonce'),
    'kreasmre_manual_include' => '',
    'kreasmre_manual_exclude' => '',
];
$plugin->save_related_overrides_meta($source, get_post($source), true);
kreasmre_check(kreasmre_call('get_manual_related_overrides', $source)['include'] === [], 'Clearing a legacy override does not resurrect the old value');
$_POST = [];
$weak = [];
for ($i = 0; $i < 65; $i++) {
    $weak[] = kreasmre_post('KSRP weak ' . $suffix . ' ' . $i, [$cat], '2026-02-01 12:00:00');
}
$draft = kreasmre_post('KSRP confidential ' . $suffix, [$cat], '2026-02-01 12:00:00', 'draft');
$contributor = wp_insert_user(['user_login' => 'kreasmre_contributor_' . $suffix, 'user_pass' => 'test-only', 'role' => 'contributor']);
kreasmre_check(!is_wp_error($contributor), 'Contributor fixture created');
wp_set_current_user($contributor);
kreasmre_check(kreasmre_call('get_post_picker_items', [$draft]) === [], 'Contributor cannot see another author\'s saved draft title');
$own_draft = kreasmre_post('KSRP own draft ' . $suffix, [$cat], '2026-02-01 12:00:00', 'draft', $contributor);
kreasmre_check(count(kreasmre_call('get_post_picker_items', [$own_draft])) === 1, 'Existing editable draft selection remains visible to its author');
$_GET = ['term' => $suffix, 'nonce' => wp_create_nonce('kreasmre_search_posts')];
$_REQUEST = $_GET;
$die_handler = static function () {
    return static function () { throw new RuntimeException('kreasmre_ajax_complete'); };
};
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', $die_handler);
ob_start();
try {
    $plugin->ajax_search_posts();
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'kreasmre_ajax_complete') {
        throw $exception;
    }
}
$payload = json_decode(ob_get_clean(), true);
remove_filter('wp_die_ajax_handler', $die_handler);
remove_filter('wp_doing_ajax', '__return_true');
kreasmre_check(!empty($payload['success']), 'Authenticated post search succeeds');
foreach ($payload['data'] as $item) {
    kreasmre_check(get_post_status($item['id']) === 'publish', 'Post search result is published: ' . $item['id']);
}
wp_set_current_user(1);

$candidates = kreasmre_call('get_taxonomy_candidates', $source, 60);
kreasmre_check(count($candidates) === 60 && $candidates[0] === $strong, 'Old strong match beats 65 newer weak matches before LIMIT');
$excluded = kreasmre_call('get_taxonomy_candidates', $source, 60, [$strong]);
kreasmre_check(!in_array($strong, $excluded, true), 'Candidate exclusions survive SQL relevance ranking');
kreasmre_check(empty($wpdb->last_error), 'Relevance SQL executes without database errors');

// WordPress's external-cache branch stores transients in the object-cache group.
wp_using_ext_object_cache(true);
$before_key = kreasmre_key($source);
kreasmre_call('get_related_posts', $source, 6);
kreasmre_check(is_array(wp_cache_get($before_key, 'transient')), 'Recommendations are cached through the external-object-cache branch');
wp_update_post(['ID' => $strong, 'post_status' => 'private']);
kreasmre_check(kreasmre_key($source) !== $before_key, 'Visibility change invalidates the cache namespace');
kreasmre_check(!in_array($strong, kreasmre_call('get_related_posts', $source, 6), true), 'Private post disappears despite old object-cache entry');
set_transient(kreasmre_key($source), ['ids' => [$draft, $weak[0]], 'ai_ready' => true], HOUR_IN_SECONDS);
kreasmre_check(!in_array($draft, kreasmre_call('get_related_posts', $source, 6), true), 'Cache hits revalidate publication status');
wp_update_post(['ID' => $strong, 'post_status' => 'publish']);
update_post_meta($source, Kreativ_Smart_Related_Posts::META_INCLUDE, (string) $weak[0]);
update_post_meta($source, Kreativ_Smart_Related_Posts::META_EXCLUDE, (string) $strong);
$manual = kreasmre_call('get_related_posts', $source, 6);
kreasmre_check($manual[0] === $weak[0] && !in_array($strong, $manual, true), 'Pinned selections and exclusions are preserved');
delete_post_meta($source, Kreativ_Smart_Related_Posts::META_INCLUDE);
delete_post_meta($source, Kreativ_Smart_Related_Posts::META_EXCLUDE);

$settings = $plugin->settings();
$settings['enable_ai'] = 1;
$settings['ai_provider'] = 'openai';
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
$key = kreasmre_key($source);
kreasmre_call('get_related_posts', $source, 6);
kreasmre_check(!kreasmre_call('ai_available', $settings), 'Legacy direct AI settings do not silently switch providers');
kreasmre_check(!wp_next_scheduled(Kreativ_Smart_Related_Posts::AI_HOOK, [$source, 6, $key]), 'Legacy direct AI settings do not schedule AI work');
$settings['ai_provider'] = 'wordpress';
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
kreasmre_check(!kreasmre_call('ai_available', $settings), 'An unconfigured WordPress AI provider remains unavailable');
$settings['cache_hours'] = 0;
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
kreasmre_call('get_related_posts', $source, 6);
kreasmre_check(!wp_next_scheduled(Kreativ_Smart_Related_Posts::AI_HOOK, [$source, 6, kreasmre_key($source)]), 'Disabled caching does not schedule AI requests');
$settings['enable_ai'] = 0;
$settings['cache_hours'] = 12;
$settings['layout'] = 'list';
$settings['show_excerpt'] = 1;
$settings['posts_per'] = 12;
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
$GLOBALS['post'] = get_post($source);
$html = do_blocks('<!-- wp:kreativ-smart-related-posts/related-posts /-->');
kreasmre_check(strpos($html, 'class="srp-grid"') !== false && strpos($html, 'class="srp-excerpt"') === false, 'Omitted block attributes use editor defaults rather than global settings');
kreasmre_check(substr_count($html, 'class="srp-card"') === 6, 'Default block post count is six');
$html = do_blocks('<!-- wp:kreativ-smart-related-posts/related-posts {"posts":999,"excerpt":true} /-->');
kreasmre_check(substr_count($html, 'class="srp-card"') === 12 && strpos($html, 'class="srp-excerpt"') !== false, 'Explicit block attributes work and post count is bounded');
kreasmre_check(substr_count($plugin->shortcode(['posts' => 999, 'layout' => 'grid']), 'class="srp-card"') === 12, 'Shortcode post count is bounded');

$main = new WP_Query(['p' => $source, 'post_type' => 'post']);
$GLOBALS['wp_query'] = $main;
$GLOBALS['wp_the_query'] = $main;
$main->the_post();
kreasmre_check(strpos($plugin->auto_append_related('Article body'), 'data-ksrp-related="1"') !== false, 'Main-loop content receives automatic recommendations');
foreach (['[kreasmre_related_posts]', '[smart_related_posts]', '[kreativ_related_articles]', '<!-- wp:kreativ-smart-related-posts/related-posts /-->'] as $placement) {
    wp_update_post(['ID' => $source, 'post_content' => 'Article body ' . $placement]);
    kreasmre_check($plugin->auto_append_related('Rendered article') === 'Rendered article', 'Manual placement suppresses auto-insertion: ' . $placement);
}
wp_update_post(['ID' => $source, 'post_content' => 'Article body']);
$already_rendered = 'Article <section data-ksrp-related="1">Existing section</section>';
kreasmre_check($plugin->auto_append_related($already_rendered) === $already_rendered, 'Rendered nested or synced placements suppress duplicates');
$GLOBALS['wp_query'] = new WP_Query(['p' => $weak[0]]);
$GLOBALS['wp_query']->the_post();
kreasmre_check($plugin->auto_append_related('Secondary content') === 'Secondary content', 'Secondary queries do not receive automatic recommendations');
$GLOBALS['wp_query'] = $main;
wp_reset_postdata();

$plugin->deactivate();
$pending = false;
foreach (_get_cron_array() as $events) {
    $pending = $pending || isset($events[Kreativ_Smart_Related_Posts::AI_HOOK]);
}
kreasmre_check(!$pending, 'Deactivation removes queued AI jobs');
kreasmre_check(empty($wpdb->last_error), 'No database errors during regression suite');
echo implode("\n", array_map(static function ($label) { return 'PASS: ' . $label; }, $checks)) . "\n";
echo count($checks) . " integration checks passed.\n";
