<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('srp_settings');
delete_option('kra_settings');
delete_option('ksrp_cache_generation');
wp_unschedule_hook('ksrp_refresh_ai_ranking');

$kreativ_smart_related_posts_transient_prefix = $wpdb->esc_like('_transient_srp_rel_') . '%';
$kreativ_smart_related_posts_timeout_prefix = $wpdb->esc_like('_transient_timeout_srp_rel_') . '%';
$kreativ_smart_related_posts_retry_prefix = $wpdb->esc_like('_transient_ksrp_ai_retry_') . '%';
$kreativ_smart_related_posts_retry_timeout_prefix = $wpdb->esc_like('_transient_timeout_ksrp_ai_retry_') . '%';
$kreativ_smart_related_posts_lock_prefix = $wpdb->esc_like('ksrp_ai_lock_') . '%';

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
    $kreativ_smart_related_posts_transient_prefix,
    $kreativ_smart_related_posts_timeout_prefix,
    $kreativ_smart_related_posts_retry_prefix,
    $kreativ_smart_related_posts_retry_timeout_prefix,
    $kreativ_smart_related_posts_lock_prefix
));
