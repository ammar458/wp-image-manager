<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wpim-wrap" id="wpim-app">
    <div class="wpim-header">
        <div class="wpim-header-inner">
            <h1>🖼️ Image Manager Pro</h1>
            <span class="wpim-version">v<?php echo WPIM_VERSION; ?></span>
        </div>
    </div>

    <!-- Stats Bar -->
    <div class="wpim-stats-bar" id="wpim-stats-bar">
        <div class="wpim-stat-card wpim-stat-total">
            <span class="wpim-stat-num" id="stat-total">—</span>
            <span class="wpim-stat-label">Total Images</span>
        </div>
        <div class="wpim-stat-card wpim-stat-attached">
            <span class="wpim-stat-num" id="stat-attached">—</span>
            <span class="wpim-stat-label">Attached</span>
        </div>
        <div class="wpim-stat-card wpim-stat-unattached">
            <span class="wpim-stat-num" id="stat-unattached">—</span>
            <span class="wpim-stat-label">Unattached</span>
        </div>
        <div class="wpim-stat-card wpim-stat-webp">
            <span class="wpim-stat-num" id="stat-webp">—</span>
            <span class="wpim-stat-label">WebP</span>
        </div>
        <div class="wpim-stat-card wpim-stat-jpeg">
            <span class="wpim-stat-num" id="stat-jpeg">—</span>
            <span class="wpim-stat-label">JPEG/PNG</span>
        </div>
        <button class="wpim-btn wpim-btn-scan" id="btn-scan">
            <span class="wpim-spinner-inline" id="scan-spinner" style="display:none"></span>
            🔍 Scan Now
        </button>
        <button class="wpim-btn wpim-btn-scan wpim-btn-outline" id="btn-deep-scan" disabled title="Run a quick scan first">
            <span class="wpim-spinner-inline" id="deep-scan-spinner" style="display:none"></span>
            🔬 Deep Scan
        </button>
    </div>
    <p class="wpim-deep-scan-note">
        <strong>Deep Scan</strong> checks extra places the quick scan can't see on its own:
        AdRotate ad images, serialized/JSON data (ACF galleries, Elementor, widgets, theme mods),
        comma-separated gallery ID lists, and raw image URLs in content or options.
        Run this before deleting anything if your site uses page builders, sliders, or ad plugins.
    </p>

    <!-- Tabs -->
    <div class="wpim-tabs">
        <button class="wpim-tab active" data-tab="unattached">🗂️ Unattached Images</button>
        <button class="wpim-tab" data-tab="convert">🔄 WebP Converter</button>
        <button class="wpim-tab" data-tab="restore-deleted">♻️ Restore Deleted</button>
        <button class="wpim-tab" data-tab="restore-converted">↩️ Revert WebP</button>
    </div>

    <!-- Tab: Unattached Images -->
    <div class="wpim-tab-content active" id="tab-unattached">
        <div class="wpim-toolbar">
            <label class="wpim-check-all-label">
                <input type="checkbox" id="check-all"> Select All on Page
            </label>
            <div class="wpim-toolbar-right">
                <span id="selected-count" class="wpim-selected-count">0 selected</span>
                <button class="wpim-btn wpim-btn-danger" id="btn-delete-selected" disabled>
                    🗑️ Delete Selected (move to backup)
                </button>
                <button class="wpim-btn wpim-btn-danger wpim-btn-outline" id="btn-delete-all-page" disabled>
                    Delete All on Page
                </button>
            </div>
        </div>

        <div class="wpim-progress-bar" id="delete-progress" style="display:none">
            <div class="wpim-progress-inner" id="delete-progress-inner"></div>
            <span class="wpim-progress-text" id="delete-progress-text">Deleting...</span>
        </div>

        <div id="wpim-images-grid" class="wpim-images-grid">
            <div class="wpim-placeholder">
                <p>Click <strong>Scan Now</strong> to detect unattached images.</p>
            </div>
        </div>

        <div class="wpim-pagination" id="wpim-pagination" style="display:none">
            <button class="wpim-btn wpim-btn-sm" id="btn-prev" disabled>← Prev</button>
            <span id="page-info">Page 1 of 1</span>
            <button class="wpim-btn wpim-btn-sm" id="btn-next" disabled>Next →</button>
        </div>
    </div>

    <!-- Tab: WebP Converter -->
    <div class="wpim-tab-content" id="tab-convert">
        <div class="wpim-convert-panel">
            <div class="wpim-convert-header">
                <h2>WebP Converter</h2>
                <div class="wpim-toggle-row">
                    <span>Auto-convert new uploads to WebP:</span>
                    <label class="wpim-toggle">
                        <input type="checkbox" id="toggle-auto-webp" <?php echo get_option('wpim_auto_webp', 1) ? 'checked' : ''; ?>>
                        <span class="wpim-toggle-slider"></span>
                    </label>
                </div>
            </div>

            <div class="wpim-convert-stats" id="convert-stats">
                <div class="wpim-cstat">
                    <span id="cstat-jpeg">—</span>
                    <small>JPEG/PNG remaining</small>
                </div>
                <div class="wpim-cstat">
                    <span id="cstat-webp">—</span>
                    <small>Already WebP</small>
                </div>
                <div class="wpim-cstat">
                    <span id="cstat-converted">—</span>
                    <small>Converted by this plugin</small>
                </div>
                <div class="wpim-cstat" id="cstat-support-wrap">
                    <span id="cstat-support">—</span>
                    <small>WebP support</small>
                </div>
            </div>

            <div class="wpim-convert-actions">
                <button class="wpim-btn wpim-btn-primary" id="btn-bulk-convert">
                    🔄 Convert 50 Images to WebP
                </button>
                <span class="wpim-convert-note">Originals are backed up before conversion. 50 at a time for performance.</span>
            </div>

            <div class="wpim-progress-bar" id="convert-progress" style="display:none">
                <div class="wpim-progress-inner" id="convert-progress-inner"></div>
                <span class="wpim-progress-text" id="convert-progress-text">Converting...</span>
            </div>

            <div id="convert-result" class="wpim-result-box" style="display:none"></div>
        </div>
    </div>

    <!-- Tab: Restore Deleted -->
    <div class="wpim-tab-content" id="tab-restore-deleted">
        <div class="wpim-restore-panel">
            <div class="wpim-restore-header">
                <h2>♻️ Restore Deleted Images</h2>
                <p>These images were moved to your backup folder. Click Restore to bring them back.</p>
                <button class="wpim-btn wpim-btn-sm" id="btn-load-deleted">Load Deleted Backups</button>
            </div>
            <div id="deleted-list" class="wpim-restore-list">
                <p class="wpim-placeholder-sm">Click "Load Deleted Backups" to see restorable images.</p>
            </div>
            <div class="wpim-pagination" id="deleted-pagination" style="display:none">
                <button class="wpim-btn wpim-btn-sm" id="btn-deleted-prev" disabled>← Prev</button>
                <span id="deleted-page-info">Page 1 of 1</span>
                <button class="wpim-btn wpim-btn-sm" id="btn-deleted-next" disabled>Next →</button>
            </div>
        </div>
    </div>

    <!-- Tab: Revert WebP -->
    <div class="wpim-tab-content" id="tab-restore-converted">
        <div class="wpim-restore-panel">
            <div class="wpim-restore-header">
                <h2>↩️ Revert WebP Conversions</h2>
                <p>Restore images that were converted to WebP back to their original JPEG/PNG format.</p>
                <button class="wpim-btn wpim-btn-sm" id="btn-load-converted">Load Converted Images</button>
            </div>
            <div id="converted-list" class="wpim-restore-list">
                <p class="wpim-placeholder-sm">Click "Load Converted Images" to see revertable items.</p>
            </div>
            <div class="wpim-pagination" id="converted-pagination" style="display:none">
                <button class="wpim-btn wpim-btn-sm" id="btn-converted-prev" disabled>← Prev</button>
                <span id="converted-page-info">Page 1 of 1</span>
                <button class="wpim-btn wpim-btn-sm" id="btn-converted-next" disabled>Next →</button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="wpim-toast" id="wpim-toast"></div>
</div>
