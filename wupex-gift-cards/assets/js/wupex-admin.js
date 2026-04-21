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
    // Settings page: Send Test Email
    // -------------------------------------------------------------------------
    $(document).on('click', '#wupex-send-test-email', function () {
        var btn    = $(this);
        var result = $('#wupex-test-email-result');
        var email  = $('#wupex-test-email-address').val().trim();

        if (!email) {
            result.css('color', '#991b1b').text('Please enter an email address.');
            return;
        }

        btn.prop('disabled', true).text('Sending…');
        result.text('').css('color', '');

        $.post(wupexAdmin.ajax_url, {
            action: 'wupex_send_test_email',
            nonce:  wupexAdmin.nonce,
            email:  email
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
            btn.prop('disabled', false).text('Send Test Email');
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
    // Import page: Load / Refresh Types
    // -------------------------------------------------------------------------
    $(document).on('click', '#wupex-load-types', function () {
        var btn     = $(this);
        var loading = $('#wupex-types-loading');

        btn.prop('disabled', true);
        loading.show();

        $.post(wupexAdmin.ajax_url, {
            action: 'wupex_fetch_types',
            nonce:  wupexAdmin.nonce
        }, null, 'json')
        .done(function (resp) {
            if (resp.success) {
                window.location.reload();
            } else {
                loading.hide();
                alert('Error: ' + (resp.data ? resp.data.message : 'Unknown error'));
                btn.prop('disabled', false);
            }
        })
        .fail(function () {
            loading.hide();
            alert('Request failed. Try again.');
            btn.prop('disabled', false);
        });
    });

    // -------------------------------------------------------------------------
    // Import page: Toggle All Types
    // -------------------------------------------------------------------------
    $(document).on('change', '#wupex-toggle-all-types', function () {
        $('.wupex-type-check').prop('checked', $(this).is(':checked'));
    });

    $(document).on('change', '.wupex-type-check', function () {
        var total   = $('.wupex-type-check').length;
        var checked = $('.wupex-type-check:checked').length;
        $('#wupex-toggle-all-types').prop('indeterminate', checked > 0 && checked < total)
                                    .prop('checked', checked === total);
    });

    // -------------------------------------------------------------------------
    // Import page: Save Type Filter
    // -------------------------------------------------------------------------
    $(document).on('click', '#wupex-save-types', function () {
        var btn    = $(this);
        var result = $('#wupex-save-types-result');
        var types  = [];

        $('.wupex-type-check:checked').each(function () {
            types.push($(this).val());
        });

        btn.prop('disabled', true).text('Saving…');
        result.text('').css('color', '');

        $.post(wupexAdmin.ajax_url, {
            action: 'wupex_save_type_filter',
            nonce:  wupexAdmin.nonce,
            types:  types
        })
        .done(function (resp) {
            if (resp.success) {
                result.css('color', '#065f46').text('✔ ' + resp.data.message + ' Reloading…');
                setTimeout(function () { window.location.reload(); }, 800);
            } else {
                result.css('color', '#991b1b').text('✖ ' + (resp.data ? resp.data.message : 'Error'));
                btn.prop('disabled', false).text('Save Filter & Reload');
            }
        })
        .fail(function () {
            result.css('color', '#991b1b').text('✖ Request failed.');
            btn.prop('disabled', false).text('Save Filter & Reload');
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
                // Reload after 2s so badge statuses update correctly
                setTimeout(function () { window.location.reload(); }, 2000);
            } else {
                text.text('Error.');
                summary.html(
                    '<div class="notice notice-error inline"><p>' + resp.data.message + '</p></div>'
                ).show();
                btn.prop('disabled', false);
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
    // Import page: Change Categories (clears saved filter → back to Step 2)
    // -------------------------------------------------------------------------
    $(document).on('click', '#wupex-change-filter', function () {
        var btn = $(this);
        btn.prop('disabled', true).text('Clearing…');

        $.post(wupexAdmin.ajax_url, {
            action: 'wupex_save_type_filter',
            nonce:  wupexAdmin.nonce,
            types:  []
        })
        .done(function () {
            window.location.reload();
        })
        .fail(function () {
            btn.prop('disabled', false).text('Change Categories');
            alert('Request failed. Try again.');
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
