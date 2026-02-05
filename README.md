# MEF RSS Feed Importer

Import and sync RSS feeds into WordPress posts or custom post types with configurable field mapping.

## What this plugin does

MEF RSS Feed Importer lets you:

- Add one or more RSS feeds in wp-admin
- Map RSS fields (title, content, media, categories, etc.) to:
  - Standard WordPress post fields
  - ACF fields (if ACF is installed)
  - Taxonomies
  - Custom meta fields
- Automatically run imports on a schedule (hourly, twice daily, daily, weekly)
- Run imports manually at any time
- Track import history (created/updated/skipped/errors)

## Key behavior

- Deduplication is based on RSS item GUID, with link as fallback.
- Existing imported posts are updated; new items are inserted.
- New feeds run once immediately after save when enabled.
- Manual "Run Now" bypasses feed cache for fresher results.
- Feed deletion does not remove already imported posts.

## Requirements

- WordPress (plugin is built for wp-admin + WP-Cron)
- Permission to manage options (`manage_options`)
- Optional: Advanced Custom Fields (ACF) for ACF field mapping

## Installation (normal plugin)

1. Copy this project into your WordPress plugins directory, for example:
   - `wp-content/plugins/rss-to-wp-post/`
2. Ensure the main plugin file is present:
   - `wp-content/plugins/rss-to-wp-post/mef-rss-importer.php`
3. In WordPress admin, go to **Plugins** and activate **MEF RSS Feed Importer**.

## How to use

1. Go to **RSS Importer -> Add New**.
2. Fill in:
   - Feed Name
   - Feed URL
   - Target Post Type
   - Post Status (Draft/Published)
   - Post Author
   - Import Interval
   - Max Items Per Run
   - Enabled toggle
3. Use **Preview Feed** to validate the feed and inspect sample items.
4. Add one or more mapping rows:
   - **RSS Source**: `title`, `description`, `content`, `link`, `pubDate`, `author`, `categories`, `guid`, `media_*`, `enclosure_*`
   - **Target Type**:
     - `WP Field` (title, content, excerpt, post date, featured image URL)
     - `ACF Field` (if available)
     - `Taxonomy`
     - `Custom Meta`
5. Save the feed.
6. Manage feeds in **RSS Importer -> All Feeds**:
   - Edit
   - Run Now
   - Enable/Disable
   - Delete

## Scheduling and cron

- Supported intervals: `hourly`, `twicedaily`, `daily`, `weekly`
- The plugin registers per-feed cron events.
- Enabled feeds are kept scheduled; disabled feeds are unscheduled.
- WP-Cron depends on site traffic unless you use a real server cron trigger.

## Logs

Open **RSS Importer -> Import Log** to view recent runs:

- Timestamp
- Feed
- Status (`success`, `partial`, `error`)
- Created / Updated / Skipped / Errors
- Duration
- Expandable error messages

You can filter logs by feed and clear the log table from this screen.

## Data stored by plugin

In `wp_options`:

- `mef_rss_feeds` - feed configurations
- `mef_rss_import_logs` - recent import run history

On imported posts (post meta):

- `_rss_import_guid`
- `_rss_import_feed_id`
- `_rss_import_last_updated`

On sideloaded featured images:

- `_rss_import_source_url`

## Notes and limitations

- This plugin does not remove imported posts when a feed is deleted.
- ACF updates require ACF to be installed and active.
- Invalid feeds or network failures are logged as import errors.
- Media sideload failures are skipped so the rest of the import can continue.

## Author

Steven J. Wright  
hey@stevenjwright.me
