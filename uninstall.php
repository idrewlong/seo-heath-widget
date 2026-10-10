<?php
/**
 * Removes all plugin data on uninstall.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'seohw_settings' );
delete_option( 'seohw_audit_results' );
delete_option( 'seohw_cache_gen' );
delete_option( 'seohw_audit_state' );
delete_transient( 'seohw_access_token' );
delete_transient( 'seohw_test_result' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_seohw\\_%' OR option_name LIKE '\\_transient\\_timeout\\_seohw\\_%'" );

wp_clear_scheduled_hook( 'seohw_daily_audit' );
wp_clear_scheduled_hook( 'seohw_audit_continue' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'seohw_audit_continue' );
}
