<?php
/**
 * MEF RSS Admin Page — Menu registration, views, form handling, AJAX.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MEF_RSS_Admin_Page {

    private MEF_RSS_Feed_Manager $feed_manager;
    private MEF_RSS_Feed_Processor $processor;
    private MEF_RSS_Cron_Manager $cron_manager;
    private MEF_RSS_Field_Mapper $field_mapper;
    private MEF_RSS_Import_Logger $logger;

    public function __construct(
        MEF_RSS_Feed_Manager $feed_manager,
        MEF_RSS_Feed_Processor $processor,
        MEF_RSS_Cron_Manager $cron_manager,
        MEF_RSS_Field_Mapper $field_mapper,
        MEF_RSS_Import_Logger $logger
    ) {
        $this->feed_manager = $feed_manager;
        $this->processor    = $processor;
        $this->cron_manager = $cron_manager;
        $this->field_mapper = $field_mapper;
        $this->logger       = $logger;
    }

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'handle_form_submission' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_mef_rss_get_post_type_fields', [ $this, 'ajax_get_post_type_fields' ] );
        add_action( 'wp_ajax_mef_rss_preview_feed', [ $this, 'ajax_preview_feed' ] );
        add_action( 'wp_ajax_mef_rss_run_feed_now', [ $this, 'ajax_run_feed_now' ] );
        add_action( 'wp_ajax_mef_rss_delete_feed', [ $this, 'ajax_delete_feed' ] );
        add_action( 'wp_ajax_mef_rss_toggle_feed', [ $this, 'ajax_toggle_feed' ] );
    }

    public function register_menu(): void {
        add_menu_page(
            'RSS Feed Importer',
            'RSS Importer',
            'manage_options',
            'mef-rss-importer',
            [ $this, 'render_feeds_list' ],
            'dashicons-rss',
            80
        );

        add_submenu_page(
            'mef-rss-importer',
            'All Feeds',
            'All Feeds',
            'manage_options',
            'mef-rss-importer',
            [ $this, 'render_feeds_list' ]
        );

        add_submenu_page(
            'mef-rss-importer',
            'Add New Feed',
            'Add New',
            'manage_options',
            'mef-rss-importer-add',
            [ $this, 'render_feed_form' ]
        );

        add_submenu_page(
            'mef-rss-importer',
            'Import Log',
            'Import Log',
            'manage_options',
            'mef-rss-importer-log',
            [ $this, 'render_import_log' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'mef-rss-importer' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'mef-rss-admin',
            MEF_RSS_IMPORTER_URL . 'assets/css/admin.css',
            [],
            MEF_RSS_IMPORTER_VERSION
        );

        wp_enqueue_script(
            'mef-rss-admin',
            MEF_RSS_IMPORTER_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            MEF_RSS_IMPORTER_VERSION,
            true
        );

        wp_localize_script( 'mef-rss-admin', 'mefRssImporter', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'mef_rss_importer_nonce' ),
            'rssSourceFields' => $this->field_mapper->get_rss_source_fields(),
        ] );
    }

    // -------------------------------------------------------------------------
    // Views
    // -------------------------------------------------------------------------

    public function render_feeds_list(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $feeds = $this->feed_manager->get_all_feeds();
        $saved = isset( $_GET['saved'] );
        $deleted = isset( $_GET['deleted'] );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">RSS Feed Importer</h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=mef-rss-importer-add' ) ); ?>" class="page-title-action">Add New Feed</a>
            <hr class="wp-header-end">

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Feed saved successfully.</p></div>
            <?php endif; ?>
            <?php if ( $deleted ) : ?>
                <div class="notice notice-success is-dismissible"><p>Feed deleted.</p></div>
            <?php endif; ?>

            <?php if ( empty( $feeds ) ) : ?>
                <div class="mef-rss-empty-state">
                    <p>No RSS feeds configured yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=mef-rss-importer-add' ) ); ?>">Add your first feed</a>.</p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Feed Name</th>
                            <th>URL</th>
                            <th>Post Type</th>
                            <th>Interval</th>
                            <th>Status</th>
                            <th>Last Run</th>
                            <th>Result</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $feeds as $feed ) : ?>
                            <tr data-feed-id="<?php echo esc_attr( $feed['id'] ); ?>">
                                <td><strong><?php echo esc_html( $feed['name'] ); ?></strong></td>
                                <td class="mef-rss-url-cell" title="<?php echo esc_attr( $feed['url'] ); ?>"><?php echo esc_html( $feed['url'] ); ?></td>
                                <td><?php echo esc_html( $feed['post_type'] ); ?></td>
                                <td><?php echo esc_html( ucfirst( $feed['cron_interval'] ) ); ?></td>
                                <td>
                                    <span class="mef-rss-status-badge mef-rss-status-<?php echo $feed['enabled'] ? 'enabled' : 'disabled'; ?>">
                                        <?php echo $feed['enabled'] ? 'Enabled' : 'Disabled'; ?>
                                    </span>
                                </td>
                                <td><?php echo $feed['last_run'] ? esc_html( $feed['last_run'] ) : '&mdash;'; ?></td>
                                <td>
                                    <?php if ( $feed['last_run_status'] ) : ?>
                                        <span class="mef-rss-status-badge mef-rss-status-<?php echo esc_attr( $feed['last_run_status'] ); ?>">
                                            <?php echo esc_html( ucfirst( $feed['last_run_status'] ) ); ?>
                                            (<?php echo esc_html( $feed['last_run_count'] ); ?>)
                                        </span>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td class="mef-rss-actions-cell">
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mef-rss-importer-add&feed_id=' . $feed['id'] ) ); ?>" class="button button-small">Edit</a>
                                    <button type="button" class="button button-small mef-rss-run-now" data-feed-id="<?php echo esc_attr( $feed['id'] ); ?>">Run Now</button>
                                    <button type="button" class="button button-small mef-rss-toggle-feed" data-feed-id="<?php echo esc_attr( $feed['id'] ); ?>">
                                        <?php echo $feed['enabled'] ? 'Disable' : 'Enable'; ?>
                                    </button>
                                    <button type="button" class="button button-small button-link-delete mef-rss-delete-feed" data-feed-id="<?php echo esc_attr( $feed['id'] ); ?>">Delete</button>
                                    <span class="mef-rss-spinner spinner" style="float:none;"></span>
                                    <span class="mef-rss-run-result"></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_feed_form(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $feed_id = sanitize_text_field( $_GET['feed_id'] ?? '' );
        $feed    = $feed_id ? $this->feed_manager->get_feed( $feed_id ) : null;
        $is_edit = (bool) $feed;

        $post_types     = get_post_types( [ 'public' => true ], 'objects' );
        $source_fields  = $this->field_mapper->get_rss_source_fields();
        $intervals      = [
            'hourly'     => 'Hourly',
            'twicedaily' => 'Twice Daily',
            'daily'      => 'Daily',
            'weekly'     => 'Weekly',
        ];

        // If editing, pre-load target fields for the current post type.
        $current_target_fields = null;
        if ( $feed ) {
            $current_target_fields = $this->field_mapper->get_target_fields_for_post_type( $feed['post_type'] );
        }
        ?>
        <div class="wrap">
            <h1><?php echo $is_edit ? 'Edit Feed' : 'Add New Feed'; ?></h1>
            <hr class="wp-header-end">

            <form method="post" action="" id="mef-rss-feed-form">
                <?php wp_nonce_field( 'mef_rss_save_feed', 'mef_rss_nonce' ); ?>
                <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed['id'] ?? '' ); ?>">

                <table class="form-table">
                    <tr>
                        <th><label for="feed_name">Feed Name</label></th>
                        <td><input type="text" id="feed_name" name="feed_name" class="regular-text" required value="<?php echo esc_attr( $feed['name'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="feed_url">Feed URL</label></th>
                        <td>
                            <input type="url" id="feed_url" name="feed_url" class="regular-text" required value="<?php echo esc_attr( $feed['url'] ?? '' ); ?>">
                            <button type="button" class="button" id="mef-rss-preview-btn">Preview Feed</button>
                            <div id="mef-rss-preview-panel" class="mef-rss-preview-panel" style="display:none;"></div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="post_type">Target Post Type</label></th>
                        <td>
                            <select id="mef-rss-post-type" name="post_type" required>
                                <option value="">— Select Post Type —</option>
                                <?php foreach ( $post_types as $pt ) : ?>
                                    <?php if ( $pt->name === 'attachment' ) continue; ?>
                                    <option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $feed['post_type'] ?? '', $pt->name ); ?>>
                                        <?php echo esc_html( $pt->label ); ?> (<?php echo esc_html( $pt->name ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="post_status">Post Status</label></th>
                        <td>
                            <select id="post_status" name="post_status">
                                <option value="draft" <?php selected( $feed['post_status'] ?? 'draft', 'draft' ); ?>>Draft</option>
                                <option value="publish" <?php selected( $feed['post_status'] ?? '', 'publish' ); ?>>Published</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="post_author">Post Author</label></th>
                        <td>
                            <?php
                            wp_dropdown_users( [
                                'name'     => 'post_author',
                                'id'       => 'post_author',
                                'role__in' => [ 'administrator', 'editor', 'author' ],
                                'selected' => $feed['post_author'] ?? get_current_user_id(),
                            ] );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="cron_interval">Import Interval</label></th>
                        <td>
                            <select id="cron_interval" name="cron_interval">
                                <?php foreach ( $intervals as $val => $label ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $feed['cron_interval'] ?? 'daily', $val ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="max_items">Max Items Per Run</label></th>
                        <td><input type="number" id="max_items" name="max_items" min="1" max="100" value="<?php echo esc_attr( $feed['max_items'] ?? 20 ); ?>"></td>
                    </tr>
                    <tr>
                        <th>Enabled</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked( $feed['enabled'] ?? true ); ?>>
                                Activate this feed
                            </label>
                        </td>
                    </tr>
                </table>

                <h2>Field Mapping</h2>
                <p class="description">Map RSS feed elements to your post type's fields. Select a post type above to load available target fields.</p>

                <div id="mef-rss-field-mapping">
                    <table class="mef-rss-mapping-table widefat">
                        <thead>
                            <tr>
                                <th>RSS Source</th>
                                <th>Target Type</th>
                                <th>Target Field</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="mef-rss-mapping-rows">
                            <?php
                            $mappings = $feed['field_mapping'] ?? [];
                            if ( ! empty( $mappings ) ) :
                                foreach ( $mappings as $i => $mapping ) :
                                    ?>
                                    <tr class="mef-rss-mapping-row">
                                        <td>
                                            <select name="mapping[<?php echo $i; ?>][source]" class="mef-rss-source-select">
                                                <option value="">— Select —</option>
                                                <?php foreach ( $source_fields as $key => $label ) : ?>
                                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $mapping['source'], $key ); ?>>
                                                        <?php echo esc_html( $label ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="mapping[<?php echo $i; ?>][target_type]" class="mef-rss-target-type-select">
                                                <option value="">— Select —</option>
                                                <option value="wp_field" <?php selected( $mapping['target_type'], 'wp_field' ); ?>>WP Field</option>
                                                <option value="acf_field" <?php selected( $mapping['target_type'], 'acf_field' ); ?>>ACF Field</option>
                                                <option value="taxonomy" <?php selected( $mapping['target_type'], 'taxonomy' ); ?>>Taxonomy</option>
                                                <option value="meta_field" <?php selected( $mapping['target_type'], 'meta_field' ); ?>>Custom Meta</option>
                                            </select>
                                        </td>
                                        <td>
                                            <?php if ( $mapping['target_type'] === 'meta_field' ) : ?>
                                                <input type="text" name="mapping[<?php echo $i; ?>][target_key]" class="mef-rss-target-key-input regular-text" value="<?php echo esc_attr( $mapping['target_key'] ); ?>" placeholder="meta_key">
                                                <select name="mapping[<?php echo $i; ?>][target_key_select]" class="mef-rss-target-key-select" style="display:none;"></select>
                                            <?php else : ?>
                                                <select name="mapping[<?php echo $i; ?>][target_key]" class="mef-rss-target-key-select">
                                                    <?php
                                                    if ( $current_target_fields ) {
                                                        $this->render_target_key_options( $mapping['target_type'], $current_target_fields, $mapping['target_key'] );
                                                    }
                                                    ?>
                                                </select>
                                                <input type="hidden" name="mapping[<?php echo $i; ?>][target_key_input]" class="mef-rss-target-key-input regular-text" style="display:none;">
                                            <?php endif; ?>
                                        </td>
                                        <td><button type="button" class="button button-link-delete mef-rss-remove-row">&times;</button></td>
                                    </tr>
                                    <?php
                                endforeach;
                            endif;
                            ?>
                        </tbody>
                    </table>
                    <p>
                        <button type="button" class="button" id="mef-rss-add-mapping">+ Add Mapping Row</button>
                    </p>
                </div>

                <?php submit_button( $is_edit ? 'Update Feed' : 'Create Feed' ); ?>
            </form>
        </div>

        <!-- Hidden template for new mapping rows (used by JS) -->
        <script type="text/html" id="tmpl-mef-rss-mapping-row">
            <tr class="mef-rss-mapping-row">
                <td>
                    <select name="mapping[{{data.index}}][source]" class="mef-rss-source-select">
                        <option value="">— Select —</option>
                        <?php foreach ( $source_fields as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="mapping[{{data.index}}][target_type]" class="mef-rss-target-type-select">
                        <option value="">— Select —</option>
                        <option value="wp_field">WP Field</option>
                        <option value="acf_field">ACF Field</option>
                        <option value="taxonomy">Taxonomy</option>
                        <option value="meta_field">Custom Meta</option>
                    </select>
                </td>
                <td>
                    <select name="mapping[{{data.index}}][target_key]" class="mef-rss-target-key-select"></select>
                    <input type="text" name="mapping[{{data.index}}][target_key]" class="mef-rss-target-key-input regular-text" style="display:none;" placeholder="meta_key">
                </td>
                <td><button type="button" class="button button-link-delete mef-rss-remove-row">&times;</button></td>
            </tr>
        </script>
        <?php
    }

    /**
     * Render <option> elements for the target key select based on target type.
     */
    private function render_target_key_options( string $target_type, array $target_fields, string $selected = '' ): void {
        $options = [];

        switch ( $target_type ) {
            case 'wp_field':
                $options = $target_fields['wp_fields'];
                break;
            case 'acf_field':
                $options = $target_fields['acf_fields'];
                break;
            case 'taxonomy':
                $options = $target_fields['taxonomies'];
                break;
        }

        echo '<option value="">— Select —</option>';
        foreach ( $options as $opt ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $opt['key'] ),
                selected( $selected, $opt['key'], false ),
                esc_html( $opt['label'] )
            );
        }
    }

    public function render_import_log(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        // Handle clear log action.
        if ( isset( $_POST['mef_rss_clear_log_nonce'] ) && wp_verify_nonce( $_POST['mef_rss_clear_log_nonce'], 'mef_rss_clear_log' ) ) {
            $this->logger->clear_logs();
            echo '<div class="notice notice-success is-dismissible"><p>Import log cleared.</p></div>';
        }

        $filter_feed = sanitize_text_field( $_GET['feed_filter'] ?? '' );
        $logs        = $this->logger->get_logs( 100, $filter_feed );
        $feeds       = $this->feed_manager->get_all_feeds();
        ?>
        <div class="wrap">
            <h1>Import Log</h1>
            <hr class="wp-header-end">

            <div class="tablenav top">
                <form method="get" class="alignleft">
                    <input type="hidden" name="page" value="mef-rss-importer-log">
                    <select name="feed_filter">
                        <option value="">All Feeds</option>
                        <?php foreach ( $feeds as $f ) : ?>
                            <option value="<?php echo esc_attr( $f['id'] ); ?>" <?php selected( $filter_feed, $f['id'] ); ?>>
                                <?php echo esc_html( $f['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">Filter</button>
                </form>

                <form method="post" class="alignright" onsubmit="return confirm('Clear all import logs?');">
                    <?php wp_nonce_field( 'mef_rss_clear_log', 'mef_rss_clear_log_nonce' ); ?>
                    <button type="submit" class="button">Clear Log</button>
                </form>
                <br class="clear">
            </div>

            <?php if ( empty( $logs ) ) : ?>
                <p>No import runs logged yet.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Feed</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Updated</th>
                            <th>Skipped</th>
                            <th>Errors</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $logs as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $entry['timestamp'] ); ?></td>
                                <td><?php echo esc_html( $entry['feed_name'] ); ?></td>
                                <td>
                                    <span class="mef-rss-status-badge mef-rss-status-<?php echo esc_attr( $entry['status'] ); ?>">
                                        <?php echo esc_html( ucfirst( $entry['status'] ) ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $entry['created'] ); ?></td>
                                <td><?php echo esc_html( $entry['updated'] ); ?></td>
                                <td><?php echo esc_html( $entry['skipped'] ); ?></td>
                                <td>
                                    <?php echo esc_html( $entry['errors'] ); ?>
                                    <?php if ( ! empty( $entry['error_messages'] ) ) : ?>
                                        <button type="button" class="button-link mef-rss-toggle-errors">Show</button>
                                        <div class="mef-rss-error-detail" style="display:none;">
                                            <ul>
                                                <?php foreach ( $entry['error_messages'] as $msg ) : ?>
                                                    <li><?php echo esc_html( $msg ); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $entry['duration'] ); ?>s</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Form Submission
    // -------------------------------------------------------------------------

    public function handle_form_submission(): void {
        if ( ! isset( $_POST['mef_rss_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['mef_rss_nonce'], 'mef_rss_save_feed' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $is_new = empty( $_POST['feed_id'] );

        $feed_data = [
            'id'            => sanitize_text_field( $_POST['feed_id'] ?? '' ),
            'name'          => sanitize_text_field( $_POST['feed_name'] ?? '' ),
            'url'           => esc_url_raw( $_POST['feed_url'] ?? '' ),
            'post_type'     => sanitize_key( $_POST['post_type'] ?? 'post' ),
            'post_status'   => in_array( $_POST['post_status'] ?? '', [ 'draft', 'publish' ], true ) ? $_POST['post_status'] : 'draft',
            'post_author'   => absint( $_POST['post_author'] ?? get_current_user_id() ),
            'cron_interval' => in_array( $_POST['cron_interval'] ?? '', [ 'hourly', 'twicedaily', 'daily', 'weekly' ], true ) ? $_POST['cron_interval'] : 'daily',
            'max_items'     => absint( $_POST['max_items'] ?? 20 ) ?: 20,
            'enabled'       => ! empty( $_POST['enabled'] ),
            'field_mapping' => [],
        ];

        // Process field mappings.
        if ( ! empty( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ) {
            foreach ( $_POST['mapping'] as $row ) {
                $source      = sanitize_text_field( $row['source'] ?? '' );
                $target_type = sanitize_key( $row['target_type'] ?? '' );

                // For meta_field, the key comes from the text input; otherwise from the select.
                $target_key = '';
                if ( $target_type === 'meta_field' ) {
                    $target_key = sanitize_text_field( $row['target_key'] ?? '' );
                } else {
                    // Could come from either target_key or target_key_select.
                    $target_key = sanitize_text_field( $row['target_key'] ?? $row['target_key_select'] ?? '' );
                }

                if ( empty( $source ) || empty( $target_type ) || empty( $target_key ) ) {
                    continue;
                }

                $feed_data['field_mapping'][] = [
                    'source'      => $source,
                    'target_type' => $target_type,
                    'target_key'  => $target_key,
                ];
            }
        }

        $feed_id = $this->feed_manager->save_feed( $feed_data );

        // Reschedule cron.
        $this->cron_manager->reschedule_feed( $feed_id );

        // Run immediately on first create if enabled.
        if ( $is_new && $feed_data['enabled'] ) {
            $this->processor->process_feed( $feed_id, true );
        }

        wp_redirect( admin_url( 'admin.php?page=mef-rss-importer&saved=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // AJAX Handlers
    // -------------------------------------------------------------------------

    public function ajax_get_post_type_fields(): void {
        check_ajax_referer( 'mef_rss_importer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $post_type = sanitize_key( $_POST['post_type'] ?? '' );
        if ( ! $post_type || ! post_type_exists( $post_type ) ) {
            wp_send_json_error( 'Invalid post type.' );
        }

        $fields = $this->field_mapper->get_target_fields_for_post_type( $post_type );
        wp_send_json_success( $fields );
    }

    public function ajax_preview_feed(): void {
        check_ajax_referer( 'mef_rss_importer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $feed_url = esc_url_raw( $_POST['feed_url'] ?? '' );
        if ( ! $feed_url ) {
            wp_send_json_error( 'No URL provided.' );
        }

        // Bypass cache for preview.
        $cache_filter = function () { return 0; };
        add_filter( 'wp_feed_cache_transient_lifetime', $cache_filter );

        $feed = fetch_feed( $feed_url );

        remove_filter( 'wp_feed_cache_transient_lifetime', $cache_filter );

        if ( is_wp_error( $feed ) ) {
            wp_send_json_error( $feed->get_error_message() );
        }

        $items   = $feed->get_items( 0, 5 );
        $samples = [];

        foreach ( $items as $item ) {
            $samples[] = [
                'title'       => $item->get_title(),
                'link'        => $item->get_link(),
                'pubDate'     => $item->get_date( 'Y-m-d H:i:s' ),
                'description' => wp_trim_words( wp_strip_all_tags( $item->get_description() ), 30 ),
                'has_content' => (bool) $item->get_content(),
                'categories'  => array_map( function ( $c ) { return $c->get_label(); }, $item->get_categories() ?: [] ),
            ];
        }

        wp_send_json_success( [
            'feed_title'   => $feed->get_title(),
            'item_count'   => $feed->get_item_quantity(),
            'sample_items' => $samples,
        ] );
    }

    public function ajax_run_feed_now(): void {
        check_ajax_referer( 'mef_rss_importer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $feed_id = sanitize_text_field( $_POST['feed_id'] ?? '' );
        if ( ! $feed_id ) {
            wp_send_json_error( 'No feed ID.' );
        }

        $result = $this->processor->process_feed( $feed_id, true );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    public function ajax_delete_feed(): void {
        check_ajax_referer( 'mef_rss_importer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $feed_id = sanitize_text_field( $_POST['feed_id'] ?? '' );
        if ( ! $feed_id ) {
            wp_send_json_error( 'No feed ID.' );
        }

        $this->cron_manager->unschedule_feed( $feed_id );
        $this->feed_manager->delete_feed( $feed_id );
        wp_send_json_success( 'Deleted.' );
    }

    public function ajax_toggle_feed(): void {
        check_ajax_referer( 'mef_rss_importer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $feed_id = sanitize_text_field( $_POST['feed_id'] ?? '' );
        if ( ! $feed_id ) {
            wp_send_json_error( 'No feed ID.' );
        }

        $feed = $this->feed_manager->get_feed( $feed_id );
        if ( ! $feed ) {
            wp_send_json_error( 'Feed not found.' );
        }

        // Toggle enabled state.
        $feed['enabled'] = ! $feed['enabled'];
        $this->feed_manager->save_feed( $feed );
        $this->cron_manager->reschedule_feed( $feed_id );

        wp_send_json_success( [
            'enabled' => $feed['enabled'],
            'label'   => $feed['enabled'] ? 'Enabled' : 'Disabled',
        ] );
    }
}
