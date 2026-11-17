<?php
// Isolated tests of the settings preview; no WordPress installation or network required.
define('ABSPATH', __DIR__ . '/');
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function add_action(...$args) {}
function add_filter(...$args) {}
function remove_filter(...$args) {}
function add_shortcode(...$args) {}
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
function get_post_type($id) { return 'post'; }
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
    return array_values(array_filter($ids, static function ($id) use ($exclude) {
        return isset($GLOBALS['published'][$id]) && !in_array($id, $exclude, true);
    }));
}
class WP_Query {
    public $posts;
    public function __construct($args) {
        $source = $args['kreasmre_source_id'];
        $this->posts = array_values(array_filter($GLOBALS['matches'][$source] ?? [], static function ($id) use ($args) {
            return isset($GLOBALS['published'][$id]) && !in_array($id, $args['post__not_in'], true);
        }));
    }
}
$GLOBALS['wpdb'] = (object) ['term_relationships' => 'term_relationships', 'term_taxonomy' => 'term_taxonomy', 'posts' => 'posts'];
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
if (strpos($html, 'Preview related posts') === false || strpos($html, 'srp-minimal') === false
    || strpos($html, 'Post 3') === false || strpos($html, 'Post 2') !== false) {
    throw new RuntimeException('Settings page must render the selected post preview with manual exclusions.');
}
$_GET = ['kreasmre_preview_post' => '5', 'kreasmre_preview_nonce' => 'valid'];
ob_start();
$plugin->settings_page();
if (strpos(ob_get_clean(), 'No related posts found') === false) {
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
if (strpos(ob_get_clean(), 'Publish a post first') === false) {
    throw new RuntimeException('Settings page must guide sites without published posts.');
}
$GLOBALS['can_manage'] = false;
ob_start();
$plugin->settings_page();
if (ob_get_clean() !== '') {
    throw new RuntimeException('Settings page must require management capability.');
}
echo "Preview checks passed without scheduling AI or writing cache.\n";
