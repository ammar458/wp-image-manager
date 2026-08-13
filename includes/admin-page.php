<?php
if ( ! defined('ABSPATH') ) exit;
$wpim_active_tab = ( isset( $_GET['tab'] ) && $_GET['tab'] === 'settings' ) ? 'settings' : 'unattached';
?>
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
        <button class="wpim-tab<?php echo $wpim_active_tab === 'unattached' ? ' active' : ''; ?>" data-tab="unattached">🗂️ Unattached Images</button>
        <button class="wpim-tab" data-tab="attached">🏷️ Browse Attached</button>
        <button class="wpim-tab" data-tab="convert">🔄 WebP Converter</button>
        <button class="wpim-tab" data-tab="restore-deleted">♻️ Restore Deleted</button>
        <button class="wpim-tab" data-tab="restore-converted">↩️ Revert WebP</button>
        <button class="wpim-tab" data-tab="gdrive-status">☁️ Drive Status</button>
        <button class="wpim-tab<?php echo $wpim_active_tab === 'settings' ? ' active' : ''; ?>" data-tab="settings">⚙️ Backup Settings</button>
    </div>

    <!-- Tab: Unattached Images -->
    <div class="wpim-tab-content<?php echo $wpim_active_tab === 'unattached' ? ' active' : ''; ?>" id="tab-unattached">
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

    <!-- Tab: Browse Attached -->
    <div class="wpim-tab-content" id="tab-attached">
        <div class="wpim-toolbar">
            <label class="wpim-filter-label" for="attached-category-select">Filter by:</label>
            <select id="attached-category-select" class="wpim-select">
                <option value="">— Choose a category —</option>
            </select>
            <button class="wpim-btn wpim-btn-sm wpim-btn-outline" id="btn-refresh-categories">🔄 Refresh List</button>
        </div>
        <p class="wpim-attached-warning">
            ⚠️ These images are currently referenced somewhere on your site — by post type, page builder, or a plugin. Deleting one here moves it to backup (restorable later), but whatever uses it will show a broken image until you restore it.
        </p>

        <div class="wpim-toolbar">
            <label class="wpim-check-all-label">
                <input type="checkbox" id="attached-check-all"> Select All on Page
            </label>
            <div class="wpim-toolbar-right">
                <span id="attached-selected-count" class="wpim-selected-count">0 selected</span>
                <button class="wpim-btn wpim-btn-danger" id="btn-attached-delete-selected" disabled>
                    🗑️ Delete Selected (move to backup)
                </button>
            </div>
        </div>

        <div class="wpim-progress-bar" id="attached-delete-progress" style="display:none">
            <div class="wpim-progress-inner" id="attached-delete-progress-inner"></div>
            <span class="wpim-progress-text" id="attached-delete-progress-text">Deleting...</span>
        </div>

        <div id="wpim-attached-grid" class="wpim-images-grid">
            <div class="wpim-placeholder">
                <p>Choose a category above to browse attached images.</p>
            </div>
        </div>

        <div class="wpim-pagination" id="attached-pagination" style="display:none">
            <button class="wpim-btn wpim-btn-sm" id="btn-attached-prev" disabled>← Prev</button>
            <span id="attached-page-info">Page 1 of 1</span>
            <button class="wpim-btn wpim-btn-sm" id="btn-attached-next" disabled>Next →</button>
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
                    🔄 Convert 100 Images to WebP
                </button>
                <span class="wpim-convert-note">Originals are backed up before conversion. 100 at a time for performance.</span>
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
                <button class="wpim-btn wpim-btn-sm" id="btn-deleted-first" disabled>« First</button>
                <button class="wpim-btn wpim-btn-sm" id="btn-deleted-prev" disabled>← Prev</button>
                <span class="wpim-page-jump">
                    Page <input type="number" id="deleted-page-input" class="wpim-page-input" min="1" value="1"> of <span id="deleted-page-total">1</span>
                </span>
                <button class="wpim-btn wpim-btn-sm" id="btn-deleted-go">Go</button>
                <button class="wpim-btn wpim-btn-sm" id="btn-deleted-next" disabled>Next →</button>
                <button class="wpim-btn wpim-btn-sm" id="btn-deleted-last" disabled>Last »</button>
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
                <button class="wpim-btn wpim-btn-sm" id="btn-converted-first" disabled>« First</button>
                <button class="wpim-btn wpim-btn-sm" id="btn-converted-prev" disabled>← Prev</button>
                <span class="wpim-page-jump">
                    Page <input type="number" id="converted-page-input" class="wpim-page-input" min="1" value="1"> of <span id="converted-page-total">1</span>
                </span>
                <button class="wpim-btn wpim-btn-sm" id="btn-converted-go">Go</button>
                <button class="wpim-btn wpim-btn-sm" id="btn-converted-next" disabled>Next →</button>
                <button class="wpim-btn wpim-btn-sm" id="btn-converted-last" disabled>Last »</button>
            </div>
        </div>
    </div>

    <!-- Tab: Google Drive Status -->
    <div class="wpim-tab-content" id="tab-gdrive-status">
        <div class="wpim-restore-panel">
            <div class="wpim-restore-header">
                <h2>☁️ Google Drive Backup Status</h2>
                <p>How many backed-up images have actually finished uploading to Google Drive vs. are still local or stuck retrying.</p>
                <button class="wpim-btn wpim-btn-sm" id="btn-load-gdrive-status">Refresh Status</button>
                <button class="wpim-btn wpim-btn-sm wpim-btn-outline" id="btn-gdrive-process-now" disabled title="Load status first">
                    <span class="wpim-spinner-inline" id="gdrive-process-spinner" style="display:none"></span>
                    ⚙️ Process Upload Queue Now
                </button>
            </div>

            <div id="gdrive-status-body">
                <p class="wpim-placeholder-sm">Click "Refresh Status" to check.</p>
            </div>
        </div>
    </div>

    <!-- Tab: Backup Settings -->
    <div class="wpim-tab-content<?php echo $wpim_active_tab === 'settings' ? ' active' : ''; ?>" id="tab-settings">
        <div class="wpim-settings-panel">
            <h2>⚙️ Backup Destination</h2>
            <p>Choose where images are backed up before they're deleted or converted.</p>

            <?php
            $wpim_msg = isset( $_GET['wpim_msg'] ) ? sanitize_key( $_GET['wpim_msg'] ) : '';
            $wpim_messages = [
                'settings_saved'        => [ 'success', 'Backup settings saved.' ],
                'gdrive_connected'      => [ 'success', 'Google Drive connected successfully.' ],
                'gdrive_disconnected'   => [ 'info', 'Google Drive disconnected. Backup destination reset to WordPress (local).' ],
                'gdrive_not_configured' => [ 'error', 'Please enter your Google OAuth Client ID and Client Secret and save before connecting.' ],
                'gdrive_error'          => [ 'error', 'Could not connect to Google Drive. Please check your Client ID/Secret and try again.' ],
            ];
            if ( $wpim_msg && isset( $wpim_messages[ $wpim_msg ] ) ) :
                list( $wpim_msg_type, $wpim_msg_text ) = $wpim_messages[ $wpim_msg ];
                ?>
                <div class="wpim-result-box wpim-msg-<?php echo esc_attr( $wpim_msg_type ); ?>" style="display:block"><?php echo esc_html( $wpim_msg_text ); ?></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpim-settings-form">
                <?php wp_nonce_field( 'wpim_backup_settings' ); ?>
                <input type="hidden" name="action" value="wpim_save_backup_settings">

                <?php $wpim_destination = get_option( 'wpim_backup_destination', 'local' ); ?>
                <label class="wpim-radio-row">
                    <input type="radio" name="backup_destination" value="local" <?php checked( $wpim_destination, 'local' ); ?>>
                    <span><strong>WordPress (local storage)</strong> — backups are saved under <code>wp-content/../wp-image-manager-backup/</code> on this server.</span>
                </label>
                <label class="wpim-radio-row">
                    <input type="radio" name="backup_destination" value="gdrive" <?php checked( $wpim_destination, 'gdrive' ); ?>>
                    <span><strong>Google Drive</strong> — backups are uploaded to a "WP Image Manager Backups" folder in your connected Google Drive account, mirroring your uploads folder structure. Deleted images are removed from WordPress right away and upload to Drive in the background, so nothing is lost if the upload is briefly delayed — the file only leaves this server once Drive confirms it arrived. Converting to WebP still waits on the upload: if it fails, the conversion is aborted (nothing is converted) rather than falling back to local storage.</span>
                </label>

                <h3>Google Drive OAuth App</h3>
                <p class="wpim-settings-note">
                    Create an OAuth 2.0 Client ID (type: Web application) in the
                    <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Google Cloud Console</a>,
                    enable the Google Drive API, and add this Authorized redirect URI:
                </p>
                <code class="wpim-redirect-uri"><?php echo esc_html( WPIM_Google_Drive::get_redirect_uri() ); ?></code>

                <div class="wpim-field-row">
                    <label for="gdrive_client_id">Client ID</label>
                    <input type="text" id="gdrive_client_id" name="gdrive_client_id" value="<?php echo esc_attr( get_option( 'wpim_gdrive_client_id', '' ) ); ?>" autocomplete="off">
                </div>
                <div class="wpim-field-row">
                    <label for="gdrive_client_secret">Client Secret</label>
                    <input type="password" id="gdrive_client_secret" name="gdrive_client_secret" placeholder="<?php echo get_option( 'wpim_gdrive_client_secret' ) ? '••••••••  (leave blank to keep current)' : ''; ?>" autocomplete="off">
                </div>

                <button type="submit" class="wpim-btn wpim-btn-primary">Save Settings</button>
            </form>

            <div class="wpim-gdrive-status">
                <?php if ( WPIM_Google_Drive::is_connected() ) : ?>
                    <p>✅ Connected to Google Drive as <strong><?php echo esc_html( WPIM_Google_Drive::get_account_email() ); ?></strong></p>
                    <a class="wpim-btn wpim-btn-outline wpim-btn-sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpim_gdrive_disconnect' ), 'wpim_gdrive_disconnect' ) ); ?>">Disconnect Google Drive</a>
                <?php elseif ( WPIM_Google_Drive::is_configured() ) : ?>
                    <p>Not connected yet.</p>
                    <a class="wpim-btn wpim-btn-primary wpim-btn-sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpim_gdrive_connect' ), 'wpim_gdrive_connect' ) ); ?>">Connect Google Drive</a>
                <?php else : ?>
                    <p class="wpim-placeholder-sm">Enter and save a Client ID/Secret above to enable connecting.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="wpim-toast" id="wpim-toast"></div>
</div>
