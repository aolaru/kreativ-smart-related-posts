<?php
// Isolated tests of the settings preview; no WordPress installation or network required.
define('ABSPATH', __DIR__ . '/');
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function add_action(...$args) {}
function add_filter(...$args) {}
function remove_filter(...$args) {}
function add_shortcode(...$args) {}
function add_menu_page(...$args) { $GLOBALS['registered_menu'] = $args; }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html_e($text, $domain = null) { echo esc_html__($text, $domain); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return esc_html($text); }
function esc_url($url) { return esc_html($url); }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function current_user_can($capability) { return $GLOBALS['can_manage']; }
function admin_url($path) { return '/wp-admin/' . $path; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid' && $action === 'kreasmre_preview'; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return $value; }
function absint($value) { return abs((int) $value); }
function wp_nonce_field($action, $name) { echo '<input name="' . esc_attr($name) . '" value="valid">'; }
function selected($value, $expected) { if ($value == $expected) { echo 'selected'; } }
function get_post_type($id) { return $GLOBALS['types'][$id] ?? 'post'; }
function get_post_types($args, $output) { return $GLOBALS['post_types']; }
function is_post_type_viewable($type) { return true; }
function get_object_taxonomies($type, $output) {
    return $GLOBALS['taxonomies'][$type] ?? [];
}
function get_post_status($id) { return isset($GLOBALS['published'][$id]) ? 'publish' : 'draft'; }
function get_the_title($post) { return 'Post ' . (is_object($post) ? $post->ID : $post); }
function get_permalink($id) { return '/post/' . $id; }
function settings_fields($group) {}
function do_settings_sections($page) {}
function submit_button() {}
function tag_escape($value) { return $value; }
function wp_get_post_terms($id, $taxonomy, $args) { return $GLOBALS['terms'][$id][$taxonomy] ?? []; }
function is_wp_error($value) { return false; }
function metadata_exists($type, $id, $key) { return isset($GLOBALS['meta'][$id][$key]); }
function get_post_meta($id, $key, $single) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function get_post($id) { return (object) ['ID' => $id, 'post_author' => 1]; }
function get_posts($args) {
    if (!isset($args['fields'])) {
        return array_map(static function ($id) { return (object) ['ID' => $id]; }, $GLOBALS['recent']);
    }
    $ids = $args['post__in'] ?? $GLOBALS['fallback'];
    $exclude = $args['post__not_in'] ?? [];
    $types = (array) ($args['post_type'] ?? 'post');
    return array_values(array_filter($ids, static function ($id) use ($exclude, $types) {
        return isset($GLOBALS['published'][$id]) && in_array(get_post_type($id), $types, true) && !in_array($id, $exclude, true);
    }));
}
class WP_Query {
    public $posts;
    public function __construct($args) {
        $source = $args['kreasmre_source_id'];
        $this->posts = array_values(array_filter($GLOBALS['matches'][$source] ?? [], static function ($id) use ($args) {
            return isset($GLOBALS['published'][$id]) && in_array(get_post_type($id), $args['post_type'], true) && !in_array($id, $args['post__not_in'], true);
        }));
    }
}
class Kreasmre_Test_Wpdb {
    public $term_relationships = 'term_relationships';
    public $term_taxonomy = 'term_taxonomy';
    public $posts = 'posts';
    public function prepare($query, $value) { return str_replace('%s', "'" . $value . "'", $query); }
}
$GLOBALS['wpdb'] = new Kreasmre_Test_Wpdb();
$GLOBALS['post_types'] = ['post' => (object) [], 'page' => (object) [], 'book' => (object) []];
$GLOBALS['types'] = [];
$category = (object) ['name' => 'category', 'public' => true, 'hierarchical' => true, 'object_type' => ['post', 'book']];
$GLOBALS['taxonomies'] = ['post' => [$category], 'book' => [$category], 'page' => []];
$GLOBALS['terms'] = [1 => ['category' => [2], 'post_tag' => []], 5 => ['category' => [], 'post_tag' => []]];
$GLOBALS['matches'] = [1 => [2, 3, 4]];
$GLOBALS['published'] = [1 => true, 2 => true, 3 => true, 5 => true];
$GLOBALS['fallback'] = [3, 2];
$GLOBALS['meta'] = [];
$GLOBALS['options'] = [];
$GLOBALS['recent'] = [1, 5];
$GLOBALS['can_manage'] = true;
require dirname(__DIR__) . '/kreativ_smart_related_posts.php';
$plugin = (new ReflectionClass(Kreativ_Smart_Related_Posts::class))->newInstanceWithoutConstructor();
$plugin->admin_menu();
if ($GLOBALS['registered_menu'][1] !== 'Smart Related Posts'
    || $GLOBALS['registered_menu'][2] !== 'manage_options'
    || $GLOBALS['registered_menu'][3] !== 'kreativ-smart-related-posts'
    || $GLOBALS['registered_menu'][6] !== 81) {
    throw new RuntimeException('Settings must be registered as a top-level admin menu.');
}
$preview = new ReflectionMethod($plugin, 'preview_related_posts');
if (PHP_VERSION_ID < 80100) {
    $preview->setAccessible(true);
}
$settings = ['posts_per' => 2, 'fallback_mode' => 'none', 'enable_ai' => 1];
if ($preview->invoke($plugin, 1, $settings) !== [2, 3]) {
    throw new RuntimeException('Preview must use ranked published results and the configured limit.');
}
$GLOBALS['meta'][1] = [Kreativ_Smart_Related_Posts::META_INCLUDE => '3', Kreativ_Smart_Related_Posts::META_EXCLUDE => '2'];
if ($preview->invoke($plugin, 1, $settings) !== [3]) {
    throw new RuntimeException('Preview must respect pinned and excluded posts.');
}
if ($preview->invoke($plugin, 5, $settings) !== []) {
    throw new RuntimeException('Preview must handle posts without matching terms.');
}
$settings['fallback_mode'] = 'latest';
if ($preview->invoke($plugin, 5, $settings) !== [3, 2]) {
    throw new RuntimeException('Preview must honor the configured fallback.');
}
$GLOBALS['options'][Kreativ_Smart_Related_Posts::OPTION] = ['layout' => 'minimal', 'posts_per' => 2];
$_GET = ['kreasmre_preview_post' => '1', 'kreasmre_preview_nonce' => 'valid'];
ob_start();
$plugin->settings_page();
$html = ob_get_clean();
if (strpos($html, 'action="/wp-admin/admin.php"') === false
    || strpos($html, 'Preview related posts') === false || strpos($html, 'srp-minimal') === false
    || strpos($html, 'Post 3') === false || strpos($html, 'Post 2') !== false) {
    throw new RuntimeException('Settings page must render the selected post preview with manual exclusions.');
}
$_GET = ['kreasmre_preview_post' => '5', 'kreasmre_preview_nonce' => 'valid'];
ob_start();
$plugin->settings_page();
if (strpos(ob_get_clean(), 'No related content found') === false) {
    throw new RuntimeException('Settings page must explain empty preview results.');
}
$_GET = ['kreasmre_preview_post' => '1', 'kreasmre_preview_nonce' => 'invalid'];
ob_start();
$plugin->settings_page();
if (strpos(ob_get_clean(), 'That post is not available for preview') === false) {
    throw new RuntimeException('Settings page must reject invalid preview nonces.');
}
$GLOBALS['recent'] = [];
$_GET = [];
ob_start();
$plugin->settings_page();
if (strpos(ob_get_clean(), 'Select a content type') === false) {
    throw new RuntimeException('Settings page must guide sites without published posts.');
}
$GLOBALS['can_manage'] = false;
ob_start();
$plugin->settings_page();
if (ob_get_clean() !== '') {
    throw new RuntimeException('Settings page must require management capability.');
}
$GLOBALS['can_manage'] = true;
$GLOBALS['types'][6] = 'page';
$GLOBALS['types'][7] = 'book';
$GLOBALS['published'][6] = true;
$GLOBALS['published'][7] = true;
$GLOBALS['matches'][1] = [2, 7];
$GLOBALS['options'][Kreativ_Smart_Related_Posts::OPTION] = [
    'source_types' => ['post', 'page'], 'target_types' => ['book'], 'posts_per' => 6, 'fallback_mode' => 'none',
];
$cross_settings = $plugin->settings();
$GLOBALS['meta'] = [];
if ($preview->invoke($plugin, 1, $cross_settings) !== [7]) {
    throw new RuntimeException('Preview must recommend only selected public target types.');
}
if ($preview->invoke($plugin, 6, $cross_settings) !== []) {
    throw new RuntimeException('A page without shared terms must not produce taxonomy matches.');
}
$cross_settings['fallback_mode'] = 'latest';
$GLOBALS['fallback'] = [7, 2];
if ($preview->invoke($plugin, 6, $cross_settings) !== [7]) {
    throw new RuntimeException('Pages must use the selected target types for fallback recommendations.');
}
$cross_settings['target_types'] = [];
if ($preview->invoke($plugin, 6, $cross_settings) !== []) {
    throw new RuntimeException('An empty target selection must not implicitly enable posts.');
}
echo "Preview checks passed without scheduling AI or writing cache.\n";
