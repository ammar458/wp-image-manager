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
        attachedCategory: '',
        attachedPage: 1,
        attachedTotalPages: 1,
        attachedSelected: {},
        attachedCategoriesLoaded: false,
        gdriveStatusLoaded: false,
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
        if (tab === 'attached' && !state.attachedCategoriesLoaded) {
            loadAttachedCategories();
        }
        if (tab === 'gdrive-status' && !state.gdriveStatusLoaded) {
            loadGdriveStatus();
        }
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

    // Delete requests only ever do local file moves now — Google Drive uploads
    // happen in the background afterward — but a batch is still sent as
    // several smaller requests so a very large selection gives the progress
    // bar real percentages to show instead of one long silent wait.
    var DELETE_CHUNK_SIZE = 250;

    function doDelete(ids) {
        var $btns = $('#btn-delete-selected, #btn-delete-all-page');
        $btns.prop('disabled', true);

        var chunks = [];
        for (var i = 0; i < ids.length; i += DELETE_CHUNK_SIZE) {
            chunks.push(ids.slice(i, i + DELETE_CHUNK_SIZE));
        }

        var chunkIndex = 0, totalDeleted = 0, allErrors = [];
        showProgress('#delete-progress', '#delete-progress-inner', '#delete-progress-text', 0, 'Moving 0 of ' + ids.length + ' images to backup…');

        function runNextChunk() {
            if (chunkIndex >= chunks.length) {
                hideProgress('#delete-progress');
                $btns.prop('disabled', false);
                state.selected = {};
                var msg = '✅ Moved ' + totalDeleted + ' image(s) to backup.';
                if (allErrors.length) msg += ' ⚠️ ' + allErrors.length + ' error(s).';
                toast(msg, allErrors.length ? 'info' : 'success');
                setTimeout(function() { loadPage(state.currentPage); }, 400);
                return;
            }

            ajax('wpim_delete_batch', { ids: chunks[chunkIndex] }, function(err, data) {
                if (err) {
                    allErrors.push(err);
                } else {
                    totalDeleted += data.deleted;
                    if (data.errors && data.errors.length) allErrors = allErrors.concat(data.errors);
                }

                chunkIndex++;
                var doneCount = Math.min(chunkIndex * DELETE_CHUNK_SIZE, ids.length);
                var pct = Math.round((chunkIndex / chunks.length) * 100);
                showProgress('#delete-progress', '#delete-progress-inner', '#delete-progress-text', pct, 'Moving ' + doneCount + ' of ' + ids.length + ' images to backup… (' + pct + '%)');
                runNextChunk();
            }, 180);
        }

        runNextChunk();
    }

    // ─── Pagination ───────────────────────────────────────────────────
    $('#btn-prev').on('click', function() { if (state.currentPage > 1) loadPage(--state.currentPage); });
    $('#btn-next').on('click', function() { if (state.currentPage < state.totalPages) loadPage(++state.currentPage); });

    // ─── Browse Attached ──────────────────────────────────────────────
    $('#btn-refresh-categories').on('click', function() { loadAttachedCategories(); });

    function loadAttachedCategories() {
        $('#attached-category-select').prop('disabled', true);
        ajax('wpim_get_attached_categories', {}, function(err, data) {
            $('#attached-category-select').prop('disabled', false);
            if (err) { toast('Error: ' + err, 'error'); return; }
            state.attachedCategoriesLoaded = true;

            var $sel = $('#attached-category-select');
            var current = $sel.val();
            $sel.empty().append('<option value="">— Choose a category —</option>');
            if (!data.categories || !data.categories.length) {
                $sel.append('<option value="" disabled>No attached images found — run a scan first</option>');
                return;
            }
            $.each(data.categories, function(i, cat) {
                $sel.append('<option value="' + escHtml(cat.key) + '">' + escHtml(cat.label) + ' (' + cat.count + ')</option>');
            });
            if (current) $sel.val(current);
        }, 60);
    }

    $('#attached-category-select').on('change', function() {
        var cat = $(this).val();
        state.attachedCategory = cat;
        state.attachedSelected = {};
        if (!cat) {
            $('#wpim-attached-grid').html('<div class="wpim-placeholder"><p>Choose a category above to browse attached images.</p></div>');
            $('#attached-pagination').hide();
            updateAttachedDeleteBtns();
            return;
        }
        loadAttachedPage(1);
    });

    function loadAttachedPage(page) {
        if (!state.attachedCategory) return;
        $('#wpim-attached-grid').html('<div class="wpim-placeholder"><p>Loading page ' + page + '…</p></div>');
        ajax('wpim_get_attached_page', { category: state.attachedCategory, page: page }, function(err, data) {
            if (err) { toast('Error: ' + err, 'error'); return; }
            state.attachedPage = data.current;
            state.attachedTotalPages = data.pages;
            renderAttachedGrid(data.images);
            renderAttachedPagination();
            updateAttachedDeleteBtns();
        }, 60);
    }

    function renderAttachedGrid(images) {
        if (!images || !images.length) {
            $('#wpim-attached-grid').html('<div class="wpim-placeholder"><p>No images found in this category.</p></div>');
            $('#attached-pagination').hide();
            return;
        }
        var html = '';
        $.each(images, function(i, img) {
            var thumb = img.thumb
                ? '<img class="wpim-card-thumb" src="' + escHtml(img.thumb) + '" alt="" loading="lazy">'
                : '<div class="wpim-card-thumb-placeholder">🖼️</div>';
            var sel = state.attachedSelected[img.id] ? ' selected' : '';
            var chk = state.attachedSelected[img.id] ? ' checked' : '';
            html += '<div class="wpim-attached-card' + sel + '" data-id="' + img.id + '">'
                  + '<input type="checkbox" class="wpim-attached-card-check" data-id="' + img.id + '"' + chk + '>'
                  + thumb
                  + '<div class="wpim-card-badge">' + escHtml((img.mime || '').replace('image/', '')) + '</div>'
                  + '<div class="wpim-card-info">'
                  +   '<div class="wpim-card-name" title="' + escHtml(img.filename) + '">' + escHtml(img.filename) + '</div>'
                  +   '<div class="wpim-card-meta"><span>' + img.size + '</span><span>' + img.date + '</span></div>'
                  + '</div></div>';
        });
        $('#wpim-attached-grid').html(html);
        $('#attached-check-all').prop('checked', false);
    }

    function renderAttachedPagination() {
        if (state.attachedTotalPages <= 1) { $('#attached-pagination').hide(); return; }
        $('#attached-pagination').show();
        $('#attached-page-info').text('Page ' + state.attachedPage + ' of ' + state.attachedTotalPages);
        $('#btn-attached-prev').prop('disabled', state.attachedPage <= 1);
        $('#btn-attached-next').prop('disabled', state.attachedPage >= state.attachedTotalPages);
    }

    $(document).on('click', '.wpim-attached-card', function(e) {
        if ($(e.target).is('input')) return;
        var id = $(this).data('id');
        if (state.attachedSelected[id]) {
            delete state.attachedSelected[id];
            $(this).removeClass('selected').find('.wpim-attached-card-check').prop('checked', false);
        } else {
            state.attachedSelected[id] = true;
            $(this).addClass('selected').find('.wpim-attached-card-check').prop('checked', true);
        }
        updateAttachedDeleteBtns();
    });

    $(document).on('change', '.wpim-attached-card-check', function() {
        var id = $(this).data('id');
        if ($(this).is(':checked')) {
            state.attachedSelected[id] = true;
            $(this).closest('.wpim-attached-card').addClass('selected');
        } else {
            delete state.attachedSelected[id];
            $(this).closest('.wpim-attached-card').removeClass('selected');
        }
        updateAttachedDeleteBtns();
    });

    $('#attached-check-all').on('change', function() {
        var checked = $(this).is(':checked');
        $('.wpim-attached-card').each(function() {
            var id = $(this).data('id');
            if (checked) {
                state.attachedSelected[id] = true;
                $(this).addClass('selected').find('.wpim-attached-card-check').prop('checked', true);
            } else {
                delete state.attachedSelected[id];
                $(this).removeClass('selected').find('.wpim-attached-card-check').prop('checked', false);
            }
        });
        updateAttachedDeleteBtns();
    });

    function updateAttachedDeleteBtns() {
        var count = Object.keys(state.attachedSelected).length;
        $('#attached-selected-count').text(count + ' selected');
        $('#btn-attached-delete-selected').prop('disabled', count === 0);
    }

    $('#btn-attached-delete-selected').on('click', function() {
        var ids = Object.keys(state.attachedSelected).map(Number);
        if (!ids.length) return;
        if (!confirm('Move ' + ids.length + ' image(s) to backup folder?\n\nThey are currently in use elsewhere on your site — whatever references them will show a broken image until you restore them. Continue?')) return;
        doAttachedDelete(ids);
    });

    function doAttachedDelete(ids) {
        var $btn = $('#btn-attached-delete-selected');
        $btn.prop('disabled', true);

        var chunks = [];
        for (var i = 0; i < ids.length; i += DELETE_CHUNK_SIZE) {
            chunks.push(ids.slice(i, i + DELETE_CHUNK_SIZE));
        }

        var chunkIndex = 0, totalDeleted = 0, allErrors = [];
        showProgress('#attached-delete-progress', '#attached-delete-progress-inner', '#attached-delete-progress-text', 0, 'Moving 0 of ' + ids.length + ' images to backup…');

        function runNextChunk() {
            if (chunkIndex >= chunks.length) {
                hideProgress('#attached-delete-progress');
                $btn.prop('disabled', false);
                state.attachedSelected = {};
                var msg = '✅ Moved ' + totalDeleted + ' image(s) to backup.';
                if (allErrors.length) msg += ' ⚠️ ' + allErrors.length + ' error(s).';
                toast(msg, allErrors.length ? 'info' : 'success');
                setTimeout(function() {
                    loadAttachedPage(state.attachedPage);
                    loadAttachedCategories();
                }, 400);
                return;
            }

            ajax('wpim_delete_batch', { ids: chunks[chunkIndex] }, function(err, data) {
                if (err) {
                    allErrors.push(err);
                } else {
                    totalDeleted += data.deleted;
                    if (data.errors && data.errors.length) allErrors = allErrors.concat(data.errors);
                }
                chunkIndex++;
                var doneCount = Math.min(chunkIndex * DELETE_CHUNK_SIZE, ids.length);
                var pct = Math.round((chunkIndex / chunks.length) * 100);
                showProgress('#attached-delete-progress', '#attached-delete-progress-inner', '#attached-delete-progress-text', pct, 'Moving ' + doneCount + ' of ' + ids.length + ' images to backup… (' + pct + '%)');
                runNextChunk();
            }, 180);
        }

        runNextChunk();
    }

    $('#btn-attached-prev').on('click', function() { if (state.attachedPage > 1) loadAttachedPage(--state.attachedPage); });
    $('#btn-attached-next').on('click', function() { if (state.attachedPage < state.attachedTotalPages) loadAttachedPage(++state.attachedPage); });

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
            $btn.prop('disabled', false).text('🔄 Convert Next 100 to WebP');
            if (err) { toast('Conversion error: ' + err, 'error'); return; }

            state.convertOffset += 100;
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

    // ─── Google Drive Status ──────────────────────────────────────────
    $('#btn-load-gdrive-status').on('click', function() { loadGdriveStatus(); });
    $('#btn-gdrive-process-now').on('click', function() { processGdriveQueueNow(); });

    function loadGdriveStatus() {
        $('#gdrive-status-body').html('<p class="wpim-placeholder-sm">Loading…</p>');
        $('#btn-gdrive-process-now').prop('disabled', true);
        ajax('wpim_gdrive_status', {}, function(err, data) {
            if (err) { toast('Error: ' + err, 'error'); return; }
            state.gdriveStatusLoaded = true;
            renderGdriveStatus(data);
        }, 60);
    }

    function processGdriveQueueNow() {
        var $btn = $('#btn-gdrive-process-now');
        $btn.prop('disabled', true);
        $('#gdrive-process-spinner').show();
        ajax('wpim_gdrive_process_queue', {}, function(err, data) {
            $('#gdrive-process-spinner').hide();
            if (err) { toast('Error: ' + err, 'error'); $btn.prop('disabled', false); return; }
            toast('✅ Queue processed.', 'success');
            renderGdriveStatus(data);
        }, 120);
    }

    function renderGdriveStatus(data) {
        var d = data.deleted, c = data.converted;
        var connHtml = data.connected
            ? '✅ Connected to Google Drive as <strong>' + escHtml(data.account || '') + '</strong>'
            : '⚠️ Not connected to Google Drive.';
        var destHtml = data.destination === 'gdrive'
            ? 'Backup destination: <strong>Google Drive</strong>'
            : 'Backup destination: <strong>WordPress (local)</strong> — nothing is queued to upload.';

        var html = '<p>' + connHtml + '<br>' + destHtml + '</p>';

        html += '<h3 style="margin-top:20px">Deleted Image Backups</h3>';
        html += '<div class="wpim-stats-bar wpim-gdrive-stats">'
              +   '<div class="wpim-stat-card wpim-stat-total"><span class="wpim-stat-num">' + d.total + '</span><span class="wpim-stat-label">Total Backups</span></div>'
              +   '<div class="wpim-stat-card wpim-stat-attached"><span class="wpim-stat-num">' + d.uploaded + '</span><span class="wpim-stat-label">Uploaded to Drive</span></div>'
              +   '<div class="wpim-stat-card wpim-stat-webp"><span class="wpim-stat-num">' + d.pending + '</span><span class="wpim-stat-label">Queued / Pending Upload</span></div>'
              +   '<div class="wpim-stat-card wpim-stat-unattached"><span class="wpim-stat-num">' + d.local + '</span><span class="wpim-stat-label">Local Only</span></div>'
              + '</div>';

        html += '<h3 style="margin-top:20px">Converted (WebP) Backups</h3>';
        html += '<div class="wpim-stats-bar wpim-gdrive-stats">'
              +   '<div class="wpim-stat-card wpim-stat-total"><span class="wpim-stat-num">' + c.total + '</span><span class="wpim-stat-label">Total Backups</span></div>'
              +   '<div class="wpim-stat-card wpim-stat-attached"><span class="wpim-stat-num">' + c.uploaded + '</span><span class="wpim-stat-label">Uploaded to Drive</span></div>'
              +   '<div class="wpim-stat-card wpim-stat-unattached"><span class="wpim-stat-num">' + c.local + '</span><span class="wpim-stat-label">Local Only</span></div>'
              + '</div>';

        if (data.errors && data.errors.length) {
            html += '<h3 style="margin-top:20px">⚠️ Upload Errors (' + data.errors.length + ')</h3>';
            html += '<p class="wpim-attached-warning">These are still queued and will keep retrying automatically, but have failed at least once — check the message below to see why (commonly an expired/revoked Google connection, or a Drive quota issue).</p>';
            $.each(data.errors, function(i, e) {
                html += '<div class="wpim-restore-item">'
                      +   '<div class="wpim-restore-item-info">'
                      +     '<div class="wpim-restore-item-title">ID: ' + e.id + ' &nbsp;·&nbsp; ' + escHtml(e.filename) + '</div>'
                      +     '<div class="wpim-restore-item-meta" style="color:#d63638;white-space:normal">' + escHtml(e.error) + (e.deleted_at ? ' &nbsp;·&nbsp; Deleted: ' + escHtml(e.deleted_at) : '') + '</div>'
                      +   '</div>'
                      + '</div>';
            });
        }

        $('#gdrive-status-body').html(html);

        var canProcess = data.connected && data.destination === 'gdrive' && d.pending > 0;
        $('#btn-gdrive-process-now').prop('disabled', !canProcess)
            .attr('title', canProcess ? '' : (d.pending > 0 ? 'Connect Google Drive and set it as the backup destination first' : 'Nothing queued'));
    }

    // ─── Restore Deleted ──────────────────────────────────────────────
    $('#btn-load-deleted').on('click', function() { loadDeletedList(1); });
    $(document).on('click', '#btn-deleted-first', function() { loadDeletedList(1); });
    $(document).on('click', '#btn-deleted-prev', function() { loadDeletedList(--state.deletedPage); });
    $(document).on('click', '#btn-deleted-next', function() { loadDeletedList(++state.deletedPage); });
    $(document).on('click', '#btn-deleted-last', function() { loadDeletedList(state.deletedTotalPages); });

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
                      +   restoreThumb(item.thumb)
                      +   '<div class="wpim-restore-item-info">'
                      +     '<div class="wpim-restore-item-title">' + escHtml(item.title || 'Untitled') + storageBadge(item.storage) + '</div>'
                      +     '<div class="wpim-restore-item-meta">ID: ' + item.id + ' &nbsp;·&nbsp; ' + escHtml(item.filename) + ' &nbsp;·&nbsp; Deleted: ' + item.deleted_at + '</div>'
                      +   '</div>'
                      +   '<button class="wpim-btn wpim-btn-success wpim-btn-sm btn-restore-deleted" data-id="' + item.id + '">'
                      +     '<span class="wpim-spinner-inline wpim-btn-spinner" style="display:none"></span>'
                      +     '<span class="wpim-btn-label">♻️ Restore</span>'
                      +   '</button>'
                      + '</div>';
            });
            $('#deleted-list').html(html);
            renderRestorePagination('deleted', page, state.deletedTotalPages);
        });
    }

    $(document).on('click', '.btn-restore-deleted', function() {
        var id = $(this).data('id'), $btn = $(this);
        if (!confirm('Restore attachment #' + id + ' back to WordPress?')) return;
        setBtnBusy($btn, true, 'Restoring…');
        ajax('wpim_restore_deleted', { attachment_id: id }, function(err) {
            if (err) { toast('Restore error: ' + err, 'error'); setBtnBusy($btn, false, '♻️ Restore'); return; }
            toast('✅ Attachment #' + id + ' restored!', 'success');
            $btn.closest('.wpim-restore-item').fadeOut(300, function() { $(this).remove(); });
        });
    });

    // ─── Restore Converted ────────────────────────────────────────────
    $('#btn-load-converted').on('click', function() { loadConvertedList(1); });
    $(document).on('click', '#btn-converted-first', function() { loadConvertedList(1); });
    $(document).on('click', '#btn-converted-prev', function() { loadConvertedList(--state.convertedPage); });
    $(document).on('click', '#btn-converted-next', function() { loadConvertedList(++state.convertedPage); });
    $(document).on('click', '#btn-converted-last', function() { loadConvertedList(state.convertedTotalPages); });

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
                      +   restoreThumb(item.thumb)
                      +   '<div class="wpim-restore-item-info">'
                      +     '<div class="wpim-restore-item-title">' + escHtml(item.title) + storageBadge(item.storage) + warn + '</div>'
                      +     '<div class="wpim-restore-item-meta">ID: ' + item.id + ' &nbsp;·&nbsp; ' + escHtml(item.original) + ' → ' + escHtml(item.webp) + ' &nbsp;·&nbsp; ' + item.converted_at + '</div>'
                      +   '</div>'
                      +   '<button class="wpim-btn wpim-btn-primary wpim-btn-sm btn-revert-converted" data-id="' + item.id + '"' + (item.backup_exists ? '' : ' disabled') + '>'
                      +     '<span class="wpim-spinner-inline wpim-btn-spinner" style="display:none"></span>'
                      +     '<span class="wpim-btn-label">↩️ Revert</span>'
                      +   '</button>'
                      + '</div>';
            });
            $('#converted-list').html(html);
            renderRestorePagination('converted', page, state.convertedTotalPages);
        });
    }

    $(document).on('click', '.btn-revert-converted', function() {
        var id = $(this).data('id'), $btn = $(this);
        if (!confirm('Revert attachment #' + id + ' from WebP back to original JPEG/PNG?')) return;
        setBtnBusy($btn, true, 'Reverting…');
        ajax('wpim_restore_converted', { attachment_id: id }, function(err) {
            if (err) { toast('Revert error: ' + err, 'error'); setBtnBusy($btn, false, '↩️ Revert'); return; }
            toast('✅ Attachment #' + id + ' reverted!', 'success');
            $btn.closest('.wpim-restore-item').fadeOut(300, function() { $(this).remove(); });
            loadConversionStats();
        });
    });

    // ─── Helpers ──────────────────────────────────────────────────────
    function renderRestorePagination(prefix, page, total) {
        var $pag = $('#' + prefix + '-pagination');
        if (total <= 1) { $pag.hide(); return; }
        $pag.show();
        $('#' + prefix + '-page-input').val(page).attr('max', total);
        $('#' + prefix + '-page-total').text(total);
        $('#btn-' + prefix + '-first').prop('disabled', page <= 1);
        $('#btn-' + prefix + '-prev').prop('disabled', page <= 1);
        $('#btn-' + prefix + '-next').prop('disabled', page >= total);
        $('#btn-' + prefix + '-last').prop('disabled', page >= total);
    }

    // ─── Restore pagination: jump to typed page ───────────────────────
    var RESTORE_LOADERS = {
        deleted:   { load: loadDeletedList,   total: function() { return state.deletedTotalPages; } },
        converted: { load: loadConvertedList, total: function() { return state.convertedTotalPages; } }
    };

    function jumpToRestorePage(prefix) {
        var cfg = RESTORE_LOADERS[prefix];
        if (!cfg) return;
        var $input = $('#' + prefix + '-page-input');
        var total = cfg.total();
        var page = parseInt($input.val(), 10);
        if (!page || page < 1) page = 1;
        if (page > total) page = total;
        $input.val(page);
        cfg.load(page);
    }

    $(document).on('click', '#btn-deleted-go', function() { jumpToRestorePage('deleted'); });
    $(document).on('click', '#btn-converted-go', function() { jumpToRestorePage('converted'); });
    $(document).on('keydown', '#deleted-page-input', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); jumpToRestorePage('deleted'); }
    });
    $(document).on('keydown', '#converted-page-input', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); jumpToRestorePage('converted'); }
    });

    function escHtml(str) {
        return $('<div>').text(str || '').html();
    }

    function storageBadge(storage) {
        if (!storage || storage === 'local') return ' <span class="wpim-storage-badge local">Local</span>';
        if (storage === 'gdrive') return ' <span class="wpim-storage-badge gdrive">Google Drive</span>';
        if (storage === 'gdrive_pending') return ' <span class="wpim-storage-badge pending">Uploading to Drive…</span>';
        return ' <span class="wpim-storage-badge mixed">Mixed</span>';
    }

    function restoreThumb(thumb) {
        return thumb
            ? '<img class="wpim-restore-thumb" src="' + escHtml(thumb) + '" alt="" loading="lazy">'
            : '<div class="wpim-restore-thumb wpim-restore-thumb-placeholder">🖼️</div>';
    }

    function setBtnBusy($btn, busy, label) {
        $btn.prop('disabled', busy);
        $btn.find('.wpim-btn-spinner').toggle(busy);
        $btn.find('.wpim-btn-label').text(label);
    }

    // ─── Init ─────────────────────────────────────────────────────────
    loadConversionStats();

})(jQuery);
