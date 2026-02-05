<?php
/**
 * Plugin Name: MEF RSS Feed Importer
 * Description: Import and sync RSS feeds into WordPress custom post types with field mapping.
 * Version: 1.0.0
 * Author: Steven J. Wright
 * Author URI: mailto:hey@stevenjwright.me
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/mef-rss-importer/mef-rss-importer.php';
