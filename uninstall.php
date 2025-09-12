<?php
/**
 * Cleanup script for uninstall
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// Verwijder opties
delete_option('printcom_ot_settings');
delete_option('printcom_ot_mappings');

// Opruimen van transients
global $wpdb;
$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_printcom_ot_%'");
$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_printcom_ot_%'");