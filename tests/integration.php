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
function ksrp_check($condition, $label) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks[] = $label;
}
function ksrp_call($method, ...$args) {
    global $plugin;
    $reflection = new ReflectionMethod($plugin, $method);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    return $reflection->invokeArgs($plugin, $args);
}
function ksrp_key($post_id, $limit = 6) {
    global $plugin;
    return ksrp_call('related_cache_key', $post_id, $limit, $plugin->settings(), ksrp_call('get_manual_related_overrides', $post_id));
}
function ksrp_post($title, $categories, $date = '2026-01-01 12:00:00', $status = 'publish', $author = 1) {
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
$initial = $plugin->settings();
$initial['enable_ai'] = 0;
$initial['cache_hours'] = 12;
update_option(Kreativ_Smart_Related_Posts::OPTION, $initial);
$suffix = wp_generate_password(8, false);
$cat = wp_insert_term('KSRP Main ' . $suffix, 'category')['term_id'];
$rare = wp_insert_term('KSRP Rare ' . $suffix, 'category')['term_id'];
$source = ksrp_post('KSRP source ' . $suffix, [$cat, $rare]);
$strong = ksrp_post('KSRP old strong ' . $suffix, [$cat, $rare], '2020-01-01 12:00:00');
$weak = [];
for ($i = 0; $i < 65; $i++) {
    $weak[] = ksrp_post('KSRP weak ' . $suffix . ' ' . $i, [$cat], '2026-02-01 12:00:00');
}
$draft = ksrp_post('KSRP confidential ' . $suffix, [$cat], '2026-02-01 12:00:00', 'draft');
$contributor = wp_insert_user(['user_login' => 'ksrp_contributor_' . $suffix, 'user_pass' => 'test-only', 'role' => 'contributor']);
ksrp_check(!is_wp_error($contributor), 'Contributor fixture created');
wp_set_current_user($contributor);
ksrp_check(ksrp_call('get_post_picker_items', [$draft]) === [], 'Contributor cannot see another author\'s saved draft title');
$own_draft = ksrp_post('KSRP own draft ' . $suffix, [$cat], '2026-02-01 12:00:00', 'draft', $contributor);
ksrp_check(count(ksrp_call('get_post_picker_items', [$own_draft])) === 1, 'Existing editable draft selection remains visible to its author');
$_GET = ['term' => $suffix, 'nonce' => wp_create_nonce('srp_search_posts')];
$_REQUEST = $_GET;
$die_handler = static function () {
    return static function () { throw new RuntimeException('ksrp_ajax_complete'); };
};
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', $die_handler);
ob_start();
try {
    $plugin->ajax_search_posts();
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'ksrp_ajax_complete') {
        throw $exception;
    }
}
$payload = json_decode(ob_get_clean(), true);
remove_filter('wp_die_ajax_handler', $die_handler);
remove_filter('wp_doing_ajax', '__return_true');
ksrp_check(!empty($payload['success']), 'Authenticated post search succeeds');
foreach ($payload['data'] as $item) {
    ksrp_check(get_post_status($item['id']) === 'publish', 'Post search result is published: ' . $item['id']);
}
wp_set_current_user(1);

$candidates = ksrp_call('get_taxonomy_candidates', $source, 60);
ksrp_check(count($candidates) === 60 && $candidates[0] === $strong, 'Old strong match beats 65 newer weak matches before LIMIT');
$excluded = ksrp_call('get_taxonomy_candidates', $source, 60, [$strong]);
ksrp_check(!in_array($strong, $excluded, true), 'Candidate exclusions survive SQL relevance ranking');
ksrp_check(empty($wpdb->last_error), 'Relevance SQL executes without database errors');

