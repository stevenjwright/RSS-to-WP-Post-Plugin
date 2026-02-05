<?php
/**
 * MEF RSS Feed Processor — Fetches RSS and upserts posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MEF_RSS_Feed_Processor {

    private MEF_RSS_Feed_Manager $feed_manager;
    private MEF_RSS_Field_Mapper $field_mapper;
    private MEF_RSS_Import_Logger $logger;

    public function __construct(
        MEF_RSS_Feed_Manager $feed_manager,
        MEF_RSS_Field_Mapper $field_mapper,
        MEF_RSS_Import_Logger $logger
    ) {
        $this->feed_manager = $feed_manager;
        $this->field_mapper = $field_mapper;
        $this->logger       = $logger;
    }

    /**
     * Process a single feed by ID.
     *
     * @param bool $bypass_cache When true, sets SimplePie cache to 0 (for manual runs).
     */
    public function process_feed( string $feed_id, bool $bypass_cache = false ): array {
        $start  = microtime( true );
        $config = $this->feed_manager->get_feed( $feed_id );

        if ( ! $config ) {
            return [ 'success' => false, 'message' => 'Feed not found.' ];
        }

        if ( ! $config['enabled'] && ! $bypass_cache ) {
            return [ 'success' => false, 'message' => 'Feed is disabled.' ];
        }

        // Temporarily reduce SimplePie cache for manual runs.
        $cache_filter = null;
        if ( $bypass_cache ) {
            $cache_filter = function () {
                return 0;
            };
            add_filter( 'wp_feed_cache_transient_lifetime', $cache_filter );
        }

        $feed = fetch_feed( $config['url'] );

        if ( $cache_filter ) {
            remove_filter( 'wp_feed_cache_transient_lifetime', $cache_filter );
        }

        if ( is_wp_error( $feed ) ) {
            $error_msg = $feed->get_error_message();
            $this->feed_manager->update_feed_run_status( $feed_id, 'error', 0 );
            $this->logger->log_import_run( [
                'feed_id'        => $feed_id,
                'feed_name'      => $config['name'],
                'status'         => 'error',
                'error_messages' => [ $error_msg ],
                'duration'       => microtime( true ) - $start,
            ] );
            return [ 'success' => false, 'message' => $error_msg ];
        }

        $items   = $feed->get_items( 0, $config['max_items'] );
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = 0;
        $error_messages = [];

        // Ensure media functions are available (needed in cron context).
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        foreach ( $items as $item ) {
            try {
                $result = $this->process_item( $item, $config );
                if ( $result === 'created' ) {
                    $created++;
                } elseif ( $result === 'updated' ) {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch ( \Exception $e ) {
                $errors++;
                $error_messages[] = $e->getMessage();
            }
        }

        $total_imported = $created + $updated;
        $status         = $errors > 0 ? ( $total_imported > 0 ? 'partial' : 'error' ) : 'success';
        $duration       = microtime( true ) - $start;

        $this->feed_manager->update_feed_run_status( $feed_id, $status, $total_imported );
        $this->logger->log_import_run( [
            'feed_id'        => $feed_id,
            'feed_name'      => $config['name'],
            'status'         => $status,
            'created'        => $created,
            'updated'        => $updated,
            'skipped'        => $skipped,
            'errors'         => $errors,
            'error_messages' => $error_messages,
            'duration'       => $duration,
        ] );

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
            'message' => sprintf( 'Import complete: %d created, %d updated, %d skipped, %d errors.', $created, $updated, $skipped, $errors ),
        ];
    }

    /**
     * Process a single RSS item — upsert logic.
     *
     * @return string 'created', 'updated', or 'skipped'
     */
    private function process_item( $item, array $config ): string {
        // Determine the unique identifier for deduplication.
        $guid = $item->get_id();
        if ( empty( $guid ) ) {
            $guid = $item->get_link();
        }
        if ( empty( $guid ) ) {
            throw new \Exception( 'Item has no GUID or link for deduplication.' );
        }

        // Check for existing post.
        $existing_query = new WP_Query( [
            'post_type'      => $config['post_type'],
            'post_status'    => 'any',
            'meta_query'     => [
                [
                    'key'   => '_rss_import_guid',
                    'value' => $guid,
                ],
            ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        $existing_id = $existing_query->posts ? $existing_query->posts[0] : 0;

        // Apply field mappings.
        $mapped = $this->field_mapper->apply_mapping( $item, $config['field_mapping'] );

        // Build post args.
        $post_args = array_merge( $mapped['post_data'], [
            'post_type'   => $config['post_type'],
            'post_status' => $config['post_status'],
            'post_author' => $config['post_author'],
        ] );

        // Ensure we have at least a title.
        if ( empty( $post_args['post_title'] ) ) {
            $post_args['post_title'] = $item->get_title() ?: '(No title)';
        }

        $action = 'skipped';

        if ( $existing_id ) {
            $post_args['ID'] = $existing_id;
            $result = wp_update_post( $post_args, true );
            if ( is_wp_error( $result ) ) {
                throw new \Exception( 'Update failed: ' . $result->get_error_message() );
            }
            $post_id = $existing_id;
            $action  = 'updated';
        } else {
            $result = wp_insert_post( $post_args, true );
            if ( is_wp_error( $result ) ) {
                throw new \Exception( 'Insert failed: ' . $result->get_error_message() );
            }
            $post_id = $result;
            $action  = 'created';
        }

        // Set tracking meta.
        update_post_meta( $post_id, '_rss_import_guid', $guid );
        update_post_meta( $post_id, '_rss_import_feed_id', $config['id'] );
        update_post_meta( $post_id, '_rss_import_last_updated', current_time( 'mysql' ) );

        // Handle featured image.
        if ( ! empty( $mapped['featured_image_url'] ) ) {
            $this->handle_featured_image( $post_id, $mapped['featured_image_url'] );
        }

        // Handle ACF fields.
        if ( ! empty( $mapped['acf_fields'] ) && function_exists( 'update_field' ) ) {
            foreach ( $mapped['acf_fields'] as $field_key => $value ) {
                update_field( $field_key, $value, $post_id );
            }
        }

        // Handle taxonomies.
        if ( ! empty( $mapped['taxonomies'] ) ) {
            foreach ( $mapped['taxonomies'] as $taxonomy => $terms ) {
                $this->assign_taxonomy_terms( $post_id, $taxonomy, $terms );
            }
        }

        // Handle custom meta fields.
        if ( ! empty( $mapped['meta_fields'] ) ) {
            foreach ( $mapped['meta_fields'] as $meta_key => $meta_value ) {
                update_post_meta( $post_id, $meta_key, $meta_value );
            }
        }

        return $action;
    }

    /**
     * Download and set a featured image from a URL.
     */
    private function handle_featured_image( int $post_id, string $image_url ): void {
        // Skip if the same image is already set.
        $existing_thumb_id = get_post_thumbnail_id( $post_id );
        if ( $existing_thumb_id ) {
            $existing_source = get_post_meta( $existing_thumb_id, '_rss_import_source_url', true );
            if ( $existing_source === $image_url ) {
                return;
            }
        }

        $attachment_id = media_sideload_image( $image_url, $post_id, '', 'id' );

        if ( is_wp_error( $attachment_id ) ) {
            return; // Silently skip — image failures shouldn't block the import.
        }

        set_post_thumbnail( $post_id, $attachment_id );
        update_post_meta( $attachment_id, '_rss_import_source_url', $image_url );
    }

    /**
     * Assign taxonomy terms, creating them if they don't exist.
     */
    private function assign_taxonomy_terms( int $post_id, string $taxonomy, array $term_names ): void {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return;
        }

        $term_ids = [];

        foreach ( $term_names as $name ) {
            $name = trim( $name );
            if ( empty( $name ) ) {
                continue;
            }

            $term = get_term_by( 'name', $name, $taxonomy );
            if ( $term ) {
                $term_ids[] = $term->term_id;
            } else {
                $inserted = wp_insert_term( $name, $taxonomy );
                if ( ! is_wp_error( $inserted ) ) {
                    $term_ids[] = $inserted['term_id'];
                }
            }
        }

        if ( $term_ids ) {
            wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
        }
    }
}
