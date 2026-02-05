(function ($) {
    'use strict';

    var config = window.mefRssImporter || {};
    var currentTargetFields = null;
    var rowIndex = 100; // Start high to avoid collisions with server-rendered rows.

    // -------------------------------------------------------------------------
    // Post Type Change → Load Target Fields
    // -------------------------------------------------------------------------

    $(document).on('change', '#mef-rss-post-type', function () {
        var postType = $(this).val();
        if (!postType) {
            currentTargetFields = null;
            return;
        }

        $.post(config.ajaxUrl, {
            action: 'mef_rss_get_post_type_fields',
            nonce: config.nonce,
            post_type: postType
        }, function (response) {
            if (response.success) {
                currentTargetFields = response.data;
                rebuildAllTargetSelects();
            }
        });
    });

    // -------------------------------------------------------------------------
    // Target Type Change → Populate Target Key Options
    // -------------------------------------------------------------------------

    $(document).on('change', '.mef-rss-target-type-select', function () {
        var $row = $(this).closest('.mef-rss-mapping-row');
        var targetType = $(this).val();
        var $select = $row.find('.mef-rss-target-key-select');
        var $input = $row.find('.mef-rss-target-key-input');

        if (targetType === 'meta_field') {
            $select.hide().attr('name', '');
            $input.show().attr('name', $select.attr('name') || $input.attr('name'));
            // Ensure the input has the correct name attribute.
            var baseName = getRowBaseName($row);
            $input.attr('name', baseName + '[target_key]');
            $select.attr('name', '');
        } else {
            $input.hide().attr('name', '');
            $select.show();
            var baseName = getRowBaseName($row);
            $select.attr('name', baseName + '[target_key]');
            populateTargetSelect($select, targetType);
        }
    });

    function getRowBaseName($row) {
        // Extract the base name from any named element in the row.
        var name = $row.find('.mef-rss-source-select').attr('name') || '';
        var match = name.match(/^(mapping\[\d+\])/);
        return match ? match[1] : 'mapping[0]';
    }

    function populateTargetSelect($select, targetType) {
        $select.empty().append('<option value="">— Select —</option>');

        if (!currentTargetFields) return;

        var options = [];
        switch (targetType) {
            case 'wp_field':
                options = currentTargetFields.wp_fields || [];
                break;
            case 'acf_field':
                options = currentTargetFields.acf_fields || [];
                break;
            case 'taxonomy':
                options = currentTargetFields.taxonomies || [];
                break;
        }

        $.each(options, function (i, opt) {
            $select.append(
                $('<option></option>').val(opt.key).text(opt.label)
            );
        });
    }

    function rebuildAllTargetSelects() {
        $('#mef-rss-mapping-rows .mef-rss-mapping-row').each(function () {
            var $row = $(this);
            var targetType = $row.find('.mef-rss-target-type-select').val();
            if (targetType && targetType !== 'meta_field') {
                var $select = $row.find('.mef-rss-target-key-select');
                var currentVal = $select.val();
                populateTargetSelect($select, targetType);
                // Try to re-select the previous value.
                if (currentVal) {
                    $select.val(currentVal);
                }
            }
        });
    }

    // -------------------------------------------------------------------------
    // Add / Remove Mapping Rows
    // -------------------------------------------------------------------------

    $(document).on('click', '#mef-rss-add-mapping', function () {
        var template = $('#tmpl-mef-rss-mapping-row').html();
        if (!template) return;

        var html = template.replace(/\{\{data\.index\}\}/g, rowIndex);
        rowIndex++;

        var $newRow = $(html);
        $('#mef-rss-mapping-rows').append($newRow);
    });

    $(document).on('click', '.mef-rss-remove-row', function () {
        $(this).closest('.mef-rss-mapping-row').remove();
    });

    // -------------------------------------------------------------------------
    // Preview Feed
    // -------------------------------------------------------------------------

    $(document).on('click', '#mef-rss-preview-btn', function () {
        var $btn = $(this);
        var url = $('#feed_url').val();
        var $panel = $('#mef-rss-preview-panel');

        if (!url) {
            alert('Please enter a feed URL first.');
            return;
        }

        $btn.prop('disabled', true).text('Loading...');
        $panel.hide();

        $.post(config.ajaxUrl, {
            action: 'mef_rss_preview_feed',
            nonce: config.nonce,
            feed_url: url
        }, function (response) {
            $btn.prop('disabled', false).text('Preview Feed');

            if (!response.success) {
                $panel.html('<p class="mef-rss-error">Error: ' + (response.data || 'Unknown error') + '</p>').show();
                return;
            }

            var data = response.data;
            var html = '<h4>' + escapeHtml(data.feed_title) + ' (' + data.item_count + ' items)</h4>';
            html += '<table class="widefat fixed striped"><thead><tr><th>Title</th><th>Date</th><th>Categories</th><th>Description</th></tr></thead><tbody>';

            $.each(data.sample_items, function (i, item) {
                html += '<tr>';
                html += '<td><a href="' + escapeHtml(item.link) + '" target="_blank">' + escapeHtml(item.title) + '</a></td>';
                html += '<td>' + escapeHtml(item.pubDate) + '</td>';
                html += '<td>' + escapeHtml((item.categories || []).join(', ')) + '</td>';
                html += '<td>' + escapeHtml(item.description) + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            $panel.html(html).show();
        }).fail(function () {
            $btn.prop('disabled', false).text('Preview Feed');
            $panel.html('<p class="mef-rss-error">Request failed.</p>').show();
        });
    });

    // -------------------------------------------------------------------------
    // Run Now
    // -------------------------------------------------------------------------

    $(document).on('click', '.mef-rss-run-now', function () {
        var $btn = $(this);
        var feedId = $btn.data('feed-id');
        var $row = $btn.closest('tr');
        var $spinner = $row.find('.mef-rss-spinner');
        var $result = $row.find('.mef-rss-run-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.text('');

        $.post(config.ajaxUrl, {
            action: 'mef_rss_run_feed_now',
            nonce: config.nonce,
            feed_id: feedId
        }, function (response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if (response.success) {
                var d = response.data;
                $result.html('<span class="mef-rss-success-text">' + d.message + '</span>');
            } else {
                $result.html('<span class="mef-rss-error-text">Error: ' + (response.data || 'Unknown') + '</span>');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $result.html('<span class="mef-rss-error-text">Request failed.</span>');
        });
    });

    // -------------------------------------------------------------------------
    // Delete Feed
    // -------------------------------------------------------------------------

    $(document).on('click', '.mef-rss-delete-feed', function () {
        if (!confirm('Delete this feed? Imported posts will NOT be removed.')) return;

        var $btn = $(this);
        var feedId = $btn.data('feed-id');
        var $row = $btn.closest('tr');

        $btn.prop('disabled', true);

        $.post(config.ajaxUrl, {
            action: 'mef_rss_delete_feed',
            nonce: config.nonce,
            feed_id: feedId
        }, function (response) {
            if (response.success) {
                $row.fadeOut(300, function () { $(this).remove(); });
            } else {
                alert('Error deleting feed.');
                $btn.prop('disabled', false);
            }
        });
    });

    // -------------------------------------------------------------------------
    // Toggle Feed Enable/Disable
    // -------------------------------------------------------------------------

    $(document).on('click', '.mef-rss-toggle-feed', function () {
        var $btn = $(this);
        var feedId = $btn.data('feed-id');
        var $row = $btn.closest('tr');

        $btn.prop('disabled', true);

        $.post(config.ajaxUrl, {
            action: 'mef_rss_toggle_feed',
            nonce: config.nonce,
            feed_id: feedId
        }, function (response) {
            $btn.prop('disabled', false);

            if (response.success) {
                var data = response.data;
                $btn.text(data.enabled ? 'Disable' : 'Enable');

                var $badge = $row.find('.mef-rss-status-badge').first();
                $badge.removeClass('mef-rss-status-enabled mef-rss-status-disabled')
                    .addClass('mef-rss-status-' + (data.enabled ? 'enabled' : 'disabled'))
                    .text(data.label);
            }
        });
    });

    // -------------------------------------------------------------------------
    // Toggle Error Details in Log
    // -------------------------------------------------------------------------

    $(document).on('click', '.mef-rss-toggle-errors', function () {
        var $detail = $(this).siblings('.mef-rss-error-detail');
        $detail.toggle();
        $(this).text($detail.is(':visible') ? 'Hide' : 'Show');
    });

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