// WordPress's external-cache branch stores transients in the object-cache group.
wp_using_ext_object_cache(true);
$before_key = ksrp_key($source);
ksrp_call('get_related_posts', $source, 6);
ksrp_check(is_array(wp_cache_get($before_key, 'transient')), 'Recommendations are cached through the external-object-cache branch');
wp_update_post(['ID' => $strong, 'post_status' => 'private']);
ksrp_check(ksrp_key($source) !== $before_key, 'Visibility change invalidates the cache namespace');
ksrp_check(!in_array($strong, ksrp_call('get_related_posts', $source, 6), true), 'Private post disappears despite old object-cache entry');
set_transient(ksrp_key($source), ['ids' => [$draft, $weak[0]], 'ai_ready' => true], HOUR_IN_SECONDS);
ksrp_check(!in_array($draft, ksrp_call('get_related_posts', $source, 6), true), 'Cache hits revalidate publication status');
wp_update_post(['ID' => $strong, 'post_status' => 'publish']);
update_post_meta($source, Kreativ_Smart_Related_Posts::META_INCLUDE, (string) $weak[0]);
update_post_meta($source, Kreativ_Smart_Related_Posts::META_EXCLUDE, (string) $strong);
$manual = ksrp_call('get_related_posts', $source, 6);
ksrp_check($manual[0] === $weak[0] && !in_array($strong, $manual, true), 'Pinned selections and exclusions are preserved');
delete_post_meta($source, Kreativ_Smart_Related_Posts::META_INCLUDE);
delete_post_meta($source, Kreativ_Smart_Related_Posts::META_EXCLUDE);

$settings = $plugin->settings();
$settings['enable_ai'] = 1;
$settings['api_key'] = 'mocked-test-key';
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
$calls = 0;
$fail_http = false;
$http = static function ($preempt, $args, $url) use (&$calls, &$fail_http, $weak, $strong, $draft) {
    if ($url !== 'https://api.openai.com/v1/chat/completions') {
        return $preempt;
    }
    $calls++;
    if ($fail_http) {
        return new WP_Error('ksrp_mock_timeout', 'Simulated API timeout');
    }
    return ['headers' => [], 'response' => ['code' => 200, 'message' => 'OK'], 'body' => wp_json_encode([
        'choices' => [['message' => ['content' => wp_json_encode([$weak[64], $strong, $draft])]]],
    ])];
};
add_filter('pre_http_request', $http, 10, 3);
$key = ksrp_key($source);
ksrp_call('get_related_posts', $source, 6);
ksrp_call('get_related_posts', $source, 6);
ksrp_check($calls === 0, 'Visitor requests never call the AI API');
$job_args = [$source, 6, $key];
ksrp_check((bool) wp_next_scheduled(Kreativ_Smart_Related_Posts::AI_HOOK, $job_args), 'Visitor request schedules background AI work');
$matching_jobs = 0;
foreach (_get_cron_array() as $events) {
    foreach ($events[Kreativ_Smart_Related_Posts::AI_HOOK] ?? [] as $event) {
        $matching_jobs += $event['args'] === $job_args ? 1 : 0;
    }
}
ksrp_check($matching_jobs === 1, 'Repeated visitors schedule only one matching job');
$lock = 'ksrp_ai_lock_' . md5($key);
add_option($lock, time() + 120, '', false);
$plugin->refresh_ai_ranking(...$job_args);
ksrp_check($calls === 0, 'Worker lock prevents duplicate external requests');
update_option($lock, time() - 1, false);
do_action(Kreativ_Smart_Related_Posts::AI_HOOK, ...$job_args);
ksrp_check($calls === 1 && ksrp_call('get_related_posts', $source, 6)[0] === $weak[64], 'Background worker stores valid AI ordering');
ksrp_check(get_option($lock) === false, 'Worker recovers an expired lock and releases it after success');
ksrp_check(!in_array($draft, ksrp_call('get_related_posts', $source, 6), true), 'Non-candidate AI response IDs are discarded');
$plugin->refresh_ai_ranking(...$job_args);
ksrp_check($calls === 1, 'Completed jobs are idempotent');
wp_update_post(['ID' => $source, 'post_title' => 'KSRP source edited ' . $suffix]);
$plugin->refresh_ai_ranking(...$job_args);
ksrp_check($calls === 1, 'Stale jobs skip changed content');
$fail_http = true;
$key = ksrp_key($source);
$basic = ksrp_call('get_related_posts', $source, 6);
$plugin->refresh_ai_ranking($source, 6, $key);
$plugin->refresh_ai_ranking($source, 6, $key);
ksrp_check($calls === 2 && ksrp_call('get_related_posts', $source, 6) === $basic, 'AI failures retain basic results and enforce retry cooldown');
ksrp_check(get_option('ksrp_ai_lock_' . md5($key)) === false, 'Worker releases its lock after failure');
$fail_http = false;
$settings['model'] = 'mock-stale-job';
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
$key = ksrp_key($source);
ksrp_call('get_related_posts', $source, 6);
$edit_during_request = static function ($response, $args, $url) use ($source) {
    if ($url === 'https://api.openai.com/v1/chat/completions') {
        wp_update_post(['ID' => $source, 'post_title' => 'Edited during AI request']);
    }
    return $response;
};
add_filter('pre_http_request', $edit_during_request, 11, 3);
$plugin->refresh_ai_ranking($source, 6, $key);
remove_filter('pre_http_request', $edit_during_request, 11);
ksrp_check(empty(get_transient($key)['ai_ready']), 'Content changed during an API request is not overwritten by stale AI results');
$calls_before = $calls;
wp_update_post(['ID' => $source, 'post_status' => 'draft']);
$key = ksrp_key($source);
ksrp_call('get_related_posts', $source, 6);
$plugin->refresh_ai_ranking($source, 6, $key);
ksrp_check($calls === $calls_before && !wp_next_scheduled(Kreativ_Smart_Related_Posts::AI_HOOK, [$source, 6, $key]), 'Draft source posts never schedule or call external AI');
wp_update_post(['ID' => $source, 'post_status' => 'publish']);
remove_filter('pre_http_request', $http, 10);

