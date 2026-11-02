<?php
// Standalone contract test for the WordPress AI Client path; makes no network calls.
define('ABSPATH', __DIR__ . '/');
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function add_action(...$args) {}
function add_filter(...$args) {}
function add_shortcode(...$args) {}
function get_option(...$args) { return false; }
function get_post($id) { return (object) ['ID' => $id]; }
function get_the_title($post) { return 'Title ' . (is_object($post) ? $post->ID : $post); }
function get_the_excerpt($post) { return 'Excerpt ' . (is_object($post) ? $post->ID : $post); }
function wp_trim_words($text, $count) { return $text; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error {}
class Kreasmre_Test_AI_Client {
    public static $result = '[3,999,2]';
    public static $prompt = '';
    public static $supported = true;
    public function with_text($text) { self::$prompt = $text; return $this; }
    public function using_temperature($temperature) { return $this; }
    public function is_supported_for_text_generation() { return self::$supported; }
    public function generate_text() { return self::$result; }
}
function wp_ai_client_prompt() { return new Kreasmre_Test_AI_Client(); }
require dirname(__DIR__) . '/kreativ_smart_related_posts.php';
$plugin = (new ReflectionClass(Kreativ_Smart_Related_Posts::class))->newInstanceWithoutConstructor();
$rank = new ReflectionMethod($plugin, 'ai_rerank');
$available = new ReflectionMethod($plugin, 'ai_available');
if (PHP_VERSION_ID < 80100) {
    $rank->setAccessible(true);
    $available->setAccessible(true);
}
$settings = ['enable_ai' => 1, 'ai_provider' => 'wordpress', 'api_key' => ''];
if (!$available->invoke($plugin, $settings)) {
    throw new RuntimeException('Configured WordPress AI provider should be available.');
}
$result = $rank->invoke($plugin, 1, [2, 3, 4], 3, '', '', 'wordpress');
if ($result !== [3, 2, 4] || strpos(Kreasmre_Test_AI_Client::$prompt, 'Title 1') === false) {
    throw new RuntimeException('AI response validation or prompt construction failed.');
}
Kreasmre_Test_AI_Client::$result = new WP_Error();
if ($rank->invoke($plugin, 1, [2, 3, 4], 3, '', '', 'wordpress') !== []) {
    throw new RuntimeException('AI errors should fall back to taxonomy ranking.');
}
Kreasmre_Test_AI_Client::$supported = false;
if ($available->invoke($plugin, $settings)) {
    throw new RuntimeException('Unsupported providers must not schedule requests.');
}
echo "WordPress AI Client contract checks passed.\n";
