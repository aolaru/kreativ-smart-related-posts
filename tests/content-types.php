<?php
/** Run against an isolated local WordPress installation with the plugin active. */
$root = getenv('KSRP_WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "Set KSRP_WP_ROOT to a disposable WordPress installation.\n");
    exit(1);
}
require $root . '/wp-load.php';
if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Tests require WP_ENVIRONMENT_TYPE=local.');
}
wp_set_current_user(1);
register_post_type('kreasmre_book', ['public' => true, 'show_ui' => true, 'supports' => ['title', 'editor']]);
register_post_type('kreasmre_hidden', ['public' => false, 'show_ui' => true, 'supports' => ['title']]);
register_taxonomy('kreasmre_topic', ['post', 'page', 'kreasmre_book'], ['public' => true, 'hierarchical' => true]);
$term = wp_insert_term('Preview topic ' . wp_generate_password(8, false), 'kreasmre_topic');
if (is_wp_error($term)) { throw new RuntimeException($term->get_error_message()); }
function kreasmre_fixture($type, $title, $status = 'publish') {
    $id = wp_insert_post(['post_type' => $type, 'post_status' => $status, 'post_title' => $title], true);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    wp_set_object_terms($id, [(int) $GLOBALS['term']['term_id']], 'kreasmre_topic');
    return $id;
}
function kreasmre_assert($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS: $message\n";
}
function kreasmre_private($object, $method, ...$args) {
    $reflection = new ReflectionMethod($object, $method);
    if (PHP_VERSION_ID < 80100) { $reflection->setAccessible(true); }
    return $reflection->invokeArgs($object, $args);
}
$plugin = (new ReflectionClass(Kreativ_Smart_Related_Posts::class))->newInstanceWithoutConstructor();
$post = kreasmre_fixture('post', 'Source post');
$page = kreasmre_fixture('page', 'Source page');
$book = kreasmre_fixture('kreasmre_book', 'Published book');
$draft = kreasmre_fixture('kreasmre_book', 'Draft book', 'draft');
$hidden = kreasmre_fixture('kreasmre_hidden', 'Hidden item');
$settings = $plugin->settings();
kreasmre_assert($settings['source_types'] === ['post'] && $settings['target_types'] === ['post'], 'Existing installations default to posts');
kreasmre_assert(kreasmre_private($plugin, 'get_related_posts', $page, 6) === [], 'Pages are not enabled by default');
$input = $settings;
$input['source_types'] = ['post', 'page', 'kreasmre_book', 'kreasmre_hidden'];
$input['target_types'] = ['post', 'page', 'kreasmre_book', 'kreasmre_hidden'];
$settings = $plugin->sanitize_settings($input);
$settings['cache_hours'] = 0;
$settings['enable_ai'] = 0;
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
kreasmre_assert(!in_array('kreasmre_hidden', $settings['source_types'], true), 'Non-public types cannot be enabled');
$ids = kreasmre_private($plugin, 'get_related_posts', $page, 6);
kreasmre_assert(in_array($post, $ids, true) && in_array($book, $ids, true), 'Shared custom taxonomy matches posts and custom types from a page');
kreasmre_assert(!in_array($page, $ids, true) && !in_array($draft, $ids, true) && !in_array($hidden, $ids, true), 'Recommendations exclude the source, drafts and private types');
update_post_meta($page, Kreativ_Smart_Related_Posts::META_INCLUDE, (string) $book);
update_post_meta($page, Kreativ_Smart_Related_Posts::META_EXCLUDE, (string) $post);
$ids = kreasmre_private($plugin, 'preview_related_posts', $page, $settings);
kreasmre_assert(isset($ids[0]) && $ids[0] === $book && !in_array($post, $ids, true), 'Preview respects custom-type pins and exclusions');
$settings['target_types'] = ['kreasmre_book'];
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
$ids = kreasmre_private($plugin, 'get_related_posts', $page, 6);
kreasmre_assert($ids === [$book], 'Recommendations respect the selected target types');
$generation = get_option(Kreativ_Smart_Related_Posts::CACHE_GENERATION);
wp_update_post(['ID' => $book, 'post_title' => 'Updated book']);
kreasmre_assert(get_option(Kreativ_Smart_Related_Posts::CACHE_GENERATION) !== $generation, 'Saving a selected custom type invalidates cached results');
$generation = get_option(Kreativ_Smart_Related_Posts::CACHE_GENERATION);
wp_set_object_terms($book, [], 'kreasmre_topic');
kreasmre_assert(get_option(Kreativ_Smart_Related_Posts::CACHE_GENERATION) !== $generation, 'Changing custom taxonomy terms invalidates cached results');
$unmatched_page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Unmatched page'], true);
if (is_wp_error($unmatched_page)) { throw new RuntimeException($unmatched_page->get_error_message()); }
kreasmre_assert(kreasmre_private($plugin, 'get_related_posts', $unmatched_page, 6) === [], 'A page without shared taxonomy terms has no matches by default');
$settings['fallback_mode'] = 'latest';
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
kreasmre_assert(in_array($book, kreasmre_private($plugin, 'get_related_posts', $unmatched_page, 6), true), 'Fallback recommendations work for pages without taxonomy terms');
$settings['target_types'] = [];
update_option(Kreativ_Smart_Related_Posts::OPTION, $settings);
kreasmre_assert(kreasmre_private($plugin, 'get_related_posts', $page, 6) === [], 'An empty target selection does not default to posts');
echo "Content-type regression checks passed.\n";
