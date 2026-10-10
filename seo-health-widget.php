<?php
/**
 * Plugin Name:       SEO Health Widget
 * Description:       Dashboard widget showing Search Console trends and a daily on-site SEO audit.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       seo-health-widget
 */

defined( 'ABSPATH' ) || exit;

define( 'SEOHW_VERSION', '0.1.0' );
define( 'SEOHW_FILE', __FILE__ );
define( 'SEOHW_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEOHW_URL', plugin_dir_url( __FILE__ ) );

define( 'SEOHW_OPTION', 'seohw_settings' );
define( 'SEOHW_AUDIT_OPTION', 'seohw_audit_results' );
define( 'SEOHW_CRON_HOOK', 'seohw_daily_audit' );

require_once SEOHW_DIR . 'includes/class-seohw-settings.php';
require_once SEOHW_DIR . 'includes/class-seohw-gsc-client.php';
require_once SEOHW_DIR . 'includes/class-seohw-seo-meta.php';
require_once SEOHW_DIR . 'includes/class-seohw-audit.php';
require_once SEOHW_DIR . 'includes/class-seohw-widget.php';

SEOHW_Settings::init();
SEOHW_Widget::init();
SEOHW_Audit::init();

register_activation_hook( __FILE__, array( 'SEOHW_Audit', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'SEOHW_Audit', 'unschedule' ) );
