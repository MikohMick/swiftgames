/* global wupexAdmin, jQuery */
(function ($) {
    'use strict';

    // -------------------------------------------------------------------------
    // Settings page: Test Connection
    // -------------------------------------------------------------------------
    $(document).on('click', '#wupex-test-connection', function () {
        var btn    = $(this);
        var result = $('#wupex-connection-result');

        btn.prop('disabled', true).text(wupexAdmin.i18n ? wupexAdmin.i18n.testing : 'Testing…');
        result.text('').css('color', '');

        $.post(wupexAdmin.ajax_url, {
            action: 'wupex_test_connection',
            nonce:  wupexAdmin.nonce
        })
        .done(function (resp) {
            if (resp.success) {
                result.css('color', '#065f46').text('✔ ' + resp.data.message);
            } else {
                result.css('color', '#991b1b').text('✖ ' + resp.data.message);
            }
        })
        .fail(function () {
            result.css('color', '#991b1b').text('✖ Request failed.');
        })
        .always(function () {
            btn.prop('disabled', false).text('Test Connection');
        });
    });

    // -------------------------------------------------------------------------
    // Settings page: View Logs
    // -------------------------------------------------------------------------
    $(document).on('click', '#wupex-view-logs', function () {
        var btn    = $(this);
        var output = $('#wupex-log-output');
        var pre    = $('#wupex-log-content');

        btn.prop('disabled', true).text('Loading…');

        $.post(wupexAdmin.ajax_url, {
            action: 'wupex_get_logs',
            nonce:  wupexAdmin.nonce
        })
        .done(function (resp) {
            if (resp.success) {
                pre.text(resp.data.lines);
                output.show();
            }
        })
        .always(function () {
            btn.prop('disabled', false).text('View Logs');
        });
    });

    // -------------------------------------------------------------------------
    // Import page: Refresh Product List
    // -------------------------------------------------------------------------
    $(document).on('click', '#wupex-refresh-products', function () {
        var btn = $(this);
        btn.prop('disabled', true).text('Refreshing…');
        $.post(wupexAdmin.ajax_url, {
            action: 'wupex_refresh_products',
            nonce:  wupexAdmin.nonce
        })
        .done(function (resp) {
            if (resp.success) {
                window.location.reload();
            }
        })
        .always(function () {
            btn.prop('disabled', false).text('Refresh Product List');
        });
    });

    // -------------------------------------------------------------------------
    // Import page: Select All checkbox
    // -------------------------------------------------------------------------
    $(document).on('change', '#wupex-select-all', function () {
        $('input[name="products[]"]').prop('checked', $(this).is(':checked'));
    });

    // -------------------------------------------------------------------------
    // Import page: Import Selected
    // -------------------------------------------------------------------------
    $(document).on('click', '#wupex-import-selected', function () {
        var selected = $('input[name="products[]"]:checked');

        if (selected.length === 0) {
            alert('Please select at least one product.');
            return;
        }

        var btn      = $(this);
        var progress = $('#wupex-import-progress');
        var fill     = $('#wupex-progress-fill');
        var text     = $('#wupex-progress-text');
        var summary  = $('#wupex-import-summary');

        btn.prop('disabled', true);
        progress.show();
        summary.hide().html('');

        var products = [];
        selected.each(function () { products.push($(this).val()); });

        // Simulate chunked progress (one batch in this implementation)
        fill.css('width', '30%');
        text.text('Importing ' + products.length + ' product(s)…');

        $.post(wupexAdmin.ajax_url, {
            action:   'wupex_import_products',
            nonce:    wupexAdmin.nonce,
            products: products
        })
        .done(function (resp) {
            fill.css('width', '100%');
            if (resp.success) {
                var d = resp.data;
                text.text('Done!');
                summary.html(
                    '<div class="notice notice-success inline"><p>' +
                    '&#10003; <strong>' + d.imported + '</strong> imported, ' +
                    '<strong>' + d.skipped + '</strong> skipped (already imported), ' +
                    '<strong>' + d.failed  + '</strong> failed.' +
                    '</p></div>'
                ).show();
            } else {
                text.text('Error.');
                summary.html(
                    '<div class="notice notice-error inline"><p>' + resp.data.message + '</p></div>'
                ).show();
            }
        })
        .fail(function () {
            fill.css('width', '100%');
            text.text('Request failed.');
        })
        .always(function () {
            btn.prop('disabled', false);
        });
    });

    // -------------------------------------------------------------------------
    // Import page: Sync Stock
    // -------------------------------------------------------------------------
    $(document).on('click', '#wupex-sync-stock', function () {
        var btn    = $(this);
        var result = $('#wupex-sync-result');

        btn.prop('disabled', true).text('Syncing…');
        result.text('');

        $.post(wupexAdmin.ajax_url, {
            action: 'wupex_sync_stock',
            nonce:  wupexAdmin.nonce
        })
        .done(function (resp) {
            if (resp.success) {
                result.css('color', '#065f46').text('✔ ' + resp.data.message);
            } else {
                result.css('color', '#991b1b').text('✖ ' + resp.data.message);
            }
        })
        .fail(function () {
            result.css('color', '#991b1b').text('✖ Sync request failed.');
        })
        .always(function () {
            btn.prop('disabled', false).text('Sync Stock');
        });
    });

})(jQuery);
