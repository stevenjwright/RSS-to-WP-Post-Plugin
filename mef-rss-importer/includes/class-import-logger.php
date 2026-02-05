<?php
/**
 * MEF RSS Import Logger — Stores import run history in wp_options.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MEF_RSS_Import_Logger {

    private const MAX_ENTRIES = 200;

    /**
     * Log an import run.
     */
    public function log_import_run( array $data ): void {
        $logs = get_option( MEF_RSS_IMPORTER_LOG_OPTION, [] );

        array_unshift( $logs, [
            'feed_id'        => sanitize_text_field( $data['feed_id'] ?? '' ),
            'feed_name'      => sanitize_text_field( $data['feed_name'] ?? '' ),
            'timestamp'      => current_time( 'mysql' ),
            'status'         => sanitize_text_field( $data['status'] ?? 'error' ),
            'created'        => absint( $data['created'] ?? 0 ),
            'updated'        => absint( $data['updated'] ?? 0 ),
            'skipped'        => absint( $data['skipped'] ?? 0 ),
            'errors'         => absint( $data['errors'] ?? 0 ),
            'error_messages' => array_map( 'sanitize_text_field', (array) ( $data['error_messages'] ?? [] ) ),
            'duration'       => round( floatval( $data['duration'] ?? 0 ), 2 ),
        ] );

        // Trim to max entries.
        $logs = array_slice( $logs, 0, self::MAX_ENTRIES );

        update_option( MEF_RSS_IMPORTER_LOG_OPTION, $logs );
    }

    /**
     * Get recent log entries, optionally filtered by feed.
     */
    public function get_logs( int $limit = 50, string $feed_id = '' ): array {
        $logs = get_option( MEF_RSS_IMPORTER_LOG_OPTION, [] );

        if ( $feed_id ) {
            $logs = array_filter( $logs, function ( $entry ) use ( $feed_id ) {
                return $entry['feed_id'] === $feed_id;
            } );
        }

        return array_slice( $logs, 0, $limit );
    }

    /**
     * Clear all logs.
     */
    public function clear_logs(): void {
        update_option( MEF_RSS_IMPORTER_LOG_OPTION, [] );
    }
}
