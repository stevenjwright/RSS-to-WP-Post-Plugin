<?php
/**
 * MEF RSS Feed Manager — CRUD for feed configurations stored in wp_options.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MEF_RSS_Feed_Manager {

    /**
     * Get all feed configurations.
     */
    public function get_all_feeds(): array {
        return get_option( MEF_RSS_IMPORTER_OPTION_KEY, [] );
    }

    /**
     * Get a single feed by ID.
     */
    public function get_feed( string $id ): ?array {
        $feeds = $this->get_all_feeds();
        return $feeds[ $id ] ?? null;
    }

    /**
     * Get only enabled feeds.
     */
    public function get_enabled_feeds(): array {
        return array_filter( $this->get_all_feeds(), function ( $feed ) {
            return ! empty( $feed['enabled'] );
        } );
    }

    /**
     * Create or update a feed configuration. Returns the feed ID.
     */
    public function save_feed( array $data ): string {
        $feeds = $this->get_all_feeds();
        $now   = current_time( 'mysql' );

        // Generate ID for new feeds.
        if ( empty( $data['id'] ) ) {
            $data['id']         = str_replace( '.', '_', uniqid( 'feed_', true ) );
            $data['created_at'] = $now;
            $data['last_run']        = '';
            $data['last_run_status'] = '';
            $data['last_run_count']  = 0;
        } else {
            // Preserve existing timestamps and run data on update.
            $existing = $feeds[ $data['id'] ] ?? [];
            $data['created_at']      = $existing['created_at'] ?? $now;
            $data['last_run']        = $existing['last_run'] ?? '';
            $data['last_run_status'] = $existing['last_run_status'] ?? '';
            $data['last_run_count']  = $existing['last_run_count'] ?? 0;
        }

        $data['updated_at'] = $now;

        // Sanitize.
        $sanitized = [
            'id'              => sanitize_text_field( $data['id'] ),
            'name'            => sanitize_text_field( $data['name'] ?? '' ),
            'url'             => esc_url_raw( $data['url'] ?? '' ),
            'post_type'       => sanitize_key( $data['post_type'] ?? 'post' ),
            'post_status'     => in_array( $data['post_status'] ?? '', [ 'draft', 'publish' ], true ) ? $data['post_status'] : 'draft',
            'post_author'     => absint( $data['post_author'] ?? 1 ),
            'cron_interval'   => in_array( $data['cron_interval'] ?? '', [ 'hourly', 'twicedaily', 'daily', 'weekly' ], true ) ? $data['cron_interval'] : 'daily',
            'max_items'       => absint( $data['max_items'] ?? 20 ) ?: 20,
            'enabled'         => ! empty( $data['enabled'] ),
            'created_at'      => $data['created_at'],
            'updated_at'      => $data['updated_at'],
            'last_run'        => $data['last_run'],
            'last_run_status' => $data['last_run_status'],
            'last_run_count'  => absint( $data['last_run_count'] ),
            'field_mapping'   => [],
        ];

        // Sanitize field mappings.
        if ( ! empty( $data['field_mapping'] ) && is_array( $data['field_mapping'] ) ) {
            foreach ( $data['field_mapping'] as $row ) {
                if ( empty( $row['source'] ) || empty( $row['target_type'] ) || empty( $row['target_key'] ) ) {
                    continue;
                }
                $sanitized['field_mapping'][] = [
                    'source'      => sanitize_text_field( $row['source'] ),
                    'target_type' => sanitize_key( $row['target_type'] ),
                    'target_key'  => sanitize_text_field( $row['target_key'] ),
                ];
            }
        }

        $feeds[ $sanitized['id'] ] = $sanitized;
        update_option( MEF_RSS_IMPORTER_OPTION_KEY, $feeds );

        return $sanitized['id'];
    }

    /**
     * Delete a feed configuration.
     */
    public function delete_feed( string $id ): bool {
        $feeds = $this->get_all_feeds();
        if ( ! isset( $feeds[ $id ] ) ) {
            return false;
        }
        unset( $feeds[ $id ] );
        update_option( MEF_RSS_IMPORTER_OPTION_KEY, $feeds );
        return true;
    }

    /**
     * Update the last-run metadata for a feed.
     */
    public function update_feed_run_status( string $id, string $status, int $count ): void {
        $feeds = $this->get_all_feeds();
        if ( ! isset( $feeds[ $id ] ) ) {
            return;
        }
        $feeds[ $id ]['last_run']        = current_time( 'mysql' );
        $feeds[ $id ]['last_run_status'] = sanitize_text_field( $status );
        $feeds[ $id ]['last_run_count']  = absint( $count );
        update_option( MEF_RSS_IMPORTER_OPTION_KEY, $feeds );
    }
}