$settings['cache_hours'] = 0;
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
ksrp_call('get_related_posts', $source, 6);
ksrp_check(!wp_next_scheduled(Kreativ_Smart_Related_Posts::AI_HOOK, [$source, 6, ksrp_key($source)]), 'Disabled caching does not schedule AI requests');
$settings['enable_ai'] = 0;
$settings['cache_hours'] = 12;
$settings['layout'] = 'list';
$settings['show_excerpt'] = 1;
$settings['posts_per'] = 12;
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
$GLOBALS['post'] = get_post($source);
$html = do_blocks('<!-- wp:kreativ-smart-related-posts/related-posts /-->');
ksrp_check(strpos($html, 'class="srp-grid"') !== false && strpos($html, 'class="srp-excerpt"') === false, 'Omitted block attributes use editor defaults rather than global settings');
ksrp_check(substr_count($html, 'class="srp-card"') === 6, 'Default block post count is six');
$html = do_blocks('<!-- wp:kreativ-smart-related-posts/related-posts {"posts":999,"excerpt":true} /-->');
ksrp_check(substr_count($html, 'class="srp-card"') === 12 && strpos($html, 'class="srp-excerpt"') !== false, 'Explicit block attributes work and post count is bounded');
ksrp_check(substr_count($plugin->shortcode(['posts' => 999, 'layout' => 'grid']), 'class="srp-card"') === 12, 'Shortcode post count is bounded');

$main = new WP_Query(['p' => $source, 'post_type' => 'post']);
$GLOBALS['wp_query'] = $main;
$GLOBALS['wp_the_query'] = $main;
$main->the_post();
ksrp_check(strpos($plugin->auto_append_related('Article body'), 'data-ksrp-related="1"') !== false, 'Main-loop content receives automatic recommendations');
foreach (['[smart_related_posts]', '[kreativ_related_articles]', '<!-- wp:kreativ-smart-related-posts/related-posts /-->'] as $placement) {
    wp_update_post(['ID' => $source, 'post_content' => 'Article body ' . $placement]);
    ksrp_check($plugin->auto_append_related('Rendered article') === 'Rendered article', 'Manual placement suppresses auto-insertion: ' . $placement);
}
wp_update_post(['ID' => $source, 'post_content' => 'Article body']);
$already_rendered = 'Article <section data-ksrp-related="1">Existing section</section>';
ksrp_check($plugin->auto_append_related($already_rendered) === $already_rendered, 'Rendered nested or synced placements suppress duplicates');
$GLOBALS['wp_query'] = new WP_Query(['p' => $weak[0]]);
$GLOBALS['wp_query']->the_post();
ksrp_check($plugin->auto_append_related('Secondary content') === 'Secondary content', 'Secondary queries do not receive automatic recommendations');
$GLOBALS['wp_query'] = $main;
wp_reset_postdata();

$plugin->deactivate();
$pending = false;
foreach (_get_cron_array() as $events) {
    $pending = $pending || isset($events[Kreativ_Smart_Related_Posts::AI_HOOK]);
}
ksrp_check(!$pending, 'Deactivation removes queued AI jobs');
ksrp_check(empty($wpdb->last_error), 'No database errors during regression suite');
echo implode("\n", array_map(static function ($label) { return 'PASS: ' . $label; }, $checks)) . "\n";
echo count($checks) . " integration checks passed.\n";
