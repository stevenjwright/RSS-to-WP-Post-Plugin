<?php
/**
 * MEF RSS Cron Manager — Schedules per-feed cron events.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MEF_RSS_Cron_Manager {

    private MEF_RSS_Feed_Manager $feed_manager;
    private MEF_RSS_Feed_Processor $processor;

    public function __construct(
        MEF_RSS_Feed_Manager $feed_manager,
        MEF_RSS_Feed_Processor $processor
    ) {
        $this->feed_manager = $feed_manager;
        $this->processor    = $processor;
    }

    /**
     * Initialize cron hooks and schedules.
     */
    public function init(): void {
        // Register the weekly interval.
        add_filter( 'cron_schedules', [ $this, 'add_weekly_interval' ] );

        // Register cron callbacks for all feeds.
        $this->register_hooks();

        // Ensure schedules are in sync on every admin load.
        add_action( 'admin_init', [ $this, 'sync_all_schedules' ] );
    }

    /**
     * Add a weekly cron interval.
     */
    public function add_weekly_interval( array $schedules ): array {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly' ),
            ];
        }
        return $schedules;
    }

    /**
     * Register cron action callbacks for all configured feeds.
     */
    public function register_hooks(): void {
        $feeds = $this->feed_manager->get_all_feeds();
        foreach ( $feeds as $feed ) {
            $hook = 'mef_rss_import_feed_' . $feed['id'];
            add_action( $hook, function () use ( $feed ) {
                $this->processor->process_feed( $feed['id'] );
            } );
        }
    }

    /**
     * Schedule the cron event for a feed.
     */
    public function schedule_feed( string $feed_id ): void {
        $feed = $this->feed_manager->get_feed( $feed_id );
        if ( ! $feed || ! $feed['enabled'] ) {
            $this->unschedule_feed( $feed_id );
            return;
        }

        $hook = 'mef_rss_import_feed_' . $feed_id;

        // Clear existing schedule first.
        $this->unschedule_feed( $feed_id );

        wp_schedule_event( time(), $feed['cron_interval'], $hook );

        // Register the action if not already registered.
        if ( ! has_action( $hook ) ) {
            add_action( $hook, function () use ( $feed_id ) {
                $this->processor->process_feed( $feed_id );
            } );
        }
    }

    /**
     * Unschedule the cron event for a feed.
     */
    public function unschedule_feed( string $feed_id ): void {
        $hook      = 'mef_rss_import_feed_' . $feed_id;
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
    }

    /**
     * Reschedule a feed (unschedule + schedule).
     */
    public function reschedule_feed( string $feed_id ): void {
        $this->unschedule_feed( $feed_id );
        $feed = $this->feed_manager->get_feed( $feed_id );
        if ( $feed && $feed['enabled'] ) {
            $this->schedule_feed( $feed_id );
        }
    }

    /**
     * Ensure all enabled feeds have active cron events and disabled ones don't.
     */
    public function sync_all_schedules(): void {
        $feeds = $this->feed_manager->get_all_feeds();
        foreach ( $feeds as $feed ) {
            $hook      = 'mef_rss_import_feed_' . $feed['id'];
            $scheduled = wp_next_scheduled( $hook );

            if ( $feed['enabled'] && ! $scheduled ) {
                $this->schedule_feed( $feed['id'] );
            } elseif ( ! $feed['enabled'] && $scheduled ) {
                $this->unschedule_feed( $feed['id'] );
            }
        }
    }
}
