<?php
/**
 * Plugin Name: Kreativ Smart Related Posts
 * Description: Display related posts using shared categories/tags, with optional AI re-ranking. Includes auto-insert, shortcode, and a dynamic block.
 * Version: 1.1.0
 * Author: Andrei Olaru
 * License: GPL-2.0+
 * Text Domain: kreativ-smart-related-posts
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kreativ_Smart_Related_Posts {
    const OPTION = 'srp_settings';
    const VERSION = '1.1.0';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('save_post_post', [$this, 'flush_related_cache_on_save'], 10, 3);
        add_action('deleted_post', [$this, 'flush_related_cache_on_delete']);
        add_action('set_object_terms', [$this, 'flush_related_cache_on_terms'], 10, 6);
        add_filter('the_content', [$this, 'auto_append_related']);

        add_shortcode('smart_related_posts', [$this, 'shortcode']);
        // Backward compatibility for existing installs.
        add_shortcode('kreativ_related_articles', [$this, 'shortcode']);

        add_action('init', [$this, 'register_block']);
    }

    public function load_textdomain() {
        load_plugin_textdomain('kreativ-smart-related-posts', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function activate() {
        $defaults = [
            'enable_ai'    => 0,
            'api_key'      => '',
            'layout'       => 'grid',
            'posts_per'    => 6,
            'show_excerpt' => 0,
            'auto_insert'  => 1,
            'title'        => __('Related Posts', 'kreativ-smart-related-posts'),
            'cache_hours'  => 12,
            'model'        => 'gpt-4o-mini',
        ];

        $existing = get_option(self::OPTION);
        if (!is_array($existing)) {
            update_option(self::OPTION, $defaults);
            return;
        }

        update_option(self::OPTION, wp_parse_args($existing, $defaults));
    }

    public function settings() {
        $defaults = [
            'enable_ai'    => 0,
            'api_key'      => '',
            'layout'       => 'grid',
            'posts_per'    => 6,
            'show_excerpt' => 0,
            'auto_insert'  => 1,
            'title'        => __('Related Posts', 'kreativ-smart-related-posts'),
            'cache_hours'  => 12,
            'model'        => 'gpt-4o-mini',
        ];

        $opt = get_option(self::OPTION, []);
        if (!is_array($opt)) {
            return $defaults;
        }

        return wp_parse_args($opt, $defaults);
    }

    public function admin_menu() {
        add_options_page(
            __('Kreativ Smart Related Posts', 'kreativ-smart-related-posts'),
            __('Kreativ Smart Related Posts', 'kreativ-smart-related-posts'),
            'manage_options',
            'kreativ-smart-related-posts',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('srp_group', self::OPTION, [$this, 'sanitize_settings']);

        add_settings_section(
            'srp_main',
            __('General', 'kreativ-smart-related-posts'),
            '__return_false',
            'kreativ-smart-related-posts'
        );

        add_settings_field('enable_ai', __('Enable AI re-ranking', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[enable_ai]" value="1" ' . checked(1, intval($opt['enable_ai']), false) . '/> ' . esc_html__('Use OpenAI to semantically re-rank candidate posts.', 'kreativ-smart-related-posts') . '</label>';
        }, 'kreativ-smart-related-posts', 'srp_main');

        add_settings_field('api_key', __('OpenAI API Key', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<input type="password" style="width:420px" name="' . esc_attr(self::OPTION) . '[api_key]" value="' . esc_attr($opt['api_key']) . '" placeholder="sk-..." autocomplete="off" />';
            echo '<p class="description">' . esc_html__('Only required when AI re-ranking is enabled.', 'kreativ-smart-related-posts') . '</p>';
        }, 'kreativ-smart-related-posts', 'srp_main');

        add_settings_field('model', __('OpenAI model', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<input type="text" style="width:260px" name="' . esc_attr(self::OPTION) . '[model]" value="' . esc_attr($opt['model']) . '" placeholder="gpt-4o-mini" />';
            echo '<p class="description">' . esc_html__('Model used only when AI re-ranking is enabled.', 'kreativ-smart-related-posts') . '</p>';
        }, 'kreativ-smart-related-posts', 'srp_main');

        add_settings_field('layout', __('Default layout', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            $layout = $opt['layout'];
            echo '<select name="' . esc_attr(self::OPTION) . '[layout]">';
            foreach (['grid' => 'Grid', 'list' => 'List', 'minimal' => 'Minimal'] as $k => $label) {
                echo '<option value="' . esc_attr($k) . '" ' . selected($layout, $k, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }, 'kreativ-smart-related-posts', 'srp_main');

        add_settings_field('posts_per', __('Number of posts', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<input type="number" min="1" max="12" name="' . esc_attr(self::OPTION) . '[posts_per]" value="' . intval($opt['posts_per']) . '" />';
        }, 'kreativ-smart-related-posts', 'srp_main');

        add_settings_field('show_excerpt', __('Show excerpt (grid/list)', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[show_excerpt]" value="1" ' . checked(1, intval($opt['show_excerpt']), false) . '/> ' . esc_html__('Display short excerpts for each related post.', 'kreativ-smart-related-posts') . '</label>';
        }, 'kreativ-smart-related-posts', 'srp_main');

        add_settings_field('auto_insert', __('Auto-insert after content', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[auto_insert]" value="1" ' . checked(1, intval($opt['auto_insert']), false) . '/> ' . esc_html__('Automatically append to single posts.', 'kreativ-smart-related-posts') . '</label>';
        }, 'kreativ-smart-related-posts', 'srp_main');

        add_settings_field('title', __('Section title', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<input type="text" style="width:420px" name="' . esc_attr(self::OPTION) . '[title]" value="' . esc_attr($opt['title']) . '" />';
        }, 'kreativ-smart-related-posts', 'srp_main');

        add_settings_field('cache_hours', __('Cache (hours)', 'kreativ-smart-related-posts'), function () {
            $opt = $this->settings();
            echo '<input type="number" min="0" max="168" name="' . esc_attr(self::OPTION) . '[cache_hours]" value="' . intval($opt['cache_hours']) . '" />';
            echo '<p class="description">' . esc_html__('Store results in a transient to reduce API/DB calls (0 to disable).', 'kreativ-smart-related-posts') . '</p>';
        }, 'kreativ-smart-related-posts', 'srp_main');
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

        $model = isset($input['model']) ? sanitize_text_field($input['model']) : $current['model'];
        if ($model === '') {
            $model = 'gpt-4o-mini';
        }

        return [
            'enable_ai'    => empty($input['enable_ai']) ? 0 : 1,
            'api_key'      => isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '',
            'layout'       => $layout,
            'posts_per'    => isset($input['posts_per']) ? max(1, min(12, intval($input['posts_per']))) : 6,
            'show_excerpt' => empty($input['show_excerpt']) ? 0 : 1,
            'auto_insert'  => empty($input['auto_insert']) ? 0 : 1,
            'title'        => isset($input['title']) ? sanitize_text_field($input['title']) : __('Related Posts', 'kreativ-smart-related-posts'),
            'cache_hours'  => isset($input['cache_hours']) ? max(0, min(168, intval($input['cache_hours']))) : 12,
            'model'        => $model,
        ];
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Kreativ Smart Related Posts', 'kreativ-smart-related-posts'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('srp_group'); ?>
                <?php do_settings_sections('kreativ-smart-related-posts'); ?>
                <?php submit_button(); ?>
            </form>
            <p><strong><?php esc_html_e('Shortcode:', 'kreativ-smart-related-posts'); ?></strong> <code>[smart_related_posts layout="grid" posts="6" excerpt="0"]</code></p>
            <p><strong><?php esc_html_e('Block:', 'kreativ-smart-related-posts'); ?></strong> <?php esc_html_e('Search for “Kreativ Smart Related Posts” in the block inserter.', 'kreativ-smart-related-posts'); ?></p>
            <hr />
            <h2><?php esc_html_e('External Services', 'kreativ-smart-related-posts'); ?></h2>
            <p><?php esc_html_e('When AI re-ranking is enabled, this plugin sends post title/excerpt and candidate post titles/excerpts to the OpenAI API for ranking.', 'kreativ-smart-related-posts'); ?></p>
            <p><a href="https://openai.com/policies/privacy-policy" target="_blank" rel="noopener noreferrer">OpenAI Privacy Policy</a> | <a href="https://openai.com/policies/terms-of-use" target="_blank" rel="noopener noreferrer">OpenAI Terms of Use</a></p>
        </div>
        <?php
    }

    public function enqueue_assets() {
        $css = '.srp-related{margin-top:1.75rem;font-size:.85rem}.srp-related h3{margin:0 0 .6rem}.srp-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}.srp-card{border:1px solid #eee;border-radius:14px;overflow:hidden}.srp-card a{display:block;text-decoration:none}.srp-thumb img{display:block;width:100%;height:auto}.srp-content{padding:10px}.srp-title{font-size:.95rem;font-weight:600;margin:0 0 .35rem;line-height:1.3}.srp-excerpt{color:#666;font-size:.88em;line-height:1.4;margin:0}.srp-list{list-style:none;padding-left:0;margin:0}.srp-list li{margin:.35rem 0}.srp-minimal{display:flex;flex-wrap:wrap;gap:.4rem}.srp-pill{padding:.2rem .45rem;border-radius:999px;background:#f5f5f5}.srp-titlebar{display:flex;align-items:center;gap:.45rem;margin-bottom:.6rem}.srp-dot{width:.55rem;height:.55rem;border-radius:999px;background:#00C2FF;display:inline-block}.srp-titletext{font-size:1rem;font-weight:700;letter-spacing:.15px}';
        wp_register_style('srp-related-posts', false, [], self::VERSION);
        wp_add_inline_style('srp-related-posts', $css);
    }

    public function flush_related_cache_on_save($post_id, $post, $update) {
        unset($update);

        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        if (!$post instanceof WP_Post || $post->post_type !== 'post') {
            return;
        }

        $this->flush_related_cache();
    }

    public function flush_related_cache_on_delete($post_id) {
        if (get_post_type($post_id) !== 'post') {
            return;
        }

        $this->flush_related_cache();
    }

    public function flush_related_cache_on_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        unset($terms, $tt_ids, $append, $old_tt_ids);

        if (get_post_type($object_id) !== 'post') {
            return;
        }

        if (!in_array($taxonomy, ['category', 'post_tag'], true)) {
            return;
        }

        $this->flush_related_cache();
    }

    private function flush_related_cache() {
        global $wpdb;

        $transient_prefix = $wpdb->esc_like('_transient_srp_rel_') . '%';
        $timeout_prefix = $wpdb->esc_like('_transient_timeout_srp_rel_') . '%';

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $transient_prefix,
            $timeout_prefix
        ));
    }

    public function auto_append_related($content) {
        if (!is_singular('post')) {
            return $content;
        }

        $opt = $this->settings();
        if (empty($opt['auto_insert'])) {
            return $content;
        }

        $section = $this->render_related(get_the_ID(), [
            'layout'  => $opt['layout'],
            'limit'   => intval($opt['posts_per']),
            'excerpt' => !empty($opt['show_excerpt']),
        ]);

        if ($section) {
            wp_enqueue_style('srp-related-posts');
            $content .= $section;
        }

        return $content;
    }

    public function shortcode($atts) {
        $opt = $this->settings();
        $atts = shortcode_atts([
            'layout'  => $opt['layout'],
            'posts'   => intval($opt['posts_per']),
            'excerpt' => intval($opt['show_excerpt']),
        ], $atts, 'smart_related_posts');

        wp_enqueue_style('srp-related-posts');

        return $this->render_related(get_the_ID(), [
            'layout'  => sanitize_text_field($atts['layout']),
            'limit'   => max(1, intval($atts['posts'])),
            'excerpt' => !empty($atts['excerpt']),
        ]);
    }

    private function render_related($post_id, $args) {
        if (!$post_id) {
            return '';
        }

        $opt = $this->settings();
        $title = isset($opt['title']) ? $opt['title'] : '';
        $posts = $this->get_related_posts($post_id, $args['limit']);
        if (empty($posts)) {
            return '';
        }

        $layout = in_array($args['layout'], ['grid', 'list', 'minimal'], true) ? $args['layout'] : 'grid';
        $show_excerpt = !empty($args['excerpt']);

        ob_start();
        ?>
        <section class="srp-related">
            <div class="srp-titlebar"><span class="srp-dot"></span><h3 class="srp-titletext"><?php echo esc_html($title); ?></h3></div>
            <?php if ($layout === 'grid') : ?>
                <ul class="srp-grid">
                    <?php foreach ($posts as $p) : ?>
                        <li class="srp-card">
                            <a href="<?php echo esc_url(get_permalink($p)); ?>" aria-label="<?php echo esc_attr(get_the_title($p)); ?>">
                                <div class="srp-thumb"><?php echo get_the_post_thumbnail($p, 'medium_large'); ?></div>
                                <div class="srp-content">
                                    <h4 class="srp-title"><?php echo esc_html(get_the_title($p)); ?></h4>
                                    <?php if ($show_excerpt) : ?>
                                        <p class="srp-excerpt"><?php echo esc_html(wp_trim_words(wp_strip_all_tags(get_the_excerpt($p)), 20)); ?></p>
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
                            <a href="<?php echo esc_url(get_permalink($p)); ?>"><strong><?php echo esc_html(get_the_title($p)); ?></strong></a>
                            <?php if ($show_excerpt) : ?>
                                <div class="srp-excerpt"><?php echo esc_html(wp_trim_words(wp_strip_all_tags(get_the_excerpt($p)), 22)); ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <div class="srp-minimal">
                    <?php foreach ($posts as $p) : ?>
                        <a class="srp-pill" href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private function get_related_posts($post_id, $limit = 6) {
        $opt = $this->settings();
        $cache_key = 'srp_rel_' . intval($post_id) . '_' . intval($limit) . '_' . (intval($opt['enable_ai']) ? 'ai' : 'basic');
        $cache_ttl = max(0, intval($opt['cache_hours'])) * HOUR_IN_SECONDS;

        if ($cache_ttl > 0) {
            $cached = get_transient($cache_key);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        $candidates = $this->get_taxonomy_candidates($post_id, min(40, max(8, $limit * 6)));
        if (empty($candidates)) {
            return [];
        }

        $ordered = $candidates;
        if (!empty($opt['enable_ai']) && !empty($opt['api_key'])) {
            $reordered = $this->ai_rerank($post_id, $candidates, $limit, $opt['api_key'], $opt['model']);
            if (!empty($reordered)) {
                $ordered = $reordered;
            }
        }

        $ordered = array_slice($ordered, 0, $limit);

        if ($cache_ttl > 0) {
            set_transient($cache_key, $ordered, $cache_ttl);
        }

        return $ordered;
    }

    private function get_taxonomy_candidates($post_id, $limit) {
        $terms_cat = wp_get_post_terms($post_id, 'category', ['fields' => 'ids']);
        $terms_tag = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'ids']);

        if (empty($terms_cat) && empty($terms_tag)) {
            return [];
        }

        $tax_query = ['relation' => 'OR'];

        if (!empty($terms_cat)) {
            $tax_query[] = [
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => $terms_cat,
            ];
        }

        if (!empty($terms_tag)) {
            $tax_query[] = [
                'taxonomy' => 'post_tag',
                'field'    => 'term_id',
                'terms'    => $terms_tag,
            ];
        }

        $q = new WP_Query([
            'post_type'           => 'post',
            'post__not_in'        => [intval($post_id)],
            'posts_per_page'      => intval($limit),
            'ignore_sticky_posts' => true,
            'tax_query'           => $tax_query,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'fields'              => 'ids',
        ]);

        $ids = is_array($q->posts) ? array_map('intval', $q->posts) : [];
        wp_reset_postdata();

        return $ids;
    }

    private function ai_rerank($post_id, $candidate_ids, $limit, $api_key, $model) {
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

        $body = [
            'model'       => sanitize_text_field($model),
            'messages'    => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => wp_json_encode($user)],
            ],
            'temperature' => 0.2,
        ];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $content = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : '';

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
        wp_register_script(
            'srp-block',
            plugins_url('assets/js/srp-block.js', __FILE__),
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n'],
            self::VERSION,
            true
        );
        wp_set_script_translations('srp-block', 'kreativ-smart-related-posts', plugin_dir_path(__FILE__) . 'languages');

        register_block_type('kreativ-smart-related-posts/related-posts', [
            'editor_script'   => 'srp-block',
            'render_callback' => function ($attrs) {
                $layout = isset($attrs['layout']) ? sanitize_text_field($attrs['layout']) : 'grid';
                $posts = isset($attrs['posts']) ? intval($attrs['posts']) : 6;
                $excerpt = !empty($attrs['excerpt']);
                wp_enqueue_style('srp-related-posts');
                return $this->render_related(get_the_ID(), ['layout' => $layout, 'limit' => max(1, $posts), 'excerpt' => $excerpt]);
            },
        ]);
    }
}

new Kreativ_Smart_Related_Posts();
