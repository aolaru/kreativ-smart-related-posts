<?php
// Standalone contract test for the WordPress AI Client path; makes no network calls.
define('ABSPATH', __DIR__ . '/');
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function add_action(...$args) {}
function add_filter(...$args) {}
function add_shortcode(...$args) {}
function get_option($name, $default = false) { return $GLOBALS['kreasmre_test_options'][$name] ?? $default; }
function __($text, $domain = null) { return $text; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
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
$legacy_settings = ['enable_ai' => 1, 'ai_provider' => 'openai', 'api_key' => 'old-key'];
if ($available->invoke($plugin, $legacy_settings)) {
    throw new RuntimeException('Legacy direct AI settings must not silently switch providers.');
}
$GLOBALS['kreasmre_test_options'][Kreativ_Smart_Related_Posts::OPTION] = ['enable_ai' => 1, 'api_key' => 'old-key'];
$old_settings = $plugin->settings();
if ($old_settings['ai_provider'] !== 'openai' || $available->invoke($plugin, $old_settings)) {
    throw new RuntimeException('Pre-1.2.2 settings must not silently switch providers.');
}
$disabled = $plugin->sanitize_settings([]);
$enabled = $plugin->sanitize_settings(['enable_ai' => 1]);
if ($disabled['enable_ai'] || $disabled['ai_provider'] !== 'wordpress'
    || !$enabled['enable_ai'] || $enabled['ai_provider'] !== 'wordpress'
    || isset($enabled['api_key']) || isset($enabled['model'])) {
    throw new RuntimeException('Saving settings must require opt-in and discard direct integration settings.');
}
$result = $rank->invoke($plugin, 1, [2, 3, 4], 3);
if ($result !== [3, 2, 4] || strpos(Kreasmre_Test_AI_Client::$prompt, 'Title 1') === false) {
    throw new RuntimeException('AI response validation or prompt construction failed.');
}
Kreasmre_Test_AI_Client::$result = new WP_Error();
if ($rank->invoke($plugin, 1, [2, 3, 4], 3) !== []) {
    throw new RuntimeException('AI errors should fall back to taxonomy ranking.');
}
Kreasmre_Test_AI_Client::$supported = false;
if ($available->invoke($plugin, $settings)) {
    throw new RuntimeException('Unsupported providers must not schedule requests.');
}
echo "WordPress AI Client contract checks passed.\n";
