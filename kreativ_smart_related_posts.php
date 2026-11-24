<?php
/**
 * Plugin Name: Kreativ Smart Related Posts
 * Plugin URI: https://kreativwp.com/plugins/kreativ-smart-related-posts/
 * Description: Display related content using shared taxonomy terms, with optional AI re-ranking. Includes auto-insert, shortcode, and a dynamic block.
 * Version: 1.3.0
 * Author: Andrei Olaru
 * Author URI: https://kreativwp.com/
 * License: GPL-2.0+
 * Text Domain: kreativ-smart-related-posts
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kreativ_Smart_Related_Posts {
    const OPTION = 'kreasmre_settings';
    const VERSION = '1.3.0';
    const CACHE_GENERATION = 'kreasmre_cache_generation';
    const AI_HOOK = 'kreasmre_refresh_ai_ranking';
    const META_INCLUDE = '_kreasmre_manual_include_ids';
    const META_EXCLUDE = '_kreasmre_manual_exclude_ids';

    private $rendering_excerpt = false;

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        add_action(self::AI_HOOK, [$this, 'refresh_ai_ranking'], 10, 3);
        add_action('update_option_' . self::OPTION, [$this, 'settings_changed'], 10, 2);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_assets']);
        add_action('add_meta_boxes', [$this, 'register_related_overrides_metabox']);
        add_action('wp_ajax_kreasmre_search_posts', [$this, 'ajax_search_posts']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('save_post', [$this, 'flush_related_cache_on_save'], 10, 3);
        add_action('save_post', [$this, 'save_related_overrides_meta'], 20, 3);
        add_action('deleted_post', [$this, 'flush_related_cache_on_delete'], 10, 2);
        add_action('set_object_terms', [$this, 'flush_related_cache_on_terms'], 10, 6);
        add_action('delete_term', [$this, 'flush_related_cache_on_term_delete'], 10, 3);
        add_filter('the_content', [$this, 'auto_append_related']);

        add_action('init', [$this, 'migrate_legacy_settings'], 1);
        add_shortcode('kreasmre_related_posts', [$this, 'shortcode']);
        // Register legacy aliases only on upgraded sites, without replacing another plugin's shortcode.
        if (is_array(get_option('srp_settings'))) {
            foreach (['smart_related_posts', 'kreativ_related_articles'] as $legacy_shortcode) {
                if (!shortcode_exists($legacy_shortcode)) {
                    add_shortcode($legacy_shortcode, [$this, 'shortcode']);
                }
            }
        }

        add_action('init', [$this, 'register_block']);
    }

    public function activate() {
        $this->migrate_legacy_settings();
        $this->flush_related_cache();
        $defaults = [
            'enable_ai'      => 0,
            'source_types'   => ['post'],
            'target_types'   => ['post'],
            'layout'         => 'grid',
            'posts_per'      => 6,
            'show_excerpt'   => 0,
            'auto_insert'    => 1,
            'title'          => __('Related Posts', 'kreativ-smart-related-posts'),
            'cache_hours'    => 12,
            'accent_color'   => '#00C2FF',
            'show_thumbnail' => 1,
            'image_ratio'    => 'wide',
            'card_radius'    => 14,
            'grid_columns'   => 3,
            'fallback_mode'  => 'none',
            'link_target'    => 'same',
            'show_date'      => 0,
            'show_category'  => 0,
            'heading_tag'    => 'h3',
        ];

        $existing = get_option(self::OPTION);
        if (!is_array($existing)) {
            update_option(self::OPTION, $defaults);
            return;
        }

        update_option(self::OPTION, wp_parse_args($existing, $defaults));
    }

    public function migrate_legacy_settings() {
        if (get_option(self::OPTION, false) !== false) {
            return;
        }
        $legacy = get_option('srp_settings', false);
        if (is_array($legacy)) {
            add_option(self::OPTION, $legacy);
            wp_unschedule_hook('ksrp_refresh_ai_ranking');
        }
    }

    public function settings() {
        $defaults = [
            'enable_ai'      => 0,
            'source_types'   => ['post'],
            'target_types'   => ['post'],
            'ai_provider'    => 'wordpress',
            'layout'         => 'grid',
            'posts_per'      => 6,
            'show_excerpt'   => 0,
            'auto_insert'    => 1,
            'title'          => __('Related Posts', 'kreativ-smart-related-posts'),
            'cache_hours'    => 12,
            'accent_color'   => '#00C2FF',
            'show_thumbnail' => 1,
            'image_ratio'    => 'wide',
            'card_radius'    => 14,
            'grid_columns'   => 3,
            'fallback_mode'  => 'none',
            'link_target'    => 'same',
            'show_date'      => 0,
            'show_category'  => 0,
            'heading_tag'    => 'h3',
        ];

        $opt = get_option(self::OPTION, []);
        if (!is_array($opt)) {
            return $defaults;
        }

        // Pre-1.2.2 settings used direct OpenAI without a provider field.
        if (!isset($opt['ai_provider']) && !empty($opt['api_key'])) {
            $opt['ai_provider'] = 'openai';
        }

        return wp_parse_args($opt, $defaults);
    }

    private function public_post_types() {
        $types = get_post_types(['public' => true], 'objects');
        unset($types['attachment']);
        return array_filter($types, 'is_post_type_viewable');
    }

    private function enabled_types($opt, $key) {
        $values = isset($opt[$key]) && is_array($opt[$key]) ? $opt[$key] : ['post'];
        return array_values(array_intersect(array_keys($this->public_post_types()), array_filter($values, 'is_string')));
    }

    private function source_enabled($id, $opt) {
        return in_array(get_post_type($id), $this->enabled_types($opt, 'source_types'), true);
    }

    public function admin_menu() {
        add_menu_page(
            __('Kreativ Smart Related Posts', 'kreativ-smart-related-posts'),
            __('Smart Related Posts', 'kreativ-smart-related-posts'),
            'manage_options',
            'kreativ-smart-related-posts',
            [$this, 'settings_page'],
            'dashicons-admin-links',
            81
        );
    }

    public function register_settings() {
        register_setting('kreasmre_group', self::OPTION, [$this, 'sanitize_settings']);

        add_settings_section(
            'kreasmre_main',
            __('General', 'kreativ-smart-related-posts'),
            '__return_false',
            'kreativ-smart-related-posts'
        );

        foreach (['source_types' => __('Display on content types', 'kreativ-smart-related-posts'), 'target_types' => __('Recommend content types', 'kreativ-smart-related-posts')] as $key => $label) {
            add_settings_field('kreasmre_' . $key, $label, function () use ($key) {
                $opt = $this->settings();
                $selected_types = $this->enabled_types($opt, $key);
                echo '<input type="hidden" name="' . esc_attr(self::OPTION) . '[' . esc_attr($key) . '][]" value="" />';
                foreach ($this->public_post_types() as $type => $object) {
                    echo '<label style="display:block"><input type="checkbox" name="' . esc_attr(self::OPTION) . '[' . esc_attr($key) . '][]" value="' . esc_attr($type) . '" ' . checked(in_array($type, $selected_types, true), true, false) . ' /> ' . esc_html($object->labels->name) . '</label>';
                }
                echo '<p class="description">' . esc_html__('Only public content types can be selected. Existing sites use posts only until changed.', 'kreativ-smart-related-posts') . '</p>';
            }, 'kreativ-smart-related-posts', 'kreasmre_main');
        }

        add_settings_field('kreasmre_enable_ai', __('Enable AI re-ranking', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            $enabled = $opt['ai_provider'] === 'wordpress' && !empty($opt['enable_ai']);
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[enable_ai]" value="1" ' . checked(true, $enabled, false) . '/> ' . esc_html__('Use the WordPress AI Client to re-rank candidate posts.', 'kreativ-smart-related-posts') . '</label>';
            echo '<p class="description">' . esc_html__('Requires WordPress 7.0 or later and a configured text-generation provider. Review the provider\'s privacy policy before enabling.', 'kreativ-smart-related-posts') . '</p>';
            if ($opt['ai_provider'] !== 'wordpress' && !empty($opt['enable_ai'])) {
                echo '<p class="description">' . esc_html__('The previous direct OpenAI integration has been removed. Configure a WordPress AI provider and enable this option to resume AI re-ranking.', 'kreativ-smart-related-posts') . '</p>';
            }
        }, 'kreativ-smart-related-posts', 'kreasmre_main');

        add_settings_field('kreasmre_layout', __('Default layout', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            $layout = $opt['layout'];
            echo '<select name="' . esc_attr(self::OPTION) . '[layout]">';
            foreach (['grid' => 'Grid', 'list' => 'List', 'minimal' => 'Minimal'] as $k => $label) {
                echo '<option value="' . esc_attr($k) . '" ' . selected($layout, $k, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }, 'kreativ-smart-related-posts', 'kreasmre_main');

        add_settings_field('kreasmre_posts_per', __('Number of posts', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<input type="number" min="1" max="12" name="' . esc_attr(self::OPTION) . '[posts_per]" value="' . intval($opt['posts_per']) . '" />';
        }, 'kreativ-smart-related-posts', 'kreasmre_main');

        add_settings_field('kreasmre_show_excerpt', __('Show excerpt (grid/list)', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[show_excerpt]" value="1" ' . checked(1, intval($opt['show_excerpt']), false) . '/> ' . esc_html__('Display short excerpts for each related post.', 'kreativ-smart-related-posts') . '</label>';
        }, 'kreativ-smart-related-posts', 'kreasmre_main');

        add_settings_field('kreasmre_auto_insert', __('Auto-insert after content', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[auto_insert]" value="1" ' . checked(1, intval($opt['auto_insert']), false) . '/> ' . esc_html__('Automatically append to selected content types.', 'kreativ-smart-related-posts') . '</label>';
        }, 'kreativ-smart-related-posts', 'kreasmre_main');

        add_settings_field('kreasmre_title', __('Section title', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<input type="text" style="width:420px" name="' . esc_attr(self::OPTION) . '[title]" value="' . esc_attr($opt['title']) . '" />';
        }, 'kreativ-smart-related-posts', 'kreasmre_main');

        add_settings_field('kreasmre_cache_hours', __('Cache (hours)', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<input type="number" min="0" max="168" name="' . esc_attr(self::OPTION) . '[cache_hours]" value="' . intval($opt['cache_hours']) . '" />';
            echo '<p class="description">' . esc_html__('Store results in a transient to reduce API/DB calls (0 to disable). Background AI ranking requires caching and WP-Cron.', 'kreativ-smart-related-posts') . '</p>';
        }, 'kreativ-smart-related-posts', 'kreasmre_main');

        add_settings_section(
            'kreasmre_display',
            __('Display', 'kreativ-smart-related-posts'),
            '__return_false',
            'kreativ-smart-related-posts'
        );

        add_settings_field('kreasmre_accent_color', __('Accent color', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<input type="color" name="' . esc_attr(self::OPTION) . '[accent_color]" value="' . esc_attr($opt['accent_color']) . '" />';
        }, 'kreativ-smart-related-posts', 'kreasmre_display');

        add_settings_field('kreasmre_show_thumbnail', __('Show thumbnails', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[show_thumbnail]" value="1" ' . checked(1, intval($opt['show_thumbnail']), false) . '/> ' . esc_html__('Display featured images in grid cards.', 'kreativ-smart-related-posts') . '</label>';
        }, 'kreativ-smart-related-posts', 'kreasmre_display');

        add_settings_field('kreasmre_image_ratio', __('Image ratio', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<select name="' . esc_attr(self::OPTION) . '[image_ratio]">';
            foreach (['wide' => '16:9', 'classic' => '4:3', 'square' => '1:1'] as $k => $label) {
                echo '<option value="' . esc_attr($k) . '" ' . selected($opt['image_ratio'], $k, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }, 'kreativ-smart-related-posts', 'kreasmre_display');

        add_settings_field('kreasmre_grid_columns', __('Grid columns', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<input type="number" min="1" max="4" name="' . esc_attr(self::OPTION) . '[grid_columns]" value="' . intval($opt['grid_columns']) . '" />';
        }, 'kreativ-smart-related-posts', 'kreasmre_display');

        add_settings_field('kreasmre_card_radius', __('Card border radius', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<input type="number" min="0" max="24" name="' . esc_attr(self::OPTION) . '[card_radius]" value="' . intval($opt['card_radius']) . '" /> px';
        }, 'kreativ-smart-related-posts', 'kreasmre_display');

        add_settings_field('kreasmre_fallback_mode', __('Fallback posts', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<select id="kreasmre-fallback-mode" name="' . esc_attr(self::OPTION) . '[fallback_mode]">';
            foreach (['none' => __('Show nothing', 'kreativ-smart-related-posts'), 'latest' => __('Latest posts', 'kreativ-smart-related-posts'), 'author' => __('Same author', 'kreativ-smart-related-posts')] as $k => $label) {
                echo '<option value="' . esc_attr($k) . '" ' . selected($opt['fallback_mode'], $k, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('Used only when no shared taxonomy matches are found.', 'kreativ-smart-related-posts') . '</p>';
        }, 'kreativ-smart-related-posts', 'kreasmre_display');

        add_settings_field('kreasmre_link_target', __('Link target', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<select name="' . esc_attr(self::OPTION) . '[link_target]">';
            foreach (['same' => __('Same tab', 'kreativ-smart-related-posts'), 'new' => __('New tab', 'kreativ-smart-related-posts')] as $k => $label) {
                echo '<option value="' . esc_attr($k) . '" ' . selected($opt['link_target'], $k, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }, 'kreativ-smart-related-posts', 'kreasmre_display');

        add_settings_field('kreasmre_show_date', __('Show date', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[show_date]" value="1" ' . checked(1, intval($opt['show_date']), false) . '/> ' . esc_html__('Display post dates below titles.', 'kreativ-smart-related-posts') . '</label>';
        }, 'kreativ-smart-related-posts', 'kreasmre_display');

        add_settings_field('kreasmre_show_category', __('Show category', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[show_category]" value="1" ' . checked(1, intval($opt['show_category']), false) . '/> ' . esc_html__('Display the first category below titles.', 'kreativ-smart-related-posts') . '</label>';
        }, 'kreativ-smart-related-posts', 'kreasmre_display');

        add_settings_field('kreasmre_heading_tag', __('Heading tag', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<select name="' . esc_attr(self::OPTION) . '[heading_tag]">';
            foreach (['h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4'] as $k => $label) {
                echo '<option value="' . esc_attr($k) . '" ' . selected($opt['heading_tag'], $k, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }, 'kreativ-smart-related-posts', 'kreasmre_display');
    }

    public function sanitize_settings($input) {
        $current = $this->settings();

        if (!is_array($input)) {
            return $current;
        }

        $layout = isset($input['layout']) ? sanitize_key($input['layout']) : $current['layout'];
        if (!in_array($layout, ['grid', 'list', 'minimal'], true)) {
            $layout = 'grid';
        }

        $accent_color = isset($input['accent_color']) ? sanitize_hex_color($input['accent_color']) : $current['accent_color'];
        if (!$accent_color) {
            $accent_color = '#00C2FF';
        }

        $image_ratio = isset($input['image_ratio']) ? sanitize_key($input['image_ratio']) : $current['image_ratio'];
        if (!in_array($image_ratio, ['wide', 'classic', 'square'], true)) {
            $image_ratio = 'wide';
        }

        $fallback_mode = isset($input['fallback_mode']) ? sanitize_key($input['fallback_mode']) : $current['fallback_mode'];
        if (!in_array($fallback_mode, ['none', 'latest', 'author'], true)) {
            $fallback_mode = 'none';
        }

        $link_target = isset($input['link_target']) ? sanitize_key($input['link_target']) : $current['link_target'];
        if (!in_array($link_target, ['same', 'new'], true)) {
            $link_target = 'same';
        }

        $heading_tag = isset($input['heading_tag']) ? sanitize_key($input['heading_tag']) : $current['heading_tag'];
        if (!in_array($heading_tag, ['h2', 'h3', 'h4'], true)) {
            $heading_tag = 'h3';
        }

        return [
            'enable_ai'      => empty($input['enable_ai']) ? 0 : 1,
            'source_types'   => $this->enabled_types($input, 'source_types'),
            'target_types'   => $this->enabled_types($input, 'target_types'),
            'ai_provider'    => 'wordpress',
            'layout'         => $layout,
            'posts_per'      => isset($input['posts_per']) ? max(1, min(12, intval($input['posts_per']))) : 6,
            'show_excerpt'   => empty($input['show_excerpt']) ? 0 : 1,
            'auto_insert'    => empty($input['auto_insert']) ? 0 : 1,
            'title'          => isset($input['title']) ? sanitize_text_field($input['title']) : __('Related Posts', 'kreativ-smart-related-posts'),
            'cache_hours'    => isset($input['cache_hours']) ? max(0, min(168, intval($input['cache_hours']))) : 12,
            'accent_color'   => $accent_color,
            'show_thumbnail' => empty($input['show_thumbnail']) ? 0 : 1,
            'image_ratio'    => $image_ratio,
            'card_radius'    => isset($input['card_radius']) ? max(0, min(24, intval($input['card_radius']))) : 14,
            'grid_columns'   => isset($input['grid_columns']) ? max(1, min(4, intval($input['grid_columns']))) : 3,
            'fallback_mode'  => $fallback_mode,
            'link_target'    => $link_target,
            'show_date'      => empty($input['show_date']) ? 0 : 1,
            'show_category'  => empty($input['show_category']) ? 0 : 1,
            'heading_tag'    => $heading_tag,
        ];
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $opt = $this->settings();
        $source_types = $this->enabled_types($opt, 'source_types');
        $recent_posts = empty($source_types) ? [] : get_posts([
            'post_type' => $source_types,
            'post_status' => 'publish',
            'posts_per_page' => 25,
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ]);
        $preview_requested = isset($_GET['kreasmre_preview_post']);
        $valid_nonce = isset($_GET['kreasmre_preview_nonce']) && is_string($_GET['kreasmre_preview_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['kreasmre_preview_nonce'])), 'kreasmre_preview');
        $preview_id = $valid_nonce && isset($_GET['kreasmre_preview_post']) && is_string($_GET['kreasmre_preview_post'])
            ? absint(wp_unslash($_GET['kreasmre_preview_post'])) : 0;
        $recent_ids = array_map(static function ($post) { return intval($post->ID); }, $recent_posts);
        $valid_preview = $preview_id && in_array($preview_id, $recent_ids, true)
            && $this->source_enabled($preview_id, $opt) && get_post_status($preview_id) === 'publish';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Kreativ Smart Related Posts', 'kreativ-smart-related-posts'); ?></h1>
            <div class="kreasmre-getting-started">
                <h2><?php esc_html_e('Getting started', 'kreativ-smart-related-posts'); ?></h2>
                <p><?php esc_html_e('Recommendations come from published content sharing a public taxonomy. AI is optional.', 'kreativ-smart-related-posts'); ?></p>
                <?php if (!empty($opt['auto_insert'])) : ?>
                    <p><?php esc_html_e('Automatic insertion is on for selected content types. Content with no matching recommendations may show nothing.', 'kreativ-smart-related-posts'); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e('Automatic insertion is off. Add the Kreativ Smart Related Posts block or shortcode to selected content to display recommendations.', 'kreativ-smart-related-posts'); ?></p>
                <?php endif; ?>
                <p><a href="https://wordpress.org/support/plugin/kreativ-smart-related-posts/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get help or share feedback', 'kreativ-smart-related-posts'); ?></a></p>
            </div>
            <div class="kreasmre-preview-panel">
                <h2><?php esc_html_e('Preview related posts', 'kreativ-smart-related-posts'); ?></h2>
                <p><?php esc_html_e('Choose published content to preview recommendations using your saved settings. The preview uses shared public taxonomies, manual selections, and fallback settings; it does not run AI or schedule background work.', 'kreativ-smart-related-posts'); ?></p>
                <?php if (empty($recent_posts)) : ?>
                    <p><?php esc_html_e('Select a content type and publish content to use the preview. Recommendations need other published items.', 'kreativ-smart-related-posts'); ?></p>
                <?php else : ?>
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                        <input type="hidden" name="page" value="kreativ-smart-related-posts" />
                        <?php wp_nonce_field('kreasmre_preview', 'kreasmre_preview_nonce'); ?>
                        <label for="kreasmre-preview-post"><?php esc_html_e('Published post', 'kreativ-smart-related-posts'); ?></label>
                        <select id="kreasmre-preview-post" name="kreasmre_preview_post">
                            <?php foreach ($recent_posts as $recent_post) : ?>
                                <option value="<?php echo esc_attr($recent_post->ID); ?>" <?php selected($preview_id, $recent_post->ID); ?>><?php echo esc_html(get_the_title($recent_post)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button"><?php esc_html_e('Preview', 'kreativ-smart-related-posts'); ?></button>
                    </form>
                    <p class="description"><?php esc_html_e('Showing up to 25 of the most recent published posts. Save changes below before previewing new settings.', 'kreativ-smart-related-posts'); ?></p>
                <?php endif; ?>
                <?php if ($preview_requested && !$valid_preview) : ?>
                    <p class="notice notice-warning inline"><?php esc_html_e('That post is not available for preview. Choose a published post from the list.', 'kreativ-smart-related-posts'); ?></p>
                <?php elseif ($valid_preview) : ?>
                    <?php $preview = $this->preview_related_posts($preview_id, $opt); ?>
                    <?php if (empty($preview)) : ?>
                        <p class="notice notice-info inline"><?php esc_html_e('No related content found. Check that selected content types share a public taxonomy and published items, review manual exclusions, or choose a fallback mode below.', 'kreativ-smart-related-posts'); ?></p>
                        <p><?php esc_html_e('Pages do not have categories or tags by default. Use a shared public taxonomy, pin items manually, or choose a fallback.', 'kreativ-smart-related-posts'); ?> <a href="#kreasmre-fallback-mode"><?php esc_html_e('Go to fallback settings', 'kreativ-smart-related-posts'); ?></a></p>
                    <?php else : ?>
                        <div class="kreasmre-preview-result">
                            <?php
                            echo $this->render_related($preview_id, [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped by render_related().
                                'layout' => $opt['layout'], 'limit' => $opt['posts_per'], 'excerpt' => $opt['show_excerpt'],
                                'thumbnail' => $opt['show_thumbnail'], 'target' => $opt['link_target'],
                                'date' => $opt['show_date'], 'category' => $opt['show_category'], 'heading' => $opt['heading_tag'],
                            ], $preview);
                            ?>
                        </div>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e('This preview does not check theme placement or cached AI ordering. Open the post on your site to verify the live result.', 'kreativ-smart-related-posts'); ?> <a href="<?php echo esc_url(get_permalink($preview_id)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View post', 'kreativ-smart-related-posts'); ?></a></p>
                <?php endif; ?>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields('kreasmre_group'); ?>
                <?php do_settings_sections('kreativ-smart-related-posts'); ?>
                <?php submit_button(); ?>
            </form>
            <p><strong><?php esc_html_e('Shortcode:', 'kreativ-smart-related-posts'); ?></strong> <code>[kreasmre_related_posts layout="grid" posts="6" excerpt="0" thumbnail="1" date="0" category="0" target="same" heading="h3"]</code></p>
            <p><strong><?php esc_html_e('Block:', 'kreativ-smart-related-posts'); ?></strong> <?php esc_html_e('Search for “Kreativ Smart Related Posts” in the block inserter.', 'kreativ-smart-related-posts'); ?></p>
            <hr />
            <h2><?php esc_html_e('External Services', 'kreativ-smart-related-posts'); ?></h2>
            <p><?php esc_html_e('When AI re-ranking is enabled, post titles, excerpts and candidate IDs are sent to the selected AI provider. The WordPress AI Client uses the provider configured on this site.', 'kreativ-smart-related-posts'); ?></p>
        </div>
        <?php
    }

    public function register_related_overrides_metabox() {
        foreach ($this->enabled_types($this->settings(), 'source_types') as $type) {
            add_meta_box(
            'kreasmre_related_overrides',
            __('Smart Related Overrides', 'kreativ-smart-related-posts'),
            [$this, 'render_related_overrides_metabox'],
            $type,
            'side',
            'default'
            );
        }
    }

    public function render_related_overrides_metabox($post) {
        $include = $this->parse_post_id_list($this->related_meta($post->ID, self::META_INCLUDE), [$post->ID]);
        $exclude = $this->parse_post_id_list($this->related_meta($post->ID, self::META_EXCLUDE), [$post->ID]);

        wp_nonce_field('kreasmre_related_overrides_nonce', 'kreasmre_related_overrides_nonce');
        ?>
        <?php $this->render_post_picker('kreasmre_manual_include', 'kreasmre_manual_include', __('Force include posts', 'kreativ-smart-related-posts'), $include); ?>
        <?php $this->render_post_picker('kreasmre_manual_exclude', 'kreasmre_manual_exclude', __('Force exclude posts', 'kreativ-smart-related-posts'), $exclude); ?>
        <p class="description">
            <?php esc_html_e('Includes are pinned first. Excludes are always removed.', 'kreativ-smart-related-posts'); ?>
        </p>
        <?php
    }

    private function render_post_picker($id, $name, $label, $ids) {
        $items = $this->get_post_picker_items($ids);
        ?>
        <div class="srp-post-picker" data-picker-id="<?php echo esc_attr($id); ?>">
            <label for="<?php echo esc_attr($id); ?>_search"><strong><?php echo esc_html($label); ?></strong></label>
            <input type="hidden" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr(implode(',', $ids)); ?>" class="srp-post-picker-value" />
            <div class="srp-post-picker-selected" aria-live="polite">
                <?php foreach ($items as $item) : ?>
                    <button type="button" class="srp-post-picker-token" data-id="<?php echo esc_attr($item['id']); ?>">
                        <span><?php echo esc_html($item['title']); ?></span>
                        <span class="srp-post-picker-remove" aria-hidden="true">x</span>
                    </button>
                <?php endforeach; ?>
            </div>
            <input type="search" id="<?php echo esc_attr($id); ?>_search" class="widefat srp-post-picker-search" placeholder="<?php esc_attr_e('Search posts...', 'kreativ-smart-related-posts'); ?>" autocomplete="off" />
            <div class="srp-post-picker-results" hidden></div>
        </div>
        <?php
    }

    public function ajax_search_posts() {
        $can_edit = false;
        foreach ($this->enabled_types($this->settings(), 'source_types') as $type) {
            $object = get_post_type_object($type);
            if ($object && current_user_can($object->cap->edit_posts)) {
                $can_edit = true;
                break;
            }
        }
        if (!$can_edit) {
            wp_send_json_error(['message' => __('Permission denied.', 'kreativ-smart-related-posts')], 403);
        }

        check_ajax_referer('kreasmre_search_posts', 'nonce');

        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        if (strlen($term) < 2) {
            wp_send_json_success([]);
        }

        $target_types = $this->enabled_types($this->settings(), 'target_types');
        if (empty($target_types)) {
            wp_send_json_success([]);
        }
        $posts = get_posts([
            'post_type'           => $target_types,
            'post_status'         => 'publish',
            's'                   => $term,
            'posts_per_page'      => 10,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'fields'              => 'ids',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ]);

        wp_send_json_success($this->get_post_picker_items($posts));
    }

    private function get_post_picker_items($ids) {
        $ids = array_values(array_filter(array_unique(array_map('intval', is_array($ids) ? $ids : []))));
        if (empty($ids)) {
            return [];
        }

        $target_types = $this->enabled_types($this->settings(), 'target_types');
        if (empty($target_types)) {
            return [];
        }
        $posts = get_posts([
            'post_type'           => $target_types,
            'post_status'         => $this->post_picker_statuses(),
            'post__in'            => $ids,
            'orderby'             => 'post__in',
            'posts_per_page'      => count($ids),
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ]);

        $items = [];
        foreach ($posts as $post) {
            if ($post->post_status !== 'publish' && !current_user_can('edit_post', $post->ID)) {
                continue;
            }
            $title = get_the_title($post);
            if ($title === '') {
                /* translators: %d: WordPress post ID. */
                $title = sprintf(__('Post #%d', 'kreativ-smart-related-posts'), intval($post->ID));
            }
            $items[] = [
                'id'    => intval($post->ID),
                'title' => $title,
            ];
        }

        return $items;
    }

    private function post_picker_statuses() {
        // Results for non-public items are checked individually with edit_post.
        return ['publish', 'future', 'draft', 'pending', 'private'];
    }

    public function save_related_overrides_meta($post_id, $post, $update) {
        unset($update);

        if (!$post instanceof WP_Post || !$this->source_enabled($post_id, $this->settings())) {
            return;
        }

        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $nonce = isset($_POST['kreasmre_related_overrides_nonce']) ? sanitize_text_field(wp_unslash($_POST['kreasmre_related_overrides_nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'kreasmre_related_overrides_nonce')) {
            return;
        }

        $raw_include = isset($_POST['kreasmre_manual_include']) ? sanitize_text_field(wp_unslash($_POST['kreasmre_manual_include'])) : '';
        $raw_exclude = isset($_POST['kreasmre_manual_exclude']) ? sanitize_text_field(wp_unslash($_POST['kreasmre_manual_exclude'])) : '';

        $exclude_ids = $this->parse_post_id_list($raw_exclude, [$post_id]);
        $include_ids = $this->parse_post_id_list($raw_include, array_merge([$post_id], $exclude_ids));

        delete_post_meta($post_id, '_srp_manual_include_ids');
        delete_post_meta($post_id, '_srp_manual_exclude_ids');

        if (!empty($include_ids)) {
            update_post_meta($post_id, self::META_INCLUDE, implode(',', $include_ids));
        } else {
            delete_post_meta($post_id, self::META_INCLUDE);
        }

        if (!empty($exclude_ids)) {
            update_post_meta($post_id, self::META_EXCLUDE, implode(',', $exclude_ids));
        } else {
            delete_post_meta($post_id, self::META_EXCLUDE);
        }

        $this->flush_related_cache();
    }

    public function admin_enqueue_assets($hook_suffix) {
        if ($hook_suffix === 'toplevel_page_kreativ-smart-related-posts') {
            wp_enqueue_style('kreasmre-admin', plugins_url('assets/css/kreasmre-admin.css', __FILE__), [], self::VERSION);
            $this->enqueue_assets();
            wp_enqueue_style('kreasmre-related-posts');
            return;
        }
        if (!in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, $this->enabled_types($this->settings(), 'source_types'), true)) {
            return;
        }

        wp_enqueue_script(
            'kreasmre-admin',
            plugins_url('assets/js/srp-admin.js', __FILE__),
            [],
            self::VERSION,
            true
        );

        wp_localize_script('kreasmre-admin', 'kreasmreAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('kreasmre_search_posts'),
            'strings' => [
                'noResults' => __('No posts found.', 'kreativ-smart-related-posts'),
                'searching' => __('Searching...', 'kreativ-smart-related-posts'),
                'remove'    => __('Remove', 'kreativ-smart-related-posts'),
            ],
        ]);

        wp_enqueue_style('kreasmre-admin', plugins_url('assets/css/kreasmre-admin.css', __FILE__), [], self::VERSION);
    }

    public function enqueue_assets() {
        $opt = $this->settings();
        $ratios = [
            'wide'    => '16 / 9',
            'classic' => '4 / 3',
            'square'  => '1 / 1',
        ];
        $ratio = isset($ratios[$opt['image_ratio']]) ? $ratios[$opt['image_ratio']] : $ratios['wide'];
        $accent = sanitize_hex_color($opt['accent_color']);
        if (!$accent) {
            $accent = '#00C2FF';
        }

        wp_register_style('kreasmre-related-posts', plugins_url('assets/css/kreasmre-related-posts.css', __FILE__), [], self::VERSION);
        $css = '.srp-related{--srp-accent:' . esc_attr($accent) . ';--srp-radius:' . intval($opt['card_radius']) . 'px;--srp-cols:' . intval($opt['grid_columns']) . ';--srp-ratio:' . esc_attr($ratio) . '}';
        wp_add_inline_style('kreasmre-related-posts', $css);
    }

    public function flush_related_cache_on_save($post_id, $post, $update) {
        unset($update);

        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        if (!$post instanceof WP_Post || !in_array($post->post_type, array_unique(array_merge($this->enabled_types($this->settings(), 'source_types'), $this->enabled_types($this->settings(), 'target_types'))), true)) {
            return;
        }

        $this->flush_related_cache();
    }

    public function flush_related_cache_on_delete($post_id, $post) {
        if (!$post instanceof WP_Post || !in_array($post->post_type, array_unique(array_merge($this->enabled_types($this->settings(), 'source_types'), $this->enabled_types($this->settings(), 'target_types'))), true)) {
            return;
        }

        $this->flush_related_cache();
    }

    public function flush_related_cache_on_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        unset($terms);

        $type = get_post_type($object_id);
        if (!in_array($type, array_unique(array_merge($this->enabled_types($this->settings(), 'source_types'), $this->enabled_types($this->settings(), 'target_types'))), true)) {
            return;
        }

        $taxonomy_object = get_taxonomy($taxonomy);
        if (!$taxonomy_object || !$taxonomy_object->public || !is_object_in_taxonomy($type, $taxonomy)) {
            return;
        }

        if (!$append && is_array($tt_ids) && is_array($old_tt_ids)) {
            $new_ids = array_map('intval', $tt_ids);
            $old_ids = array_map('intval', $old_tt_ids);
            sort($new_ids);
            sort($old_ids);
            if ($new_ids === $old_ids) {
                return;
            }
        }

        $this->flush_related_cache();
    }

    public function flush_related_cache_on_term_delete($term_id, $tt_id, $taxonomy) {
        unset($term_id, $tt_id);
        $object = get_taxonomy($taxonomy);
        $opt = $this->settings();
        $types = array_merge($this->enabled_types($opt, 'source_types'), $this->enabled_types($opt, 'target_types'));
        if ($object && $object->public && array_intersect($types, $object->object_type)) {
            $this->flush_related_cache();
        }
    }

    private function flush_related_cache() {
        // Changing the namespace invalidates DB and persistent object-cache entries alike.
        update_option(self::CACHE_GENERATION, wp_generate_uuid4(), false);
    }

    public function settings_changed($old_value, $new_value) {
        if ($old_value !== $new_value) {
            $this->flush_related_cache();
        }
    }

    public function deactivate() {
        wp_unschedule_hook(self::AI_HOOK);
        $this->flush_related_cache();
    }

    public function auto_append_related($content) {
        if ($this->rendering_excerpt || !is_singular() || !in_the_loop() || !is_main_query() || get_the_ID() !== get_queried_object_id() || !$this->source_enabled(get_the_ID(), $this->settings())) {
            return $content;
        }

        $source = (string) get_post_field('post_content', get_the_ID());
        if (has_block('kreativ-smart-related-posts/related-posts', $source)
            || has_shortcode($source, 'kreasmre_related_posts')
            || has_shortcode($content, 'kreasmre_related_posts')
            || has_shortcode($source, 'smart_related_posts')
            || has_shortcode($source, 'kreativ_related_articles')
            || has_shortcode($content, 'smart_related_posts')
            || has_shortcode($content, 'kreativ_related_articles')
            || strpos($content, 'data-ksrp-related="1"') !== false) {
            return $content;
        }

        $opt = $this->settings();
        if (empty($opt['auto_insert'])) {
            return $content;
        }

        $section = $this->render_related(get_the_ID(), [
            'layout'    => $opt['layout'],
            'limit'     => intval($opt['posts_per']),
            'excerpt'   => !empty($opt['show_excerpt']),
            'thumbnail' => !empty($opt['show_thumbnail']),
            'target'    => $opt['link_target'],
            'date'      => !empty($opt['show_date']),
            'category'  => !empty($opt['show_category']),
            'heading'   => $opt['heading_tag'],
        ]);

        if ($section) {
            wp_enqueue_style('kreasmre-related-posts');
            $content .= $section;
        }

        return $content;
    }

    public function shortcode($atts) {
        $opt = $this->settings();
        $atts = shortcode_atts([
            'layout'    => $opt['layout'],
            'posts'     => intval($opt['posts_per']),
            'excerpt'   => intval($opt['show_excerpt']),
            'thumbnail' => intval($opt['show_thumbnail']),
            'target'    => $opt['link_target'],
            'date'      => intval($opt['show_date']),
            'category'  => intval($opt['show_category']),
            'heading'   => $opt['heading_tag'],
        ], $atts, 'kreasmre_related_posts');

        wp_enqueue_style('kreasmre-related-posts');

        return $this->render_related(get_the_ID(), [
            'layout'    => sanitize_text_field($atts['layout']),
            'limit'     => max(1, min(12, intval($atts['posts']))),
            'excerpt'   => !empty($atts['excerpt']),
            'thumbnail' => !empty($atts['thumbnail']),
            'target'    => sanitize_key($atts['target']),
            'date'      => !empty($atts['date']),
            'category'  => !empty($atts['category']),
            'heading'   => sanitize_key($atts['heading']),
        ]);
    }

    private function render_related($post_id, $args, $preview_posts = null) {
        if (!$post_id) {
            return '';
        }

        $opt = $this->settings();
        $title = isset($opt['title']) ? $opt['title'] : '';
        $posts = $preview_posts === null ? $this->get_related_posts($post_id, max(1, min(12, intval($args['limit'])))) : $preview_posts;
        if (empty($posts)) {
            return '';
        }

        $layout = in_array($args['layout'], ['grid', 'list', 'minimal'], true) ? $args['layout'] : 'grid';
        $show_excerpt = !empty($args['excerpt']);
        $show_thumbnail = !empty($args['thumbnail']);
        $show_date = !empty($args['date']);
        $show_category = !empty($args['category']);
        $heading_tag = isset($args['heading']) && in_array($args['heading'], ['h2', 'h3', 'h4'], true) ? $args['heading'] : 'h3';
        $link_target = isset($args['target']) && $args['target'] === 'new' ? 'new' : 'same';

        ob_start();
        ?>
        <section class="srp-related" data-ksrp-related="1">
            <?php if ($title !== '') : ?>
                <div class="srp-titlebar"><span class="srp-dot"></span><<?php echo tag_escape($heading_tag); ?> class="srp-titletext"><?php echo esc_html($title); ?></<?php echo tag_escape($heading_tag); ?>></div>
            <?php endif; ?>
            <?php if ($layout === 'grid') : ?>
                <ul class="srp-grid">
                    <?php foreach ($posts as $p) : ?>
                        <li class="srp-card">
                            <a href="<?php echo esc_url(get_permalink($p)); ?>" aria-label="<?php echo esc_attr(get_the_title($p)); ?>"<?php if ($link_target === 'new') : ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>>
                                <?php if ($show_thumbnail && has_post_thumbnail($p)) : ?>
                                    <div class="srp-thumb"><?php echo get_the_post_thumbnail($p, 'medium_large'); ?></div>
                                <?php endif; ?>
                                <div class="srp-content">
                                    <h4 class="srp-title"><?php echo esc_html(get_the_title($p)); ?></h4>
                                    <?php echo wp_kses_post($this->render_post_meta($p, $show_date, $show_category)); ?>
                                    <?php if ($show_excerpt) : ?>
                                        <p class="srp-excerpt"><?php echo esc_html($this->related_excerpt($p, 20)); ?></p>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif ($layout === 'list') : ?>
                <ul class="srp-list">
                    <?php foreach ($posts as $p) : ?>
                        <li>
                            <a href="<?php echo esc_url(get_permalink($p)); ?>"<?php if ($link_target === 'new') : ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><strong><?php echo esc_html(get_the_title($p)); ?></strong></a>
                            <?php echo wp_kses_post($this->render_post_meta($p, $show_date, $show_category)); ?>
                            <?php if ($show_excerpt) : ?>
                                <div class="srp-excerpt"><?php echo esc_html($this->related_excerpt($p, 22)); ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <div class="srp-minimal">
                    <?php foreach ($posts as $p) : ?>
                        <a class="srp-pill" href="<?php echo esc_url(get_permalink($p)); ?>"<?php if ($link_target === 'new') : ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?php echo esc_html(get_the_title($p)); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private function related_excerpt($post_id, $words) {
        $previous = $this->rendering_excerpt;
        $this->rendering_excerpt = true;
        try {
            return wp_trim_words(wp_strip_all_tags(get_the_excerpt($post_id)), $words);
        } finally {
            $this->rendering_excerpt = $previous;
        }
    }

    private function render_post_meta($post_id, $show_date, $show_category) {
        if (!$show_date && !$show_category) {
            return '';
        }

        $parts = [];
        if ($show_date) {
            $parts[] = get_the_date('', $post_id);
        }

        if ($show_category) {
            $categories = get_the_category($post_id);
            if (!empty($categories)) {
                $parts[] = $categories[0]->name;
            }
        }

        if (empty($parts)) {
            return '';
        }

        return '<div class="srp-meta">' . esc_html(implode(' / ', $parts)) . '</div>';
    }

    private function get_related_posts($post_id, $limit = 6) {
        $limit = max(1, min(12, intval($limit)));
        $opt = $this->settings();
        if (!$this->source_enabled($post_id, $opt) || empty($this->enabled_types($opt, 'target_types'))) {
            return [];
        }
        $manual = $this->get_manual_related_overrides($post_id);
        $cache_key = $this->related_cache_key($post_id, $limit, $opt, $manual);
        $cache_ttl = max(0, intval($opt['cache_hours'])) * HOUR_IN_SECONDS;

        if ($cache_ttl > 0) {
            $cached = get_transient($cache_key);
            if (is_array($cached) && isset($cached['ids']) && is_array($cached['ids'])) {
                $valid_ids = $this->published_related_ids($cached['ids']);
                if ($valid_ids === $cached['ids']) {
                    if (!empty($valid_ids) && empty($cached['ai_ready'])) {
                        $this->schedule_ai_ranking($post_id, $limit, $cache_key, $opt);
                    }
                    return $valid_ids;
                }
            }
        }

        $candidates = $this->related_candidates($post_id, $limit, $manual, $opt);
        $ordered = $this->merge_manual_related_overrides($post_id, $manual['include'], $manual['exclude'], $candidates);
        $ordered = array_slice($ordered, 0, $limit);

        if ($cache_ttl > 0) {
            set_transient($cache_key, ['ids' => $ordered, 'ai_ready' => false], $cache_ttl);
            if (!empty($candidates)) {
                $this->schedule_ai_ranking($post_id, $limit, $cache_key, $opt);
            }
        }

        return $ordered;
    }

    private function related_cache_key($post_id, $limit, $opt, $manual) {
        return 'kreasmre_rel_' . md5(wp_json_encode([
            self::VERSION,
            get_option(self::CACHE_GENERATION, '1'),
            intval($post_id),
            intval($limit),
            $opt['fallback_mode'],
            !empty($opt['enable_ai']),
            $opt['ai_provider'],
            $this->enabled_types($opt, 'source_types'),
            $this->enabled_types($opt, 'target_types'),
            $manual,
        ]));
    }

    private function published_related_ids($ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $target_types = $this->enabled_types($this->settings(), 'target_types');
        if (empty($target_types)) {
            return [];
        }
        return array_map('intval', get_posts([
            'post_type'           => $target_types,
            'post_status'         => 'publish',
            'post__in'            => $ids,
            'orderby'             => 'post__in',
            'posts_per_page'      => count($ids),
            'fields'              => 'ids',
            'suppress_filters'    => false,
        ]));
    }

    private function related_candidates($post_id, $limit, $manual, $opt) {
        $candidate_limit = min(60, max(12, $limit * 8));
        $candidates = $this->get_taxonomy_candidates($post_id, $candidate_limit, $manual['exclude']);
        if (empty($candidates) && in_array($opt['fallback_mode'], ['latest', 'author'], true)) {
            $candidates = $this->get_fallback_candidates($post_id, $candidate_limit, $manual['exclude'], $opt['fallback_mode']);
        }
        return $candidates;
    }

    private function preview_related_posts($post_id, $opt) {
        if (!$this->source_enabled($post_id, $opt) || empty($this->enabled_types($opt, 'target_types'))) {
            return [];
        }
        $limit = max(1, min(12, intval($opt['posts_per'])));
        $manual = $this->get_manual_related_overrides($post_id);
        $candidates = $this->related_candidates($post_id, $limit, $manual, $opt);
        $ordered = $this->merge_manual_related_overrides($post_id, $manual['include'], $manual['exclude'], $candidates);
        return array_slice($this->published_related_ids($ordered), 0, $limit);
    }

    private function schedule_ai_ranking($post_id, $limit, $cache_key, $opt) {
        if (!$this->ai_available($opt) || intval($opt['cache_hours']) < 1
            || get_post_status($post_id) !== 'publish' || !$this->source_enabled($post_id, $opt)
            || get_transient('kreasmre_ai_retry_' . md5($cache_key))) {
            return;
        }

        $args = [intval($post_id), intval($limit), $cache_key];
        if (!wp_next_scheduled(self::AI_HOOK, $args)) {
            wp_schedule_single_event(time() + 1, self::AI_HOOK, $args);
        }
    }

    private function acquire_ai_lock($name) {
        $expires = time() + 120;
        if (add_option($name, $expires, '', false)) {
            return true;
        }

        $previous = get_option($name);
        if (!$previous || intval($previous) >= time()) {
            return false;
        }

        // Compare-and-delete prevents one worker from removing a newer worker's lock.
        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $name,
            (string) $previous
        ));
        if (!$deleted) {
            return false;
        }
        wp_cache_delete($name, 'options');
        return add_option($name, $expires, '', false);
    }

    public function refresh_ai_ranking($post_id, $limit, $cache_key) {
        $limit = max(1, min(12, intval($limit)));
        $opt = $this->settings();
        $manual = $this->get_manual_related_overrides($post_id);
        if (!$this->ai_available($opt) || intval($opt['cache_hours']) < 1
            || get_post_status($post_id) !== 'publish' || !$this->source_enabled($post_id, $opt)
            || $cache_key !== $this->related_cache_key($post_id, $limit, $opt, $manual)
            || get_transient('kreasmre_ai_retry_' . md5($cache_key))) {
            return;
        }

        $lock = 'kreasmre_ai_lock_' . md5($cache_key);
        if (!$this->acquire_ai_lock($lock)) {
            return;
        }

        try {
            $cached = get_transient($cache_key);
            if (is_array($cached) && !empty($cached['ai_ready'])) {
                return;
            }

            $candidates = $this->related_candidates($post_id, $limit, $manual, $opt);
            if (empty($candidates)) {
                if (is_array($cached) && isset($cached['ids'])) {
                    $cached['ai_ready'] = true;
                    set_transient($cache_key, $cached, intval($opt['cache_hours']) * HOUR_IN_SECONDS);
                }
                return;
            }
            $ordered = $this->ai_rerank($post_id, $candidates, $limit);
            if (empty($ordered)) {
                set_transient('kreasmre_ai_retry_' . md5($cache_key), 1, 5 * MINUTE_IN_SECONDS);
                return;
            }

            $current = $this->settings();
            $current_manual = $this->get_manual_related_overrides($post_id);
            if ($cache_key !== $this->related_cache_key($post_id, $limit, $current, $current_manual)) {
                return;
            }
            $ordered = $this->published_related_ids($ordered);
            $ordered = $this->merge_manual_related_overrides($post_id, $manual['include'], $manual['exclude'], $ordered);
            set_transient($cache_key, [
                'ids'      => array_slice($ordered, 0, $limit),
                'ai_ready' => true,
            ], intval($opt['cache_hours']) * HOUR_IN_SECONDS);
        } finally {
            delete_option($lock);
        }
    }

    private function get_taxonomy_candidates($post_id, $limit, $exclude_ids = []) {
        $target_types = $this->enabled_types($this->settings(), 'target_types');
        if (empty($target_types)) {
            return [];
        }
        $tax_query = ['relation' => 'OR'];
        $weighted_conditions = [];
        $hierarchical_conditions = [];
        global $wpdb;
        foreach (get_object_taxonomies(get_post_type($post_id), 'objects') as $taxonomy) {
            if (!$taxonomy->public || !array_intersect($target_types, $taxonomy->object_type)) {
                continue;
            }
            $terms = wp_get_post_terms($post_id, $taxonomy->name, ['fields' => 'ids']);
            $terms = is_wp_error($terms) ? [] : array_filter(array_map('intval', $terms));
            if (empty($terms)) {
                continue;
            }
            $tax_query[] = ['taxonomy' => $taxonomy->name, 'field' => 'term_id', 'terms' => $terms];
            $condition = $wpdb->prepare('tt.taxonomy = %s', $taxonomy->name) . ' AND tt.term_id IN (' . implode(',', $terms) . ')';
            $weighted_conditions[] = 'WHEN ' . $condition . ' THEN ' . ($taxonomy->hierarchical ? '4' : '2');
            if ($taxonomy->hierarchical) {
                $hierarchical_conditions[] = '(' . $condition . ')';
            }
        }
        if (empty($weighted_conditions)) {
            return [];
        }

        $blocked_ids = array_values(array_unique(array_merge([intval($post_id)], array_map('intval', $exclude_ids))));

        // Score every match in SQL before LIMIT, without loading the full archive into PHP.
        $from = "FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = {$wpdb->posts}.ID";
        $score = '(SELECT COALESCE(SUM(CASE ' . implode(' ', $weighted_conditions) . " ELSE 0 END), 0) {$from})";
        $hierarchical_condition = empty($hierarchical_conditions) ? '0 = 1' : implode(' OR ', $hierarchical_conditions);
        $category_count = "(SELECT COUNT(*) {$from} AND ({$hierarchical_condition}))";
        $order = static function ($orderby, $query) use ($post_id, $wpdb, $score, $category_count) {
            if ($query->get('kreasmre_source_id') !== intval($post_id)) {
                return $orderby;
            }
            return "{$score} DESC, {$category_count} DESC, {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
        };

        add_filter('posts_orderby', $order, 10, 2);
        try {
            $q = new WP_Query([
                'post_type'           => $target_types,
                'post_status'         => 'publish',
                'post__not_in'        => $blocked_ids,
                'posts_per_page'      => intval($limit),
                'ignore_sticky_posts' => true,
                'no_found_rows'       => true,
                'tax_query'           => $tax_query,
                'fields'              => 'ids',
                'kreasmre_source_id'      => intval($post_id),
            ]);
        } finally {
            remove_filter('posts_orderby', $order, 10);
        }

        return is_array($q->posts) ? array_map('intval', $q->posts) : [];
    }

    private function get_fallback_candidates($post_id, $limit, $exclude_ids, $mode) {
        $post = get_post($post_id);
        $target_types = $this->enabled_types($this->settings(), 'target_types');
        if (!$post || empty($target_types)) {
            return [];
        }

        $args = [
            'post_type'           => $target_types,
            'post_status'         => 'publish',
            'post__not_in'        => array_values(array_unique(array_merge([intval($post_id)], array_map('intval', $exclude_ids)))),
            'posts_per_page'      => intval($limit),
            'orderby'             => 'date',
            'order'               => 'DESC',
            'fields'              => 'ids',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'suppress_filters'    => false,
        ];

        if ($mode === 'author') {
            $args['author'] = intval($post->post_author);
        }

        $posts = get_posts($args);

        return is_array($posts) ? array_map('intval', $posts) : [];
    }

    private function get_manual_related_overrides($post_id) {
        $raw_include = $this->related_meta($post_id, self::META_INCLUDE);
        $raw_exclude = $this->related_meta($post_id, self::META_EXCLUDE);

        $exclude_ids = $this->parse_post_id_list($raw_exclude, [$post_id]);
        $include_ids = $this->parse_post_id_list($raw_include, array_merge([$post_id], $exclude_ids));

        return [
            'include' => $include_ids,
            'exclude' => $exclude_ids,
        ];
    }

    private function parse_post_id_list($raw, $skip_ids = []) {
        $raw = is_string($raw) ? $raw : '';
        if ($raw === '') {
            return [];
        }

        $skip_map = array_fill_keys(array_map('intval', $skip_ids), true);
        $parts = preg_split('/[\s,]+/', $raw);
        $ids = [];
        foreach ($parts as $part) {
            $id = intval($part);
            if ($id <= 0 || isset($skip_map[$id]) || in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function merge_manual_related_overrides($post_id, $manual_include, $manual_exclude, $ordered) {
        $blocked = array_fill_keys(array_merge([intval($post_id)], array_map('intval', $manual_exclude)), true);
        $ordered = is_array($ordered) ? array_map('intval', $ordered) : [];

        $validated_include = [];
        if (!empty($manual_include)) {
            $candidates = [];
            foreach ($manual_include as $id) {
                $id = intval($id);
                if ($id > 0 && !isset($blocked[$id])) {
                    $candidates[] = $id;
                }
            }

            if (!empty($candidates)) {
                $target_types = $this->enabled_types($this->settings(), 'target_types');
                if (empty($target_types)) {
                    return [];
                }
                $validated_include = get_posts([
                    'post_type'           => $target_types,
                    'post_status'         => 'publish',
                    'post__in'            => $candidates,
                    'orderby'             => 'post__in',
                    'posts_per_page'      => count($candidates),
                    'fields'              => 'ids',
                    'ignore_sticky_posts' => true,
                    'no_found_rows'       => true,
                    'suppress_filters'    => false,
                ]);
                $validated_include = is_array($validated_include) ? array_map('intval', $validated_include) : [];
            }
        }

        $result = [];
        foreach (array_merge($validated_include, $ordered) as $id) {
            $id = intval($id);
            if ($id <= 0 || isset($blocked[$id]) || in_array($id, $result, true)) {
                continue;
            }
            $result[] = $id;
        }

        return $result;
    }

    private function related_meta($post_id, $key) {
        if (metadata_exists('post', $post_id, $key)) {
            return get_post_meta($post_id, $key, true);
        }
        $legacy = $key === self::META_INCLUDE ? '_srp_manual_include_ids' : '_srp_manual_exclude_ids';
        return get_post_meta($post_id, $legacy, true);
    }

    private function ai_available($opt) {
        if (empty($opt['enable_ai'])) {
            return false;
        }
        if ($opt['ai_provider'] !== 'wordpress') {
            return false;
        }
        if (!function_exists('wp_ai_client_prompt')) {
            return false;
        }
        try {
            return wp_ai_client_prompt()->with_text('test')->is_supported_for_text_generation();
        } catch (Throwable $error) {
            return false;
        }
    }

    private function ai_rerank($post_id, $candidate_ids, $limit) {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $context = [
            'title'   => get_the_title($post),
            'excerpt' => wp_trim_words(wp_strip_all_tags(get_the_excerpt($post)), 60),
        ];

        $candidates = [];
        foreach ($candidate_ids as $cid) {
            $candidates[] = [
                'id'      => intval($cid),
                'title'   => get_the_title($cid),
                'excerpt' => wp_trim_words(wp_strip_all_tags(get_the_excerpt($cid)), 30),
            ];
        }

        $sys = 'You re-rank related blog posts. Return ONLY a JSON array of candidate post IDs (integers), ordered by relevance to the source. No extra text.';
        $user = [
            'source'     => $context,
            'choose'     => intval($limit),
            'candidates' => $candidates,
        ];

        try {
            $content = wp_ai_client_prompt()->with_text($sys . "\n" . wp_json_encode($user))->using_temperature(0.2)->generate_text();
        } catch (Throwable $error) {
            return [];
        }
        if (is_wp_error($content) || !is_string($content)) {
            return [];
        }

        $ids = json_decode(trim($content), true);
        if (!is_array($ids)) {
            return [];
        }

        $candidate_ids = array_values(array_map('intval', $candidate_ids));
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ordered_ids = [];

        foreach ($ids as $id) {
            if (in_array($id, $candidate_ids, true)) {
                $ordered_ids[] = $id;
            }
        }

        foreach ($candidate_ids as $candidate_id) {
            if (!in_array($candidate_id, $ordered_ids, true)) {
                $ordered_ids[] = $candidate_id;
            }
        }

        return $ordered_ids;
    }

    public function register_block() {
        wp_register_style('kreasmre-block-editor', plugins_url('assets/css/srp-block-editor.css', __FILE__), [], self::VERSION);
        wp_register_script(
            'kreasmre-block',
            plugins_url('assets/js/srp-block.js', __FILE__),
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n'],
            self::VERSION,
            true
        );
        wp_set_script_translations('kreasmre-block', 'kreativ-smart-related-posts', plugin_dir_path(__FILE__) . 'languages');
        $metadata = json_decode(file_get_contents(__DIR__ . '/block.json'), true);
        $api_version = version_compare(get_bloginfo('version'), '6.3', '>=') ? 3 : 2;
        wp_add_inline_script('kreasmre-block', 'window.kreasmreBlockAttributes = ' . wp_json_encode($metadata['attributes']) . '; window.kreasmreBlockApiVersion = ' . intval($api_version) . ';', 'before');

        register_block_type(__DIR__ . '/block.json', [
            'api_version' => $api_version,
            'render_callback' => function ($attrs, $content, $block) {
                $post_id = isset($block->context['postId']) ? intval($block->context['postId']) : get_the_ID();
                wp_enqueue_style('kreasmre-related-posts');
                return $this->render_related($post_id, [
                    'layout'    => $attrs['layout'],
                    'limit'     => max(1, min(12, intval($attrs['posts']))),
                    'excerpt'   => $attrs['excerpt'],
                    'thumbnail' => $attrs['thumbnail'],
                    'target'    => $attrs['target'],
                    'date'      => $attrs['date'],
                    'category'  => $attrs['category'],
                    'heading'   => $attrs['heading'],
                ]);
            },
        ]);
    }
}

new Kreativ_Smart_Related_Posts();
