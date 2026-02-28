<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('srp_settings');
delete_option('kra_settings');

$transient_prefix = $wpdb->esc_like('_transient_srp_rel_') . '%';
$timeout_prefix = $wpdb->esc_like('_transient_timeout_srp_rel_') . '%';

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $transient_prefix,
    $timeout_prefix
));
