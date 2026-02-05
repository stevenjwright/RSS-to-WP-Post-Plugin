<?php
/**
 * MEF RSS Field Mapper — Discovers target fields and maps RSS item values.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MEF_RSS_Field_Mapper {

    /**
     * Get the list of available RSS source fields.
     */
    public function get_rss_source_fields(): array {
        return [
            'title'               => 'Title',
            'description'         => 'Description / Summary',
            'content'             => 'Content (content:encoded)',
            'link'                => 'Link / URL',
            'pubDate'             => 'Publication Date',
            'author'              => 'Author',
            'categories'          => 'Categories',
            'guid'                => 'GUID',
            'media_content_url'   => 'Media Content URL',
            'media_thumbnail_url' => 'Media Thumbnail URL',
            'enclosure_url'       => 'Enclosure URL',
            'enclosure_type'      => 'Enclosure MIME Type',
        ];
    }

    /**
     * Get all available target fields for a given post type.
     */
    public function get_target_fields_for_post_type( string $post_type ): array {
        $result = [
            'wp_fields'     => $this->get_wp_fields(),
            'acf_fields'    => $this->get_acf_fields( $post_type ),
            'taxonomies'    => $this->get_taxonomies( $post_type ),
            'acf_available' => function_exists( 'acf_get_field_groups' ),
        ];

        return $result;
    }

    /**
     * Standard WordPress post fields.
     */
    private function get_wp_fields(): array {
        return [
            [ 'key' => 'post_title',     'label' => 'Title' ],
            [ 'key' => 'post_content',   'label' => 'Content' ],
            [ 'key' => 'post_excerpt',   'label' => 'Excerpt' ],
            [ 'key' => 'post_date',      'label' => 'Publish Date' ],
            [ 'key' => 'featured_image', 'label' => 'Featured Image (URL)' ],
        ];
    }

    /**
     * Get ACF fields registered for a post type.
     */
    private function get_acf_fields( string $post_type ): array {
        if ( ! function_exists( 'acf_get_field_groups' ) ) {
            return [];
        }

        $groups = acf_get_field_groups( [ 'post_type' => $post_type ] );
        $fields = [];

        foreach ( $groups as $group ) {
            $group_fields = acf_get_fields( $group['key'] );
            if ( ! $group_fields ) {
                continue;
            }
            foreach ( $group_fields as $field ) {
                $fields[] = [
                    'key'   => $field['name'],
                    'label' => $field['label'],
                    'type'  => $field['type'],
                ];
            }
        }

        return $fields;
    }

    /**
     * Get taxonomies registered for a post type.
     */
    private function get_taxonomies( string $post_type ): array {
        $taxonomies = get_object_taxonomies( $post_type, 'objects' );
        $result     = [];

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! $taxonomy->public ) {
                continue;
            }
            $result[] = [
                'key'          => $taxonomy->name,
                'label'        => $taxonomy->label,
                'hierarchical' => $taxonomy->hierarchical,
            ];
        }

        return $result;
    }

    /**
     * Extract a value from a SimplePie item by source key.
     */
    public function extract_rss_value( $item, string $source_key ) {
        switch ( $source_key ) {
            case 'title':
                return $item->get_title();

            case 'description':
                return $item->get_description();

            case 'content':
                return $item->get_content();

            case 'link':
                return $item->get_link();

            case 'pubDate':
                return $item->get_date( 'Y-m-d H:i:s' );

            case 'author':
                $author = $item->get_author();
                return $author ? $author->get_name() : '';

            case 'categories':
                $cats = $item->get_categories();
                if ( ! $cats ) {
                    return [];
                }
                return array_map( function ( $cat ) {
                    return $cat->get_label();
                }, $cats );

            case 'guid':
                return $item->get_id();

            case 'media_content_url':
                $tags = $item->get_item_tags( SIMPLEPIE_NAMESPACE_MEDIARSS, 'content' );
                if ( $tags && isset( $tags[0]['attribs']['']['url'] ) ) {
                    return $tags[0]['attribs']['']['url'];
                }
                // Fallback to enclosure.
                $enclosure = $item->get_enclosure();
                if ( $enclosure && strpos( $enclosure->get_type(), 'image' ) !== false ) {
                    return $enclosure->get_link();
                }
                return '';

            case 'media_thumbnail_url':
                $tags = $item->get_item_tags( SIMPLEPIE_NAMESPACE_MEDIARSS, 'thumbnail' );
                if ( $tags && isset( $tags[0]['attribs']['']['url'] ) ) {
                    return $tags[0]['attribs']['']['url'];
                }
                return '';

            case 'enclosure_url':
                $enclosure = $item->get_enclosure();
                return $enclosure ? $enclosure->get_link() : '';

            case 'enclosure_type':
                $enclosure = $item->get_enclosure();
                return $enclosure ? $enclosure->get_type() : '';

            default:
                return '';
        }
    }

    /**
     * Apply all field mappings to a SimplePie item.
     *
     * Returns structured data ready for post creation.
     */
    public function apply_mapping( $item, array $field_mapping ): array {
        $result = [
            'post_data'          => [],
            'featured_image_url' => '',
            'acf_fields'         => [],
            'taxonomies'         => [],
            'meta_fields'        => [],
        ];

        foreach ( $field_mapping as $mapping ) {
            $value = $this->extract_rss_value( $item, $mapping['source'] );

            if ( $value === '' || $value === null || $value === [] ) {
                continue;
            }

            switch ( $mapping['target_type'] ) {
                case 'wp_field':
                    if ( $mapping['target_key'] === 'featured_image' ) {
                        $result['featured_image_url'] = $value;
                    } else {
                        $result['post_data'][ $mapping['target_key'] ] = $value;
                    }
                    break;

                case 'acf_field':
                    $result['acf_fields'][ $mapping['target_key'] ] = $value;
                    break;

                case 'taxonomy':
                    $terms = is_array( $value ) ? $value : [ $value ];
                    $result['taxonomies'][ $mapping['target_key'] ] = $terms;
                    break;

                case 'meta_field':
                    $result['meta_fields'][ $mapping['target_key'] ] = $value;
                    break;
            }
        }

        return $result;
    }
}
