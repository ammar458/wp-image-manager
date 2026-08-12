(function($) {
    'use strict';

    var state = {
        currentPage: 1,
        totalPages: 1,
        selected: {},
        scanning: false,
        deletedPage: 1,
        deletedTotalPages: 1,
        convertedPage: 1,
        convertedTotalPages: 1,
        convertOffset: 0,
        scanDone: false,
    };

    // ─── Toast ────────────────────────────────────────────────────────
    function toast(msg, type) {
        var $t = $('#wpim-toast');
        $t.html(msg).removeClass('success error info').addClass(type || 'info').addClass('show');
        setTimeout(function() { $t.removeClass('show'); }, 4000);
    }

    // ─── AJAX helper ──────────────────────────────────────────────────
    function ajax(action, data, cb, timeout) {
        $.ajax({
            url: WPIM.ajaxurl,
            type: 'POST',
            data: $.extend({ action: action, nonce: WPIM.nonce }, data),
            timeout: (timeout || 120) * 1000,
            success: function(res) {
                if (res.success) cb(null, res.data);
                else cb(res.data || 'An error occurred.');
            },
            error: function(xhr, status) {
                if (status === 'timeout') cb('Request timed out. Your library is very large — try again, the database cache will speed it up.');
                else cb('Request failed: ' + status);
            }
        });
    }

    // ─── Tabs ─────────────────────────────────────────────────────────
    $(document).on('click', '.wpim-tab', function() {
        var tab = $(this).data('tab');
        $('.wpim-tab').removeClass('active');
        $(this).addClass('active');
        $('.wpim-tab-content').removeClass('active');
        $('#tab-' + tab).addClass('active');
    });

    // ─── Scan ─────────────────────────────────────────────────────────
    $('#btn-scan').on('click', function() {
        if (state.scanning) return;
        state.scanning = true;
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#scan-spinner').show();
        $('#stat-total').text('…');
        $('#stat-attached').text('…');
        $('#stat-unattached').text('…');
        $('#wpim-images-grid').html('<div class="wpim-placeholder"><p>⏳ Scanning your media library… This may take 10–30 seconds for large sites.</p></div>');

        ajax('wpim_scan', {}, function(err, data) {
            state.scanning = false;
            $btn.prop('disabled', false);
            $('#scan-spinner').hide();

            if (err) {
                toast('⚠️ ' + err, 'error');
                $('#wpim-images-grid').html('<div class="wpim-placeholder"><p>Scan failed. Check server error log or try again.</p></div>');
                return;
            }

            state.scanDone = true;
            $('#stat-total').text(data.total);
            $('#stat-attached').text(data.attached);
            $('#stat-unattached').text(data.unattached);
            $('#btn-deep-scan').prop('disabled', false).attr('title', '');

            if (data.unattached > 0) {
                state.currentPage = 1;
                loadPage(1);
            } else {
                $('#wpim-images-grid').html('<div class="wpim-placeholder"><p>✅ No unattached images found!</p></div>');
                $('#wpim-pagination').hide();
            }
            loadConversionStats();
        }, 180); // 3 min timeout for huge libraries
    });

    // ─── Deep Scan ────────────────────────────────────────────────────
    $('#btn-deep-scan').on('click', function() {
        if (state.scanning || !state.scanDone) return;
        state.scanning = true;
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#deep-scan-spinner').show();
        $('#wpim-images-grid').html('<div class="wpim-placeholder"><p>🔬 Running deep scan — checking AdRotate, serialized meta, widgets, and content URLs… This can take a minute on large sites.</p></div>');

        ajax('wpim_deep_scan', {}, function(err, data) {
            state.scanning = false;
            $btn.prop('disabled', false);
            $('#deep-scan-spinner').hide();

            if (err) {
                toast('⚠️ ' + err, 'error');
                $('#wpim-images-grid').html('<div class="wpim-placeholder"><p>Deep scan failed. Check server error log or try again.</p></div>');
                return;
            }

            $('#stat-total').text(data.total);
            $('#stat-attached').text(data.attached);
            $('#stat-unattached').text(data.unattached);

            var d = data.deep || {};
            var msg = 'Deep scan found ' + (d.total_new || 0) + ' additional attached images'
                + ' (AdRotate: ' + (d.adrotate || 0)
                + ', serialized/JSON meta: ' + (d.postmeta || 0)
                + ', options/widgets: ' + (d.options || 0)
                + ', content URLs: ' + (d.content || 0) + ').';
            toast(msg, 'success');

            state.currentPage = 1;
            if (data.unattached > 0) {
                loadPage(1);
            } else {
                $('#wpim-images-grid').html('<div class="wpim-placeholder"><p>✅ No unattached images found!</p></div>');
                $('#wpim-pagination').hide();
            }
        }, 240);
    });

    function loadConversionStats() {
        ajax('wpim_conversion_stats', {}, function(err, data) {
            if (err) return;
            $('#stat-webp').text(data.webp);
            $('#stat-jpeg').text(data.jpeg_png);
            $('#cstat-jpeg').text(data.jpeg_png);
            $('#cstat-webp').text(data.webp);
            $('#cstat-converted').text(data.converted_count);
            if (data.supported) {
                $('#cstat-support').text('✅ Supported').css('color', '#00a32a');
            } else {
                $('#cstat-support').text('❌ GD/Imagick missing').css('color', '#d63638');
            }
        });
    }

    // ─── Load Page ────────────────────────────────────────────────────
    function loadPage(page) {
        $('#wpim-images-grid').html('<div class="wpim-placeholder"><p>Loading page ' + page + '…</p></div>');
        ajax('wpim_get_page', { page: page }, function(err, data) {
            if (err) { toast('Error: ' + err, 'error'); return; }
            state.currentPage = data.current;
            state.totalPages  = data.pages;
            $('#stat-attached').text(data.attached);
            $('#stat-unattached').text(data.unattached);
            $('#stat-total').text(data.total_all);
            renderGrid(data.images);
            renderPagination();
            updateDeleteBtns();
        }, 60);
    }

    function renderGrid(images) {
        if (!images || !images.length) {
            $('#wpim-images-grid').html('<div class="wpim-placeholder"><p>✅ No unattached images found.</p></div>');
            $('#wpim-pagination').hide();
            return;
        }
        var html = '';
        $.each(images, function(i, img) {
            var thumb = img.thumb
                ? '<img class="wpim-card-thumb" src="' + escHtml(img.thumb) + '" alt="" loading="lazy">'
                : '<div class="wpim-card-thumb-placeholder">🖼️</div>';
            var sel = state.selected[img.id] ? ' selected' : '';
            var chk = state.selected[img.id] ? ' checked' : '';
            html += '<div class="wpim-image-card' + sel + '" data-id="' + img.id + '">'
                  + '<input type="checkbox" class="wpim-card-check" data-id="' + img.id + '"' + chk + '>'
                  + thumb
                  + '<div class="wpim-card-badge">' + escHtml(img.mime.replace('image/', '')) + '</div>'
                  + '<div class="wpim-card-info">'
                  +   '<div class="wpim-card-name" title="' + escHtml(img.filename) + '">' + escHtml(img.filename) + '</div>'
                  +   '<div class="wpim-card-meta"><span>' + img.size + '</span><span>' + img.date + '</span></div>'
                  + '</div></div>';
        });
        $('#wpim-images-grid').html(html);
        $('#check-all').prop('checked', false);
    }

    function renderPagination() {
        if (state.totalPages <= 1) { $('#wpim-pagination').hide(); return; }
        $('#wpim-pagination').show();
        $('#page-info').text('Page ' + state.currentPage + ' of ' + state.totalPages);
        $('#btn-prev').prop('disabled', state.currentPage <= 1);
        $('#btn-next').prop('disabled', state.currentPage >= state.totalPages);
    }

    // ─── Card interaction ─────────────────────────────────────────────
    $(document).on('click', '.wpim-image-card', function(e) {
        if ($(e.target).is('input')) return;
        var id = $(this).data('id');
        if (state.selected[id]) {
            delete state.selected[id];
            $(this).removeClass('selected').find('.wpim-card-check').prop('checked', false);
        } else {
            state.selected[id] = true;
            $(this).addClass('selected').find('.wpim-card-check').prop('checked', true);
        }
        updateDeleteBtns();
    });

    $(document).on('change', '.wpim-card-check', function() {
        var id = $(this).data('id');
        if ($(this).is(':checked')) {
            state.selected[id] = true;
            $(this).closest('.wpim-image-card').addClass('selected');
        } else {
            delete state.selected[id];
            $(this).closest('.wpim-image-card').removeClass('selected');
        }
        updateDeleteBtns();
    });

    $('#check-all').on('change', function() {
        var checked = $(this).is(':checked');
        $('.wpim-image-card').each(function() {
            var id = $(this).data('id');
            if (checked) {
                state.selected[id] = true;
                $(this).addClass('selected').find('.wpim-card-check').prop('checked', true);
            } else {
                delete state.selected[id];
                $(this).removeClass('selected').find('.wpim-card-check').prop('checked', false);
            }
        });
        updateDeleteBtns();
    });

    function updateDeleteBtns() {
        var count = Object.keys(state.selected).length;
        $('#selected-count').text(count + ' selected');
        $('#btn-delete-selected').prop('disabled', count === 0);
        var onPage = $('.wpim-image-card').length;
        $('#btn-delete-all-page').prop('disabled', onPage === 0);
    }

    // ─── Delete ───────────────────────────────────────────────────────
    $('#btn-delete-selected').on('click', function() {
        var ids = Object.keys(state.selected).map(Number);
        if (!ids.length) return;
        if (!confirm('Move ' + ids.length + ' image(s) to backup folder?\n\nThey will be removed from WordPress but can be restored later.')) return;
        doDelete(ids);
    });

    $('#btn-delete-all-page').on('click', function() {
        var ids = [];
        $('.wpim-image-card').each(function() { ids.push(parseInt($(this).data('id'))); });
        if (!ids.length) return;
        if (!confirm('Move all ' + ids.length + ' images on this page to backup?')) return;
        doDelete(ids);
    });

    function doDelete(ids) {
        var $btns = $('#btn-delete-selected, #btn-delete-all-page');
        $btns.prop('disabled', true);
        showProgress('#delete-progress', '#delete-progress-inner', '#delete-progress-text', 30, 'Moving ' + ids.length + ' images to backup…');

        ajax('wpim_delete_batch', { ids: ids }, function(err, data) {
            hideProgress('#delete-progress');
            $btns.prop('disabled', false);
            if (err) { toast('Delete error: ' + err, 'error'); return; }
            state.selected = {};
            var msg = '✅ Moved ' + data.deleted + ' image(s) to backup.';
            if (data.errors && data.errors.length) msg += ' ⚠️ ' + data.errors.length + ' errors.';
            toast(msg, 'success');
            // Reload current page
            setTimeout(function() { loadPage(state.currentPage); }, 400);
        }, 90);
    }

    // ─── Pagination ───────────────────────────────────────────────────
    $('#btn-prev').on('click', function() { if (state.currentPage > 1) loadPage(--state.currentPage); });
    $('#btn-next').on('click', function() { if (state.currentPage < state.totalPages) loadPage(++state.currentPage); });

    // ─── Progress helpers ─────────────────────────────────────────────
    function showProgress(bar, inner, text, pct, msg) {
        $(bar).show();
        $(inner).css('width', pct + '%');
        if (text) $(text).text(msg || '');
    }
    function hideProgress(bar) { $(bar).hide(); }

    // ─── WebP Converter ───────────────────────────────────────────────
    $('#toggle-auto-webp').on('change', function() {
        var enabled = $(this).is(':checked') ? 1 : 0;
        ajax('wpim_toggle_auto_webp', { enabled: enabled }, function(err) {
            if (err) toast('Error saving.', 'error');
            else toast(enabled ? '✅ Auto WebP ON — new JPEG/PNG uploads will be converted.' : 'Auto WebP OFF.', 'info');
        });
    });

    $('#btn-bulk-convert').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('⏳ Converting…');
        showProgress('#convert-progress', '#convert-progress-inner', '#convert-progress-text', 50, 'Converting images…');

        ajax('wpim_bulk_convert', { offset: state.convertOffset }, function(err, data) {
            hideProgress('#convert-progress');
            $btn.prop('disabled', false).text('🔄 Convert Next 50 to WebP');
            if (err) { toast('Conversion error: ' + err, 'error'); return; }

            state.convertOffset += 50;
            var msg = '✅ Converted: <strong>' + data.converted + '</strong>  |  Skipped: ' + data.skipped;
            if (data.remaining !== undefined) msg += '  |  Remaining JPEG/PNG: <strong>' + data.remaining + '</strong>';
            if (data.errors && data.errors.length) msg += '<br>⚠️ ' + data.errors.slice(0,3).join(', ');
            $('#convert-result').show().html(msg).removeClass('error');
            loadConversionStats();

            if (data.remaining === 0) {
                toast('🎉 All images converted to WebP!', 'success');
                $btn.prop('disabled', true).text('✅ All Converted');
            } else {
                toast('Converted ' + data.converted + '. ' + data.remaining + ' remaining.', 'success');
            }
        }, 120);
    });

    // ─── Restore Deleted ──────────────────────────────────────────────
    $('#btn-load-deleted').on('click', function() { loadDeletedList(1); });
    $(document).on('click', '#btn-deleted-prev', function() { loadDeletedList(--state.deletedPage); });
    $(document).on('click', '#btn-deleted-next', function() { loadDeletedList(++state.deletedPage); });

    function loadDeletedList(page) {
        state.deletedPage = page;
        $('#deleted-list').html('<p class="wpim-placeholder-sm">Loading…</p>');
        ajax('wpim_get_deleted', { page: page }, function(err, data) {
            if (err) { toast('Error: ' + err, 'error'); return; }
            state.deletedTotalPages = data.pages || 1;
            if (!data.items || !data.items.length) {
                $('#deleted-list').html('<p class="wpim-placeholder-sm">✅ No deleted backups found.</p>');
                $('#deleted-pagination').hide();
                return;
            }
            var html = '';
            $.each(data.items, function(i, item) {
                html += '<div class="wpim-restore-item">'
                      + '<div class="wpim-restore-item-info">'
                      +   '<div class="wpim-restore-item-title">' + escHtml(item.title || 'Untitled') + storageBadge(item.storage) + '</div>'
                      +   '<div class="wpim-restore-item-meta">ID: ' + item.id + ' &nbsp;·&nbsp; ' + escHtml(item.filename) + ' &nbsp;·&nbsp; Deleted: ' + item.deleted_at + '</div>'
                      + '</div>'
                      + '<button class="wpim-btn wpim-btn-success wpim-btn-sm btn-restore-deleted" data-id="' + item.id + '">♻️ Restore</button>'
                      + '</div>';
            });
            $('#deleted-list').html(html);
            renderRestorePagination('#deleted-pagination', '#deleted-page-info', '#btn-deleted-prev', '#btn-deleted-next', page, state.deletedTotalPages);
        });
    }

    $(document).on('click', '.btn-restore-deleted', function() {
        var id = $(this).data('id'), $btn = $(this);
        if (!confirm('Restore attachment #' + id + ' back to WordPress?')) return;
        $btn.prop('disabled', true).text('Restoring…');
        ajax('wpim_restore_deleted', { attachment_id: id }, function(err) {
            if (err) { toast('Restore error: ' + err, 'error'); $btn.prop('disabled', false).text('♻️ Restore'); return; }
            toast('✅ Attachment #' + id + ' restored!', 'success');
            $btn.closest('.wpim-restore-item').fadeOut(300, function() { $(this).remove(); });
        });
    });

    // ─── Restore Converted ────────────────────────────────────────────
    $('#btn-load-converted').on('click', function() { loadConvertedList(1); });
    $(document).on('click', '#btn-converted-prev', function() { loadConvertedList(--state.convertedPage); });
    $(document).on('click', '#btn-converted-next', function() { loadConvertedList(++state.convertedPage); });

    function loadConvertedList(page) {
        state.convertedPage = page;
        $('#converted-list').html('<p class="wpim-placeholder-sm">Loading…</p>');
        ajax('wpim_get_converted', { page: page }, function(err, data) {
            if (err) { toast('Error: ' + err, 'error'); return; }
            state.convertedTotalPages = data.pages || 1;
            if (!data.items || !data.items.length) {
                $('#converted-list').html('<p class="wpim-placeholder-sm">No converted images found. Use WebP Converter tab first.</p>');
                $('#converted-pagination').hide();
                return;
            }
            var html = '';
            $.each(data.items, function(i, item) {
                var warn = item.backup_exists ? '' : ' <em style="color:#d63638">(backup file missing)</em>';
                html += '<div class="wpim-restore-item">'
                      + '<div class="wpim-restore-item-info">'
                      +   '<div class="wpim-restore-item-title">' + escHtml(item.title) + storageBadge(item.storage) + warn + '</div>'
                      +   '<div class="wpim-restore-item-meta">ID: ' + item.id + ' &nbsp;·&nbsp; ' + escHtml(item.original) + ' → ' + escHtml(item.webp) + ' &nbsp;·&nbsp; ' + item.converted_at + '</div>'
                      + '</div>'
                      + '<button class="wpim-btn wpim-btn-sm btn-revert-converted" data-id="' + item.id + '"' + (item.backup_exists ? '' : ' disabled') + '>↩️ Revert</button>'
                      + '</div>';
            });
            $('#converted-list').html(html);
            renderRestorePagination('#converted-pagination', '#converted-page-info', '#btn-converted-prev', '#btn-converted-next', page, state.convertedTotalPages);
        });
    }

    $(document).on('click', '.btn-revert-converted', function() {
        var id = $(this).data('id'), $btn = $(this);
        if (!confirm('Revert attachment #' + id + ' from WebP back to original JPEG/PNG?')) return;
        $btn.prop('disabled', true).text('Reverting…');
        ajax('wpim_restore_converted', { attachment_id: id }, function(err) {
            if (err) { toast('Revert error: ' + err, 'error'); $btn.prop('disabled', false).text('↩️ Revert'); return; }
            toast('✅ Attachment #' + id + ' reverted!', 'success');
            $btn.closest('.wpim-restore-item').fadeOut(300, function() { $(this).remove(); });
            loadConversionStats();
        });
    });

    // ─── Helpers ──────────────────────────────────────────────────────
    function renderRestorePagination(pag, info, prev, next, page, total) {
        if (total <= 1) { $(pag).hide(); return; }
        $(pag).show();
        $(info).text('Page ' + page + ' of ' + total);
        $(prev).prop('disabled', page <= 1);
        $(next).prop('disabled', page >= total);
    }

    function escHtml(str) {
        return $('<div>').text(str || '').html();
    }

    function storageBadge(storage) {
        if (!storage || storage === 'local') return ' <span class="wpim-storage-badge local">Local</span>';
        if (storage === 'gdrive') return ' <span class="wpim-storage-badge gdrive">Google Drive</span>';
        return ' <span class="wpim-storage-badge mixed">Mixed</span>';
    }

    // ─── Init ─────────────────────────────────────────────────────────
    loadConversionStats();

})(jQuery);
