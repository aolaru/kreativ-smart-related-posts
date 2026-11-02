<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function kreasmre_uninstall_cleanup() {
    global $wpdb;

    delete_option('kreasmre_settings');
    delete_option('srp_settings');
    delete_option('kra_settings');
    delete_option('kreasmre_cache_generation');
    delete_option('ksrp_cache_generation');
    wp_unschedule_hook('kreasmre_refresh_ai_ranking');
    wp_unschedule_hook('ksrp_refresh_ai_ranking');

    $kreativ_smart_related_posts_transient_prefix = $wpdb->esc_like('_transient_kreasmre_rel_') . '%';
    $kreativ_smart_related_posts_timeout_prefix = $wpdb->esc_like('_transient_timeout_kreasmre_rel_') . '%';
    $kreativ_smart_related_posts_retry_prefix = $wpdb->esc_like('_transient_kreasmre_ai_retry_') . '%';
    $kreativ_smart_related_posts_retry_timeout_prefix = $wpdb->esc_like('_transient_timeout_kreasmre_ai_retry_') . '%';
    $kreativ_smart_related_posts_lock_prefix = $wpdb->esc_like('kreasmre_ai_lock_') . '%';
    $kreativ_smart_related_posts_legacy_cache = $wpdb->esc_like('_transient_srp_rel_') . '%';
    $kreativ_smart_related_posts_legacy_timeout = $wpdb->esc_like('_transient_timeout_srp_rel_') . '%';
    $kreativ_smart_related_posts_legacy_retry = $wpdb->esc_like('_transient_ksrp_ai_retry_') . '%';
    $kreativ_smart_related_posts_legacy_retry_timeout = $wpdb->esc_like('_transient_timeout_ksrp_ai_retry_') . '%';
    $kreativ_smart_related_posts_legacy_lock = $wpdb->esc_like('ksrp_ai_lock_') . '%';

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $kreativ_smart_related_posts_transient_prefix,
        $kreativ_smart_related_posts_timeout_prefix,
        $kreativ_smart_related_posts_retry_prefix,
        $kreativ_smart_related_posts_retry_timeout_prefix,
        $kreativ_smart_related_posts_lock_prefix,
        $kreativ_smart_related_posts_legacy_cache,
        $kreativ_smart_related_posts_legacy_timeout,
        $kreativ_smart_related_posts_legacy_retry,
        $kreativ_smart_related_posts_legacy_retry_timeout,
        $kreativ_smart_related_posts_legacy_lock
    ));
}

kreasmre_uninstall_cleanup();
