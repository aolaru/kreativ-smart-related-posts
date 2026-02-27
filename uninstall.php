<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('srp_settings');
delete_option('kra_settings');
