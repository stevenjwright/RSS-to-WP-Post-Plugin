<?php
/**
 * MEF RSS Feed Importer - Main Bootstrap
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MEF_RSS_IMPORTER_VERSION', '1.0.0' );
define( 'MEF_RSS_IMPORTER_PATH', __DIR__ );
// Supports both normal plugins and MU plugins.
define( 'MEF_RSS_IMPORTER_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'MEF_RSS_IMPORTER_OPTION_KEY', 'mef_rss_feeds' );
define( 'MEF_RSS_IMPORTER_LOG_OPTION', 'mef_rss_import_logs' );

// Load class files.
require_once MEF_RSS_IMPORTER_PATH . '/includes/class-feed-manager.php';
require_once MEF_RSS_IMPORTER_PATH . '/includes/class-import-logger.php';
require_once MEF_RSS_IMPORTER_PATH . '/includes/class-field-mapper.php';
require_once MEF_RSS_IMPORTER_PATH . '/includes/class-feed-processor.php';
require_once MEF_RSS_IMPORTER_PATH . '/includes/class-cron-manager.php';
require_once MEF_RSS_IMPORTER_PATH . '/includes/class-admin-page.php';

/**
 * Initialize plugin on plugins_loaded.
 */
add_action( 'plugins_loaded', function () {
    $feed_manager  = new MEF_RSS_Feed_Manager();
    $logger        = new MEF_RSS_Import_Logger();
    $field_mapper  = new MEF_RSS_Field_Mapper();
    $processor     = new MEF_RSS_Feed_Processor( $feed_manager, $field_mapper, $logger );
    $cron_manager  = new MEF_RSS_Cron_Manager( $feed_manager, $processor );
    $admin_page    = new MEF_RSS_Admin_Page( $feed_manager, $processor, $cron_manager, $field_mapper, $logger );

    $cron_manager->init();
    $admin_page->init();
} );
