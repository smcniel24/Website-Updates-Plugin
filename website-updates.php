<?php
/**
 * Plugin Name: Website Updates Report
 * Plugin URI: https://godev.net
 * Description: Generate detailed reports of website activity including new pages, updated content, and other changes within a specified date range for client reporting.
 * Version: 1.1.0
 * Author: GoDev Technologies
 * Author URI: https://godev.net
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: website-updates
 * Requires at least: 5.8
 * Update URI: https://github.com/smcniel24/Website-Updates-Plugin
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('WEBSITE_UPDATES_VERSION', '1.1.0');
define('WEBSITE_UPDATES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WEBSITE_UPDATES_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WEBSITE_UPDATES_GITHUB_REPO', 'smcniel24/Website-Updates-Plugin');

// Updates come from GitHub releases instead of WordPress.org
require_once WEBSITE_UPDATES_PLUGIN_DIR . 'includes/class-github-updater.php';
new Website_Updates_GitHub_Updater(__FILE__, WEBSITE_UPDATES_GITHUB_REPO, WEBSITE_UPDATES_VERSION);

/**
 * Main Plugin Class
 */
class Website_Updates_Report {
    
    private static $instance = null;

    // Versions recorded before an update runs, keyed by "type:slug"
    private $versions_before = array();
    private $core_version_before = '';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_generate_updates_report', array($this, 'ajax_generate_report'));
        add_action('wp_ajax_email_updates_report', array($this, 'ajax_email_report'));
        add_action('wp_ajax_sync_gallery_folders', array($this, 'ajax_sync_gallery_folders'));
        add_action('admin_post_website_updates_save_schedule', array($this, 'handle_schedule_save'));
        add_action('website_updates_send_scheduled_report', array($this, 'send_scheduled_report'));
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        
        // Hook into BildeGallery folder events to track gallery activity
        add_action('bilde_folder_created', array($this, 'log_folder_created'), 10, 2);
        add_action('bilde_folder_updated', array($this, 'log_folder_updated'), 10, 3);
        add_action('bilde_folder_deleted', array($this, 'log_folder_deleted'), 10, 2);

        // Track plugin, theme and WordPress core updates
        $this->core_version_before = isset($GLOBALS['wp_version']) ? $GLOBALS['wp_version'] : '';
        add_filter('upgrader_pre_install', array($this, 'capture_versions_before_update'), 10, 2);
        add_action('upgrader_process_complete', array($this, 'log_software_updates'), 10, 2);

        $this->ensure_biweekly_schedule(get_option('website_updates_schedule_enabled') === '1');
    }
    
    /**
     * Create database table for folder activity logging
     */
    public static function create_activity_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'website_updates_activity';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            activity_type varchar(50) NOT NULL,
            object_id bigint(20) NOT NULL,
            object_name varchar(255) NOT NULL,
            object_data text,
            user_id bigint(20) unsigned NOT NULL,
            activity_date datetime NOT NULL,
            PRIMARY KEY (id),
            KEY activity_type (activity_type),
            KEY activity_date (activity_date),
            KEY object_id (object_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Log when a BildeGallery folder is created
     */
    public function log_folder_created($folder_id, $folder) {
        $this->log_gallery_activity('gallery_folder_created', $folder_id, $folder);
    }

    /**
     * Log when a BildeGallery folder is deleted (fires before the folder is removed)
     */
    public function log_folder_deleted($folder_id, $folder) {
        $this->log_gallery_activity('gallery_folder_deleted', $folder_id, $folder);
    }

    /**
     * Log when a BildeGallery folder is renamed or moved
     */
    public function log_folder_updated($folder_id, $folder, $changes) {
        $this->log_gallery_activity('gallery_folder_updated', $folder_id, $folder, array(
            'renamed' => !empty($changes['renamed']),
            'moved' => !empty($changes['moved']),
            'old_name' => isset($changes['old_name']) ? $changes['old_name'] : '',
            'old_path' => isset($changes['old_path']) ? $changes['old_path'] : ''
        ));
    }

    /**
     * Insert one gallery folder activity into the activity log
     */
    private function log_gallery_activity($activity_type, $folder_id, $folder, $extra = array(), $user_id = null, $activity_date = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'website_updates_activity';

        $wpdb->insert(
            $table_name,
            array(
                'activity_type' => $activity_type,
                'object_id' => $folder_id,
                'object_name' => $folder['name'],
                'object_data' => json_encode(array_merge(array(
                    'parent_id' => $folder['parent'],
                    'folder_path' => $folder['path']
                ), $extra)),
                'user_id' => $user_id !== null ? $user_id : get_current_user_id(),
                'activity_date' => $activity_date !== null ? $activity_date : current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%d', '%s')
        );
    }
    
    /**
     * Record the current plugin/theme version before an update overwrites it
     */
    public function capture_versions_before_update($response, $hook_extra) {
        if (!empty($hook_extra['plugin'])) {
            $data = $this->read_plugin_header($hook_extra['plugin']);
            if ($data) {
                $this->versions_before['plugin:' . $hook_extra['plugin']] = $data;
            }
        }

        if (!empty($hook_extra['theme'])) {
            $data = $this->read_theme_header($hook_extra['theme']);
            if ($data) {
                $this->versions_before['theme:' . $hook_extra['theme']] = $data;
            }
        }

        return $response;
    }

    /**
     * Log completed plugin, theme and WordPress core updates
     */
    public function log_software_updates($upgrader, $hook_extra) {
        if (empty($hook_extra['action']) || $hook_extra['action'] !== 'update' || empty($hook_extra['type'])) {
            return;
        }

        $type = $hook_extra['type'];

        if ($type === 'plugin' || $type === 'theme') {
            if (!empty($hook_extra[$type . 's'])) {
                $slugs = (array) $hook_extra[$type . 's'];
            } elseif (!empty($hook_extra[$type])) {
                $slugs = array($hook_extra[$type]);
            } else {
                $slugs = array();
            }

            foreach ($slugs as $slug) {
                $after = ($type === 'plugin') ? $this->read_plugin_header($slug) : $this->read_theme_header($slug);
                if (!$after) {
                    continue;
                }

                $before = isset($this->versions_before[$type . ':' . $slug]) ? $this->versions_before[$type . ':' . $slug] : array();

                $this->log_software_update(
                    $type,
                    $slug,
                    !empty($after['Name']) ? $after['Name'] : (!empty($before['Name']) ? $before['Name'] : $slug),
                    isset($before['Version']) ? $before['Version'] : '',
                    $after['Version']
                );
            }
        } elseif ($type === 'core') {
            $this->log_software_update('core', 'wordpress', 'WordPress', $this->core_version_before, $this->read_core_version());
        }
    }

    /**
     * Insert one software update into the activity log
     */
    private function log_software_update($type, $slug, $name, $old_version, $new_version) {
        // Skip failed updates and reinstalls of the same version
        if ($new_version === '' || $old_version === $new_version) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'website_updates_activity';

        $wpdb->insert(
            $table_name,
            array(
                'activity_type' => $type . '_updated',
                'object_id' => 0,
                'object_name' => $name,
                'object_data' => json_encode(array(
                    'type' => $type,
                    'slug' => $slug,
                    'old_version' => $old_version,
                    'new_version' => $new_version
                )),
                'user_id' => get_current_user_id(),
                'activity_date' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%d', '%s')
        );
    }

    /**
     * Read a plugin's name and version straight from its file (avoids cached plugin data)
     */
    private function read_plugin_header($plugin_file) {
        $path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if (!file_exists($path)) {
            return null;
        }
        return get_file_data($path, array('Name' => 'Plugin Name', 'Version' => 'Version'));
    }

    /**
     * Read a theme's name and version straight from its style.css (avoids cached theme data)
     */
    private function read_theme_header($stylesheet) {
        $path = get_theme_root($stylesheet) . '/' . $stylesheet . '/style.css';
        if (!file_exists($path)) {
            return null;
        }
        return get_file_data($path, array('Name' => 'Theme Name', 'Version' => 'Version'));
    }

    /**
     * Read the installed WordPress version from version.php (the global still holds the old version mid-update)
     */
    private function read_core_version() {
        $contents = @file_get_contents(ABSPATH . WPINC . '/version.php');
        if ($contents && preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $contents, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Website Updates', 'website-updates'),
            __('Updates Report', 'website-updates'),
            'manage_options',
            'website-updates-report',
            array($this, 'render_admin_page'),
            'dashicons-chart-line',
            30
        );
    }
    
    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_admin_assets($hook) {
        if ('toplevel_page_website-updates-report' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'website-updates-admin',
            WEBSITE_UPDATES_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WEBSITE_UPDATES_VERSION
        );
        
        wp_enqueue_script(
            'website-updates-admin',
            WEBSITE_UPDATES_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WEBSITE_UPDATES_VERSION,
            true
        );
        
        wp_localize_script('website-updates-admin', 'websiteUpdates', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('website_updates_nonce')
        ));
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap website-updates-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?> <span style="font-size: 14px; color: #666; font-weight: normal;">v<?php echo WEBSITE_UPDATES_VERSION; ?></span></h1>
            
            <div class="website-updates-container">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="scheduled-email-form">
                    <?php wp_nonce_field('website_updates_schedule', 'website_updates_schedule_nonce'); ?>
                    <input type="hidden" name="action" value="website_updates_save_schedule">
                    <div class="scheduled-email-row">
                        <div class="scheduled-email-info">
                            <h2><?php _e('Scheduled Email (Every Other Monday, 8:00 AM)', 'website-updates'); ?></h2>
                            <p><?php _e('Send this report automatically every other Monday at 8:00 AM.', 'website-updates'); ?></p>
                        </div>
                        <div class="scheduled-email-controls">
                            <label class="scheduled-email-toggle">
                                <input type="checkbox" name="schedule_enabled" value="1" <?php checked(get_option('website_updates_schedule_enabled'), '1'); ?>>
                                <?php _e('Enable scheduled email', 'website-updates'); ?>
                            </label>
                            <label class="scheduled-email-address" for="schedule_email">
                                <?php _e('Send to email:', 'website-updates'); ?>
                                <input type="text" id="schedule_email" name="schedule_email" value="<?php echo esc_attr(get_option('website_updates_schedule_email', get_option('admin_email'))); ?>" placeholder="email@domain.com, email2@domain.com">
                                <span class="description"><?php _e('Separate multiple emails with commas or semicolons.', 'website-updates'); ?></span>
                            </label>
                        </div>
                        <div class="scheduled-email-actions">
                            <button type="submit" class="button button-secondary">
                                <?php _e('Save Schedule', 'website-updates'); ?>
                            </button>
                        </div>
                    </div>
                </form>

                <div class="date-range-selector">
                    <h2><?php _e('Select Date Range', 'website-updates'); ?></h2>
                    <form id="updates-report-form">
                        <?php wp_nonce_field('website_updates_nonce', 'website_updates_nonce_field'); ?>
                        
                        <div class="date-inputs">
                            <div class="date-field">
                                <label for="start_date"><?php _e('Start Date:', 'website-updates'); ?></label>
                                <input type="date" id="start_date" name="start_date" required 
                                       value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
                            </div>
                            
                            <div class="date-field">
                                <label for="end_date"><?php _e('End Date:', 'website-updates'); ?></label>
                                <input type="date" id="end_date" name="end_date" required 
                                       value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        
                        <div class="report-options">
                            <h3><?php _e('Include in Report:', 'website-updates'); ?></h3>
                            <label>
                                <input type="checkbox" name="include_pages" value="1" checked>
                                <?php _e('Pages (New & Updated)', 'website-updates'); ?>
                                <span class="report-option-count" data-report-count="pages"></span>
                            </label>
                            <label>
                                <input type="checkbox" name="include_projects" value="1" checked>
                                <?php _e('Projects (New & Updated)', 'website-updates'); ?>
                                <span class="report-option-count" data-report-count="projects"></span>
                            </label>
                            <label>
                                <input type="checkbox" name="include_posts" value="1" checked>
                                <?php _e('Posts (New & Updated)', 'website-updates'); ?>
                                <span class="report-option-count" data-report-count="posts"></span>
                            </label>
                            <label>
                                <input type="checkbox" name="include_media" value="1" checked>
                                <?php _e('Media Files', 'website-updates'); ?>
                                <span class="report-option-count" data-report-count="media"></span>
                            </label>
                            <label>
                                <input type="checkbox" name="include_gallery" value="1" checked>
                                <?php _e('Gallery Pages', 'website-updates'); ?>
                                <span class="report-option-count" data-report-count="gallery"></span>
                            </label>
                            <label>
                                <input type="checkbox" name="include_users" value="1">
                                <?php _e('New Users', 'website-updates'); ?>
                                <span class="report-option-count" data-report-count="users"></span>
                            </label>
                            <label>
                                <input type="checkbox" name="include_plugins" value="1">
                                <?php _e('Plugin, Theme & WordPress Updates', 'website-updates'); ?>
                                <span class="report-option-count" data-report-count="plugins"></span>
                            </label>
                        </div>

                        <div class="report-gallery-sync">
                            <h3><?php _e('Missing Gallery Pages?', 'website-updates'); ?></h3>
                            <p><?php _e('If you created BildeGallery folders before this version of the plugin was installed, you can sync them into the report log. Folders imported from FileBird are skipped.', 'website-updates'); ?></p>
                            <button type="button" id="sync-gallery-folders" class="button">
                                <?php _e('Sync Existing Gallery Folders', 'website-updates'); ?>
                            </button>
                            <span id="sync-gallery-result" class="sync-gallery-result" aria-live="polite"></span>
                        </div>

                        <button type="submit" class="button button-primary button-large">
                            <?php _e('Generate Report', 'website-updates'); ?>
                        </button>
                    </form>
                </div>
                
                <div id="report-results" class="report-results" style="display:none;">
                    <div class="report-actions">
                        <button type="button" id="export-pdf" class="button button-secondary" style="display:none;">
                            <?php _e('Export as PDF', 'website-updates'); ?>
                        </button>
                        <button type="button" id="email-report" class="button button-secondary" style="display:none;">
                            <?php _e('Email Report', 'website-updates'); ?>
                        </button>
                    </div>
                    <div class="report-loading">
                        <span class="spinner is-active"></span>
                        <?php _e('Generating report...', 'website-updates'); ?>
                    </div>
                    <div id="report-content"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for generating report
     */
    public function ajax_generate_report() {
        check_ajax_referer('website_updates_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'website-updates')));
        }
        
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        $include_pages = !empty($_POST['include_pages']) && $_POST['include_pages'] !== 'false';
        $include_projects = !empty($_POST['include_projects']) && $_POST['include_projects'] !== 'false';
        $include_posts = !empty($_POST['include_posts']) && $_POST['include_posts'] !== 'false';
        $include_media = !empty($_POST['include_media']) && $_POST['include_media'] !== 'false';
        $include_gallery = !empty($_POST['include_gallery']) && $_POST['include_gallery'] !== 'false';
        $include_users = !empty($_POST['include_users']) && $_POST['include_users'] !== 'false';
        $include_plugins = !empty($_POST['include_plugins']) && $_POST['include_plugins'] !== 'false';
        
        $report_data = array(
            'date_range' => array(
                'start' => $start_date,
                'end' => $end_date
            ),
            'summary' => array(),
            'details' => array()
        );
        
        // Get pages data
        if ($include_pages) {
            $report_data['details']['pages'] = $this->get_pages_data($start_date, $end_date);
            $report_data['summary']['pages'] = array(
                'new' => count($report_data['details']['pages']['new']),
                'updated' => count($report_data['details']['pages']['updated'])
            );
            $report_data['details']['pages']['included'] = true;
        }
        
        // Get projects data
        if ($include_projects) {
            $report_data['details']['projects'] = $this->get_projects_data($start_date, $end_date);
            $report_data['summary']['projects'] = array(
                'new' => count($report_data['details']['projects']['new']),
                'updated' => count($report_data['details']['projects']['updated'])
            );
            $report_data['details']['projects']['included'] = true;
        }
        
        // Get posts data
        if ($include_posts) {
            $report_data['details']['posts'] = $this->get_posts_data($start_date, $end_date);
            $report_data['summary']['posts'] = array(
                'new' => count($report_data['details']['posts']['new']),
                'updated' => count($report_data['details']['posts']['updated'])
            );
            $report_data['details']['posts']['included'] = true;
        }
        
        // Get media data
        if ($include_media) {
            $media_items = $this->get_media_data($start_date, $end_date);
            $report_data['details']['media'] = array(
                'items' => $media_items,
                'included' => true
            );
            $report_data['summary']['media'] = count($media_items);
        }
        
        // Get gallery folder data
        if ($include_gallery) {
            $report_data['details']['gallery'] = $this->get_gallery_data($start_date, $end_date);
            $report_data['summary']['gallery'] = array(
                'created' => count($report_data['details']['gallery']['created']),
                'updated' => count($report_data['details']['gallery']['updated']),
                'deleted' => count($report_data['details']['gallery']['deleted'])
            );
            // Ensure the key exists even if empty
            $report_data['details']['gallery']['included'] = true;
        }
        
        // Get users data
        if ($include_users) {
            $users_items = $this->get_users_data($start_date, $end_date);
            $report_data['details']['users'] = array(
                'items' => $users_items,
                'included' => true
            );
            $report_data['summary']['users'] = count($users_items);
        }
        
        // Get plugin updates
        if ($include_plugins) {
            $plugins_items = $this->get_plugin_updates($start_date, $end_date);
            $report_data['details']['plugins'] = array(
                'items' => $plugins_items,
                'included' => true
            );
            $report_data['summary']['plugins'] = count($plugins_items);
        }
        
        // Generate HTML report
        $html = $this->generate_report_html($report_data);
        
        wp_send_json_success(array(
            'html' => $html,
            'data' => $report_data
        ));
    }
    
    /**
     * AJAX handler for emailing report
     */
    public function ajax_email_report() {
        check_ajax_referer('website_updates_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'website-updates')));
        }
        
        $email = sanitize_email($_POST['email']);
        $report_html = wp_kses_post($_POST['report_html']);
        $date_range = isset($_POST['date_range']) ? $_POST['date_range'] : array();
        
        if (!is_email($email)) {
            wp_send_json_error(array('message' => __('Invalid email address', 'website-updates')));
        }
        
        $start_date = isset($date_range['start']) ? date('F j, Y', strtotime($date_range['start'])) : '';
        $end_date = isset($date_range['end']) ? date('F j, Y', strtotime($date_range['end'])) : '';
        
        $subject = sprintf(__('Website Activity Report: %s to %s', 'website-updates'), $start_date, $end_date);
        
        // Create HTML email
        $message = $this->build_email_message($report_html);

        // Set email headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>'
        );

        // Send email
        $sent = wp_mail($email, $subject, $message, $headers);

        if ($sent) {
            wp_send_json_success(array('message' => __('Report sent successfully', 'website-updates')));
        } else {
            wp_send_json_error(array('message' => __('Failed to send email', 'website-updates')));
        }
    }

    /**
     * Wrap report HTML in the styled email template (used by manual and scheduled emails)
     */
    private function build_email_message($report_html) {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; padding: 20px; background-color: #f6f7f7; }
        .email-container { max-width: 800px; margin: 0 auto; background-color: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f6f7f7; font-weight: 600; }
        .report-header { margin-bottom: 30px; }
        .report-header h2 { margin: 0 0 10px 0; color: #23282d; }
        .report-period { color: #666; margin: 0; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .summary-item { background: #f6f7f7; padding: 15px; border-radius: 4px; }
        .summary-icon { font-size: 24px; margin-bottom: 8px; }
        .summary-content h4 { margin: 0 0 5px 0; font-size: 14px; color: #666; }
        .summary-stat { margin: 0; font-size: 18px; }
        .detail-section { margin: 30px 0; }
        .detail-section h3 { border-bottom: 2px solid #0073aa; padding-bottom: 10px; color: #23282d; }
        .detail-section h4 { margin-top: 20px; color: #23282d; }
        .button { display: none !important; }
    </style>
</head>
<body>
    <div class="email-container">
        ' . $report_html . '
    </div>
</body>
</html>';
    }

    public function add_cron_schedules($schedules) {
        $schedules['biweekly'] = array(
            'interval' => 14 * DAY_IN_SECONDS,
            'display' => __('Every 2 weeks', 'website-updates')
        );
        return $schedules;
    }

    private function ensure_biweekly_schedule($enabled) {
        if ($enabled) {
            $next_timestamp = $this->get_next_biweekly_monday_timestamp();
            if (!wp_next_scheduled('website_updates_send_scheduled_report')) {
                wp_schedule_event($next_timestamp, 'biweekly', 'website_updates_send_scheduled_report');
            }
        } else {
            wp_clear_scheduled_hook('website_updates_send_scheduled_report');
        }
    }

    private function get_next_biweekly_monday_timestamp() {
        $now = current_time('timestamp');
        $timezone = wp_timezone();

        $next = new DateTime('now', $timezone);
        $next->setTimestamp($now);
        $next->modify('next monday');
        $next->setTime(8, 0, 0);

        if ($next->getTimestamp() <= $now) {
            $next->modify('+14 days');
        }

        return $next->getTimestamp();
    }

    private function normalize_schedule_emails($raw_emails) {
        $raw_emails = is_string($raw_emails) ? $raw_emails : '';
        $parts = preg_split('/[;,]+/', $raw_emails);
        $valid = array();

        foreach ($parts as $part) {
            $email = sanitize_email(trim($part));
            if (!empty($email) && is_email($email)) {
                $valid[] = $email;
            }
        }

        $valid = array_values(array_unique($valid));
        return implode(',', $valid);
    }

    public function handle_schedule_save() {
        check_admin_referer('website_updates_schedule', 'website_updates_schedule_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'website-updates'));
        }

        $enabled = isset($_POST['schedule_enabled']) && $_POST['schedule_enabled'] === '1';
        $email = isset($_POST['schedule_email']) ? $this->normalize_schedule_emails(wp_unslash($_POST['schedule_email'])) : '';

        update_option('website_updates_schedule_enabled', $enabled ? '1' : '0');
        update_option('website_updates_schedule_email', $email);

        wp_clear_scheduled_hook('website_updates_send_scheduled_report');
        $this->ensure_biweekly_schedule($enabled);

        $redirect_url = wp_get_referer();
        if (empty($redirect_url)) {
            $redirect_url = admin_url('admin.php?page=website-updates-report');
        }

        wp_safe_redirect(add_query_arg('schedule_saved', '1', $redirect_url));
        exit;
    }

    public function send_scheduled_report() {
        $email = $this->normalize_schedule_emails(get_option('website_updates_schedule_email'));
        if (empty($email)) {
            $email = get_option('admin_email');
        }

        $end_date = current_time('Y-m-d');
        $start_date = date('Y-m-d', strtotime('-14 days', current_time('timestamp')));

        $report_data = array(
            'date_range' => array(
                'start' => $start_date,
                'end' => $end_date
            ),
            'summary' => array(),
            'details' => array()
        );

        $report_data['details']['pages'] = $this->get_pages_data($start_date, $end_date);
        $report_data['summary']['pages'] = array(
            'new' => count($report_data['details']['pages']['new']),
            'updated' => count($report_data['details']['pages']['updated'])
        );
        $report_data['details']['pages']['included'] = true;

        $report_data['details']['projects'] = $this->get_projects_data($start_date, $end_date);
        $report_data['summary']['projects'] = array(
            'new' => count($report_data['details']['projects']['new']),
            'updated' => count($report_data['details']['projects']['updated'])
        );
        $report_data['details']['projects']['included'] = true;

        $report_data['details']['posts'] = $this->get_posts_data($start_date, $end_date);
        $report_data['summary']['posts'] = array(
            'new' => count($report_data['details']['posts']['new']),
            'updated' => count($report_data['details']['posts']['updated'])
        );
        $report_data['details']['posts']['included'] = true;

        $media_items = $this->get_media_data($start_date, $end_date);
        $report_data['details']['media'] = array(
            'items' => $media_items,
            'included' => true
        );
        $report_data['summary']['media'] = count($media_items);

        $report_data['details']['gallery'] = $this->get_gallery_data($start_date, $end_date);
        $report_data['summary']['gallery'] = array(
            'created' => count($report_data['details']['gallery']['created']),
            'updated' => count($report_data['details']['gallery']['updated']),
            'deleted' => count($report_data['details']['gallery']['deleted'])
        );
        $report_data['details']['gallery']['included'] = true;

        $users_items = $this->get_users_data($start_date, $end_date);
        $report_data['details']['users'] = array(
            'items' => $users_items,
            'included' => true
        );
        $report_data['summary']['users'] = count($users_items);

        $plugins_items = $this->get_plugin_updates($start_date, $end_date);
        $report_data['details']['plugins'] = array(
            'items' => $plugins_items,
            'included' => true
        );
        $report_data['summary']['plugins'] = count($plugins_items);

        $report_html = $this->generate_report_html($report_data);

        $subject = sprintf(__('Website Activity Report: %s to %s', 'website-updates'),
            date('F j, Y', strtotime($start_date)),
            date('F j, Y', strtotime($end_date))
        );

        $message = $this->build_email_message($report_html);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>'
        );

        wp_mail($email, $subject, $message, $headers);
    }

    /**
     * AJAX handler to sync existing BildeGallery folders into report activity log
     */
    public function ajax_sync_gallery_folders() {
        check_ajax_referer('website_updates_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'website-updates')));
        }

        if (!$this->gallery_plugin_active()) {
            wp_send_json_error(array('message' => __('BildeGallery 1.2.0 or higher must be active to sync gallery folders.', 'website-updates')));
        }

        global $wpdb;
        $activity_table = $wpdb->prefix . 'website_updates_activity';

        if ($wpdb->get_var("SHOW TABLES LIKE '$activity_table'") != $activity_table) {
            self::create_activity_table();
        }

        $activity_date = '';
        $activity_date_input = isset($_POST['activity_date']) ? sanitize_text_field($_POST['activity_date']) : '';
        if (!empty($activity_date_input) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $activity_date_input)) {
            $activity_date = $activity_date_input . ' 12:00:00';
        } else {
            $activity_date = current_time('mysql');
        }

        $folder_ids = get_terms(array(
            'taxonomy' => BG_Folders::TAXONOMY,
            'hide_empty' => false,
            'fields' => 'ids'
        ));

        if (is_wp_error($folder_ids) || empty($folder_ids)) {
            wp_send_json_success(array(
                'count' => 0,
                'message' => __('No gallery folders found to sync.', 'website-updates')
            ));
        }

        $existing_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT object_id FROM $activity_table WHERE activity_type = %s",
                'gallery_folder_created'
            )
        );
        $existing_ids = array_map('intval', $existing_ids);

        // Imports run before BildeGallery 1.2.0 didn't tag their folders
        // with a FileBird ID, so also check the import's ID map
        $imported_ids = array_map('intval', array_values((array) get_option(BG_Migration::OPTION_ID_MAP, array())));

        $synced = 0;
        foreach ($folder_ids as $folder_id) {
            $folder_id = intval($folder_id);
            if (in_array($folder_id, $existing_ids, true) || in_array($folder_id, $imported_ids, true)) {
                continue;
            }

            $folder = BG_Folders::get_folder_info($folder_id);

            // Folders imported from FileBird were never new on this site; any
            // FileBird-era activity for them is already in the log
            if (!$folder || $folder['filebird_id'] > 0) {
                continue;
            }

            $user_id = $folder['created_by'] > 0 ? $folder['created_by'] : get_current_user_id();

            // Folders made before BildeGallery 1.2.0 have no recorded creation
            // date, so fall back to their oldest image
            $first_attachment_date = '';
            $folder_activity_date = $folder['created_at'];
            if ($folder_activity_date === '') {
                $first_attachment_date = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT MIN(p.post_date) FROM {$wpdb->posts} p
                         INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                         INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                         WHERE tt.taxonomy = %s AND tt.term_id = %d",
                        BG_Folders::TAXONOMY,
                        $folder_id
                    )
                );
                $folder_activity_date = !empty($first_attachment_date) ? $first_attachment_date : $activity_date;
            }

            $this->log_gallery_activity('gallery_folder_created', $folder_id, $folder, array(
                'first_attachment_date' => $first_attachment_date,
                'synced' => true
            ), $user_id, $folder_activity_date);

            $synced++;
        }

        wp_send_json_success(array(
            'count' => $synced,
            'message' => sprintf(_n('%s folder synced.', '%s folders synced.', $synced, 'website-updates'), number_format_i18n($synced))
        ));
    }

    /**
     * Get edit stats for a post from its saved revisions (excluding autosaves):
     * total edits, edits within the report period, and who made the last edit
     */
    private function get_edit_stats($post_id, $start_date, $end_date) {
        global $wpdb;

        $autosave_like = '%' . $wpdb->esc_like('autosave') . '%';

        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(post_date BETWEEN %s AND %s), 0) AS period
             FROM {$wpdb->posts}
             WHERE post_parent = %d
             AND post_type = 'revision'
             AND post_name NOT LIKE %s",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
            $post_id,
            $autosave_like
        ));

        // Last editor: latest revision's author, then WordPress's _edit_last meta, then the original author
        $last_editor_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_author FROM {$wpdb->posts}
             WHERE post_parent = %d
             AND post_type = 'revision'
             AND post_name NOT LIKE %s
             ORDER BY post_date DESC, ID DESC
             LIMIT 1",
            $post_id,
            $autosave_like
        ));

        if (empty($last_editor_id)) {
            $last_editor_id = get_post_meta($post_id, '_edit_last', true);
        }
        if (empty($last_editor_id)) {
            $last_editor_id = get_post_field('post_author', $post_id);
        }

        $last_editor = $last_editor_id ? get_the_author_meta('display_name', $last_editor_id) : '';

        return array(
            'total' => $counts ? (int) $counts->total : 0,
            'period' => $counts ? (int) $counts->period : 0,
            'last_editor' => $last_editor ? $last_editor : __('Unknown', 'website-updates')
        );
    }

    /**
     * Get pages data (new and updated)
     */
    private function get_pages_data($start_date, $end_date) {
        $data = array('new' => array(), 'updated' => array());
        
        // New pages
        $new_pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date . ' 23:59:59',
                    'inclusive' => true,
                    'column' => 'post_date'
                )
            )
        ));
        
        foreach ($new_pages as $page) {
            $data['new'][] = array(
                'id' => $page->ID,
                'title' => $page->post_title,
                'date' => $page->post_date,
                'author' => get_the_author_meta('display_name', $page->post_author),
                'url' => get_permalink($page->ID),
                'edits' => $this->get_edit_stats($page->ID, $start_date, $end_date)
            );
        }
        
        // Updated pages (modified but not created in range)
        $updated_pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date . ' 23:59:59',
                    'inclusive' => true,
                    'column' => 'post_modified'
                ),
                array(
                    'before' => $start_date,
                    'column' => 'post_date'
                )
            )
        ));
        
        foreach ($updated_pages as $page) {
            $data['updated'][] = array(
                'id' => $page->ID,
                'title' => $page->post_title,
                'date' => $page->post_date,
                'modified' => $page->post_modified,
                'author' => get_the_author_meta('display_name', $page->post_author),
                'url' => get_permalink($page->ID),
                'edits' => $this->get_edit_stats($page->ID, $start_date, $end_date)
            );
        }
        
        return $data;
    }
    
    /**
     * Get projects data (new and updated) - Divi custom post type
     */
    private function get_projects_data($start_date, $end_date) {
        $data = array('new' => array(), 'updated' => array());
        
        // New projects
        $new_projects = get_posts(array(
            'post_type' => 'project',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date . ' 23:59:59',
                    'inclusive' => true,
                    'column' => 'post_date'
                )
            )
        ));
        
        foreach ($new_projects as $project) {
            // Get project categories
            $project_cats = wp_get_object_terms($project->ID, 'project_category', array('fields' => 'names'));
            
            $data['new'][] = array(
                'id' => $project->ID,
                'title' => $project->post_title,
                'date' => $project->post_date,
                'author' => get_the_author_meta('display_name', $project->post_author),
                'url' => get_permalink($project->ID),
                'edits' => $this->get_edit_stats($project->ID, $start_date, $end_date),
                'categories' => is_array($project_cats) ? $project_cats : array()
            );
        }
        
        // Updated projects
        $updated_projects = get_posts(array(
            'post_type' => 'project',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date . ' 23:59:59',
                    'inclusive' => true,
                    'column' => 'post_modified'
                ),
                array(
                    'before' => $start_date,
                    'column' => 'post_date'
                )
            )
        ));
        
        foreach ($updated_projects as $project) {
            // Get project categories
            $project_cats = wp_get_object_terms($project->ID, 'project_category', array('fields' => 'names'));
            
            $data['updated'][] = array(
                'id' => $project->ID,
                'title' => $project->post_title,
                'date' => $project->post_date,
                'modified' => $project->post_modified,
                'author' => get_the_author_meta('display_name', $project->post_author),
                'url' => get_permalink($project->ID),
                'edits' => $this->get_edit_stats($project->ID, $start_date, $end_date),
                'categories' => is_array($project_cats) ? $project_cats : array()
            );
        }
        
        return $data;
    }
    
    /**
     * Get posts data (new and updated)
     */
    private function get_posts_data($start_date, $end_date) {
        $data = array('new' => array(), 'updated' => array());
        
        // New posts
        $new_posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date . ' 23:59:59',
                    'inclusive' => true,
                    'column' => 'post_date'
                )
            )
        ));
        
        foreach ($new_posts as $post) {
            $data['new'][] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'date' => $post->post_date,
                'author' => get_the_author_meta('display_name', $post->post_author),
                'url' => get_permalink($post->ID),
                'edits' => $this->get_edit_stats($post->ID, $start_date, $end_date),
                'categories' => wp_get_post_categories($post->ID, array('fields' => 'names'))
            );
        }
        
        // Updated posts
        $updated_posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date . ' 23:59:59',
                    'inclusive' => true,
                    'column' => 'post_modified'
                ),
                array(
                    'before' => $start_date,
                    'column' => 'post_date'
                )
            )
        ));
        
        foreach ($updated_posts as $post) {
            $data['updated'][] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'date' => $post->post_date,
                'modified' => $post->post_modified,
                'author' => get_the_author_meta('display_name', $post->post_author),
                'url' => get_permalink($post->ID),
                'edits' => $this->get_edit_stats($post->ID, $start_date, $end_date),
                'categories' => wp_get_post_categories($post->ID, array('fields' => 'names'))
            );
        }
        
        return $data;
    }
    
    /**
     * Get media uploads data
     */
    private function get_media_data($start_date, $end_date) {
        $data = array();
        
        $media_items = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date . ' 23:59:59',
                    'inclusive' => true,
                    'column' => 'post_date'
                )
            )
        ));
        
        foreach ($media_items as $item) {
            $file_path = get_attached_file($item->ID);
            $file_size = file_exists($file_path) ? size_format(filesize($file_path)) : 'N/A';
            
            $data[] = array(
                'id' => $item->ID,
                'title' => $item->post_title,
                'filename' => basename($file_path),
                'type' => $item->post_mime_type,
                'size' => $file_size,
                'date' => $item->post_date,
                'author' => get_the_author_meta('display_name', $item->post_author),
                'url' => wp_get_attachment_url($item->ID)
            );
        }
        
        return $data;
    }
    
    /**
     * Is BildeGallery (1.2.0+, which fires the folder events we log) active?
     */
    private function gallery_plugin_active() {
        return class_exists('BG_Folders') && method_exists('BG_Folders', 'get_folder_info');
    }

    /**
     * Get gallery folder activity data. Includes activity logged under
     * FileBird (filebird_folder_*) so reports for earlier dates still work.
     */
    private function get_gallery_data($start_date, $end_date) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'website_updates_activity';
        $data = array(
            'created' => array(),
            'updated' => array(),
            'deleted' => array(),
            'available' => $this->gallery_plugin_active()
        );

        // Check if table exists, if not create it
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            self::create_activity_table();
        }

        // Get folder activities
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name
            WHERE (activity_type LIKE %s OR activity_type LIKE %s)
            AND activity_date >= %s
            AND activity_date <= %s
            ORDER BY activity_date DESC",
            $wpdb->esc_like('gallery_folder_') . '%',
            $wpdb->esc_like('filebird_folder_') . '%',
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));
        
        foreach ($activities as $activity) {
            $object_data = json_decode($activity->object_data, true);
            $user = get_userdata($activity->user_id);
            
            $item_date = $activity->activity_date;
            if (!empty($object_data['first_attachment_date'])) {
                $item_date = $object_data['first_attachment_date'];
            }

            $item = array(
                'id' => $activity->object_id,
                'name' => $activity->object_name,
                'path' => isset($object_data['folder_path']) ? $object_data['folder_path'] : $activity->object_name,
                'date' => $item_date,
                'author' => $user ? $user->display_name : 'Unknown',
                'change' => $this->describe_gallery_change($object_data)
            );

            $action = preg_replace('/^(gallery|filebird)_folder_/', '', $activity->activity_type);
            if ($action === 'created') {
                $data['created'][] = $item;
            } elseif ($action === 'updated') {
                $data['updated'][] = $item;
            } elseif ($action === 'deleted') {
                $data['deleted'][] = $item;
            }
        }

        return $data;
    }

    /**
     * Describe a gallery folder update, e.g. 'Renamed from "Gala"'.
     * Empty for activity logged under FileBird, which didn't record it.
     */
    private function describe_gallery_change($object_data) {
        $changes = array();

        if (!empty($object_data['renamed']) && !empty($object_data['old_name'])) {
            $changes[] = sprintf(__('Renamed from "%s"', 'website-updates'), $object_data['old_name']);
        }

        if (!empty($object_data['moved']) && !empty($object_data['old_path'])) {
            $changes[] = sprintf(__('Moved from %s', 'website-updates'), $object_data['old_path']);
        }

        return implode('; ', $changes);
    }
    
    /**
     * Get new users data
     */
    private function get_users_data($start_date, $end_date) {
        $data = array();
        
        $users = get_users(array(
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date . ' 23:59:59',
                    'inclusive' => true
                )
            )
        ));
        
        foreach ($users as $user) {
            $data[] = array(
                'id' => $user->ID,
                'username' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'role' => implode(', ', $user->roles),
                'registered' => $user->user_registered
            );
        }
        
        return $data;
    }
    
    /**
     * Get plugin, theme and WordPress core updates logged during the period
     */
    private function get_plugin_updates($start_date, $end_date) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'website_updates_activity';
        $data = array();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return $data;
        }

        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name
            WHERE activity_type IN ('plugin_updated', 'theme_updated', 'core_updated')
            AND activity_date BETWEEN %s AND %s
            ORDER BY activity_date DESC",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        $type_labels = array(
            'plugin' => __('Plugin', 'website-updates'),
            'theme' => __('Theme', 'website-updates'),
            'core' => __('WordPress', 'website-updates')
        );

        foreach ($activities as $activity) {
            $object_data = json_decode($activity->object_data, true);
            $type = isset($object_data['type']) ? $object_data['type'] : 'plugin';
            $user = $activity->user_id ? get_userdata($activity->user_id) : false;

            $data[] = array(
                'name' => $activity->object_name,
                'type' => $type,
                'type_label' => isset($type_labels[$type]) ? $type_labels[$type] : $type,
                'old_version' => isset($object_data['old_version']) ? $object_data['old_version'] : '',
                'new_version' => isset($object_data['new_version']) ? $object_data['new_version'] : '',
                'date' => $activity->activity_date,
                // No user means WordPress ran the update on its own
                'author' => $user ? $user->display_name : __('Automatic update', 'website-updates')
            );
        }

        return $data;
    }
    
    /**
     * Generate HTML report
     */
    private function generate_report_html($data) {
        $start_date = date('F j, Y', strtotime($data['date_range']['start']));
        $end_date = date('F j, Y', strtotime($data['date_range']['end']));
        
        ob_start();
        ?>
        <div class="report-header">
            <h2><?php _e('Website Activity Report', 'website-updates'); ?></h2>
            <p class="report-period">
                <?php printf(__('Period: %s to %s', 'website-updates'), $start_date, $end_date); ?>
            </p>
        </div>
        
        <div class="report-summary">
            <h3><?php _e('Summary', 'website-updates'); ?></h3>
            <div class="summary-grid">
                <?php if (isset($data['summary']['pages'])): ?>
                <div class="summary-item">
                    <div class="summary-icon">📄</div>
                    <div class="summary-content">
                        <h4><?php _e('Pages', 'website-updates'); ?></h4>
                        <p class="summary-stat">
                            <strong><?php echo $data['summary']['pages']['new']; ?></strong> new, 
                            <strong><?php echo $data['summary']['pages']['updated']; ?></strong> updated
                        </p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (isset($data['summary']['projects'])): ?>
                <div class="summary-item">
                    <div class="summary-icon">🏗️</div>
                    <div class="summary-content">
                        <h4><?php _e('Projects', 'website-updates'); ?></h4>
                        <p class="summary-stat">
                            <strong><?php echo $data['summary']['projects']['new']; ?></strong> new, 
                            <strong><?php echo $data['summary']['projects']['updated']; ?></strong> updated
                        </p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (isset($data['summary']['posts'])): ?>
                <div class="summary-item">
                    <div class="summary-icon">📝</div>
                    <div class="summary-content">
                        <h4><?php _e('Posts', 'website-updates'); ?></h4>
                        <p class="summary-stat">
                            <strong><?php echo $data['summary']['posts']['new']; ?></strong> new, 
                            <strong><?php echo $data['summary']['posts']['updated']; ?></strong> updated
                        </p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (isset($data['summary']['media'])): ?>
                <div class="summary-item">
                    <div class="summary-icon">🖼️</div>
                    <div class="summary-content">
                        <h4><?php _e('Media Files', 'website-updates'); ?></h4>
                        <p class="summary-stat"><strong><?php echo $data['summary']['media']; ?></strong> uploaded</p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (isset($data['summary']['gallery'])): ?>
                <div class="summary-item">
                    <div class="summary-icon">📁</div>
                    <div class="summary-content">
                        <h4><?php _e('Gallery Pages', 'website-updates'); ?></h4>
                        <p class="summary-stat">
                            <strong><?php echo $data['summary']['gallery']['created']; ?></strong> created,
                            <strong><?php echo $data['summary']['gallery']['updated']; ?></strong> updated
                        </p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (isset($data['summary']['users'])): ?>
                <div class="summary-item">
                    <div class="summary-icon">👤</div>
                    <div class="summary-content">
                        <h4><?php _e('New Users', 'website-updates'); ?></h4>
                        <p class="summary-stat"><strong><?php echo $data['summary']['users']; ?></strong> added</p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (isset($data['summary']['plugins'])): ?>
                <?php
                $update_type_counts = array('plugin' => 0, 'theme' => 0, 'core' => 0);
                foreach ($data['details']['plugins']['items'] as $update) {
                    if (isset($update_type_counts[$update['type']])) {
                        $update_type_counts[$update['type']]++;
                    }
                }
                ?>
                <div class="summary-item">
                    <div class="summary-icon">🔌</div>
                    <div class="summary-content">
                        <h4><?php _e('Software Updates', 'website-updates'); ?></h4>
                        <p class="summary-stat">
                            <strong><?php echo $update_type_counts['plugin']; ?></strong> plugins,
                            <strong><?php echo $update_type_counts['theme']; ?></strong> themes<?php if ($update_type_counts['core']): ?>,
                            <strong><?php echo $update_type_counts['core']; ?></strong> WordPress<?php endif; ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php // Detailed sections ?>
        <div class="report-details">
            
            <?php if (isset($data['details']['pages']) && isset($data['details']['pages']['included'])): ?>
            <div class="detail-section">
                <h3><?php _e('Pages Activity', 'website-updates'); ?></h3>
                
                <?php if (!empty($data['details']['pages']['new'])): ?>
                <?php $new_pages_count = count($data['details']['pages']['new']); ?>
                <h4><?php _e('New Pages', 'website-updates'); ?> (<?php printf(_n('%s new page', '%s new pages', $new_pages_count, 'website-updates'), number_format_i18n($new_pages_count)); ?>)</h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Title', 'website-updates'); ?></th>
                            <th><?php _e('Author', 'website-updates'); ?></th>
                            <th><?php _e('Date Created', 'website-updates'); ?></th>
                            <th><?php _e('Last Edited By', 'website-updates'); ?></th>
                            <th><?php _e('Edits', 'website-updates'); ?></th>
                            <th><?php _e('Actions', 'website-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['details']['pages']['new'] as $page): ?>
                        <tr>
                            <td><strong><?php echo esc_html($page['title']); ?></strong></td>
                            <td><?php echo esc_html($page['author']); ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($page['date'])); ?></td>
                            <td><?php echo esc_html($page['edits']['last_editor']); ?></td>
                            <td>
                                <?php printf(esc_html__('%s this period', 'website-updates'), '<strong>' . number_format_i18n($page['edits']['period']) . '</strong>'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php printf(esc_html__('%s total', 'website-updates'), number_format_i18n($page['edits']['total'])); ?></span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($page['url']); ?>" target="_blank" class="button button-small">
                                    <?php _e('View', 'website-updates'); ?>
                                </a>
                                <a href="<?php echo get_edit_post_link($page['id']); ?>" class="button button-small">
                                    <?php _e('Edit', 'website-updates'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($data['details']['pages']['updated'])): ?>
                <?php $updated_pages_count = count($data['details']['pages']['updated']); ?>
                <h4><?php _e('Updated Pages', 'website-updates'); ?> (<?php printf(_n('%s updated page', '%s updated pages', $updated_pages_count, 'website-updates'), number_format_i18n($updated_pages_count)); ?>)</h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Title', 'website-updates'); ?></th>
                            <th><?php _e('Author', 'website-updates'); ?></th>
                            <th><?php _e('Last Modified', 'website-updates'); ?></th>
                            <th><?php _e('Last Edited By', 'website-updates'); ?></th>
                            <th><?php _e('Edits', 'website-updates'); ?></th>
                            <th><?php _e('Actions', 'website-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['details']['pages']['updated'] as $page): ?>
                        <tr>
                            <td><strong><?php echo esc_html($page['title']); ?></strong></td>
                            <td><?php echo esc_html($page['author']); ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($page['modified'])); ?></td>
                            <td><?php echo esc_html($page['edits']['last_editor']); ?></td>
                            <td>
                                <?php printf(esc_html__('%s this period', 'website-updates'), '<strong>' . number_format_i18n($page['edits']['period']) . '</strong>'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php printf(esc_html__('%s total', 'website-updates'), number_format_i18n($page['edits']['total'])); ?></span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($page['url']); ?>" target="_blank" class="button button-small">
                                    <?php _e('View', 'website-updates'); ?>
                                </a>
                                <a href="<?php echo get_edit_post_link($page['id']); ?>" class="button button-small">
                                    <?php _e('Edit', 'website-updates'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($data['details']['gallery']) && isset($data['details']['gallery']['included'])): ?>
            <div class="detail-section">
                <h3><?php _e('Gallery Pages', 'website-updates'); ?></h3>

                <?php if (empty($data['details']['gallery']['available'])): ?>
                <p style="padding: 15px; background: #fcf9e8; border-left: 4px solid #dba617; margin: 10px 0;">
                    <?php _e('BildeGallery 1.2.0 or higher is not active, so new gallery activity is not being tracked.', 'website-updates'); ?>
                </p>
                <?php endif; ?>

                <?php if (!empty($data['details']['gallery']['created'])): ?>
                <?php $new_gallery_count = count($data['details']['gallery']['created']); ?>
                <h4><?php _e('New Gallery Pages', 'website-updates'); ?> (<?php printf(_n('%s new gallery page', '%s new gallery pages', $new_gallery_count, 'website-updates'), number_format_i18n($new_gallery_count)); ?>)</h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Folder Name', 'website-updates'); ?></th>
                            <th><?php _e('Full Path', 'website-updates'); ?></th>
                            <th><?php _e('Created By', 'website-updates'); ?></th>
                            <th><?php _e('Date Created', 'website-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['details']['gallery']['created'] as $folder): ?>
                        <tr>
                            <td><strong><?php echo esc_html($folder['name']); ?></strong></td>
                            <td><?php echo esc_html($folder['path']); ?></td>
                            <td><?php echo esc_html($folder['author']); ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($folder['date'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($data['details']['gallery']['updated'])): ?>
                <?php $updated_gallery_count = count($data['details']['gallery']['updated']); ?>
                <h4><?php _e('Gallery Pages Updated', 'website-updates'); ?> (<?php printf(_n('%s updated gallery page', '%s updated gallery pages', $updated_gallery_count, 'website-updates'), number_format_i18n($updated_gallery_count)); ?>)</h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Folder Name', 'website-updates'); ?></th>
                            <th><?php _e('Full Path', 'website-updates'); ?></th>
                            <th><?php _e('Change', 'website-updates'); ?></th>
                            <th><?php _e('Updated By', 'website-updates'); ?></th>
                            <th><?php _e('Date Updated', 'website-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['details']['gallery']['updated'] as $folder): ?>
                        <tr>
                            <td><strong><?php echo esc_html($folder['name']); ?></strong></td>
                            <td><?php echo esc_html($folder['path']); ?></td>
                            <td><?php echo $folder['change'] !== '' ? esc_html($folder['change']) : '&mdash;'; ?></td>
                            <td><?php echo esc_html($folder['author']); ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($folder['date'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($data['details']['gallery']['deleted'])): ?>
                <?php $deleted_gallery_count = count($data['details']['gallery']['deleted']); ?>
                <h4><?php _e('Gallery Pages Deleted', 'website-updates'); ?> (<?php printf(_n('%s deleted gallery page', '%s deleted gallery pages', $deleted_gallery_count, 'website-updates'), number_format_i18n($deleted_gallery_count)); ?>)</h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Folder Name', 'website-updates'); ?></th>
                            <th><?php _e('Full Path', 'website-updates'); ?></th>
                            <th><?php _e('Deleted By', 'website-updates'); ?></th>
                            <th><?php _e('Date Deleted', 'website-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['details']['gallery']['deleted'] as $folder): ?>
                        <tr>
                            <td><strong><?php echo esc_html($folder['name']); ?></strong></td>
                            <td><?php echo esc_html($folder['path']); ?></td>
                            <td><?php echo esc_html($folder['author']); ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($folder['date'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <?php 
                // Show "no activity" message only if ALL subsections are empty
                if (empty($data['details']['gallery']['created']) && 
                    empty($data['details']['gallery']['updated']) && 
                    empty($data['details']['gallery']['deleted'])): 
                ?>
                <p style="padding: 15px; background: #f6f7f7; border-left: 4px solid #72aee6; margin: 10px 0;">
                    <?php _e('No gallery page activity during this period.', 'website-updates'); ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($data['details']['projects']) && isset($data['details']['projects']['included'])): ?>
            <div class="detail-section">
                <h3><?php _e('Projects Activity', 'website-updates'); ?></h3>
                
                <?php if (!empty($data['details']['projects']['new'])): ?>
                <?php $new_projects_count = count($data['details']['projects']['new']); ?>
                <h4><?php _e('New Projects', 'website-updates'); ?> (<?php printf(_n('%s new project', '%s new projects', $new_projects_count, 'website-updates'), number_format_i18n($new_projects_count)); ?>)</h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Title', 'website-updates'); ?></th>
                            <th><?php _e('Categories', 'website-updates'); ?></th>
                            <th><?php _e('Author', 'website-updates'); ?></th>
                            <th><?php _e('Date Created', 'website-updates'); ?></th>
                            <th><?php _e('Last Edited By', 'website-updates'); ?></th>
                            <th><?php _e('Edits', 'website-updates'); ?></th>
                            <th><?php _e('Actions', 'website-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['details']['projects']['new'] as $project): ?>
                        <tr>
                            <td><strong><?php echo esc_html($project['title']); ?></strong></td>
                            <td><?php echo esc_html(implode(', ', $project['categories'])); ?></td>
                            <td><?php echo esc_html($project['author']); ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($project['date'])); ?></td>
                            <td><?php echo esc_html($project['edits']['last_editor']); ?></td>
                            <td>
                                <?php printf(esc_html__('%s this period', 'website-updates'), '<strong>' . number_format_i18n($project['edits']['period']) . '</strong>'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php printf(esc_html__('%s total', 'website-updates'), number_format_i18n($project['edits']['total'])); ?></span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($project['url']); ?>" target="_blank" class="button button-small">
                                    <?php _e('View', 'website-updates'); ?>
                                </a>
                                <a href="<?php echo get_edit_post_link($project['id']); ?>" class="button button-small">
                                    <?php _e('Edit', 'website-updates'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($data['details']['projects']['updated'])): ?>
                <?php $updated_projects_count = count($data['details']['projects']['updated']); ?>
                <h4><?php _e('Updated Projects', 'website-updates'); ?> (<?php printf(_n('%s updated project', '%s updated projects', $updated_projects_count, 'website-updates'), number_format_i18n($updated_projects_count)); ?>)</h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Title', 'website-updates'); ?></th>
                            <th><?php _e('Categories', 'website-updates'); ?></th>
                            <th><?php _e('Author', 'website-updates'); ?></th>
                            <th><?php _e('Last Modified', 'website-updates'); ?></th>
                            <th><?php _e('Last Edited By', 'website-updates'); ?></th>
                            <th><?php _e('Edits', 'website-updates'); ?></th>
                            <th><?php _e('Actions', 'website-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['details']['projects']['updated'] as $project): ?>
                        <tr>
                            <td><strong><?php echo esc_html($project['title']); ?></strong></td>
                            <td><?php echo esc_html(implode(', ', $project['categories'])); ?></td>
                            <td><?php echo esc_html($project['author']); ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($project['modified'])); ?></td>
                            <td><?php echo esc_html($project['edits']['last_editor']); ?></td>
                            <td>
                                <?php printf(esc_html__('%s this period', 'website-updates'), '<strong>' . number_format_i18n($project['edits']['period']) . '</strong>'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php printf(esc_html__('%s total', 'website-updates'), number_format_i18n($project['edits']['total'])); ?></span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($project['url']); ?>" target="_blank" class="button button-small">
                                    <?php _e('View', 'website-updates'); ?>
                                </a>
                                <a href="<?php echo get_edit_post_link($project['id']); ?>" class="button button-small">
                                    <?php _e('Edit', 'website-updates'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($data['details']['posts']) && isset($data['details']['posts']['included'])): ?>
            <div class="detail-section">
                <h3><?php _e('Posts Activity', 'website-updates'); ?></h3>
                
                <?php if (!empty($data['details']['posts']['new'])): ?>
                <?php $new_posts_count = count($data['details']['posts']['new']); ?>
                <h4><?php _e('New Posts', 'website-updates'); ?> (<?php printf(_n('%s new post', '%s new posts', $new_posts_count, 'website-updates'), number_format_i18n($new_posts_count)); ?>)</h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Title', 'website-updates'); ?></th>
                            <th><?php _e('Categories', 'website-updates'); ?></th>
                            <th><?php _e('Author', 'website-updates'); ?></th>
                            <th><?php _e('Date Published', 'website-updates'); ?></th>
                            <th><?php _e('Last Edited By', 'website-updates'); ?></th>
                            <th><?php _e('Edits', 'website-updates'); ?></th>
                            <th><?php _e('Actions', 'website-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['details']['posts']['new'] as $post): ?>
                        <tr>
                            <td><strong><?php echo esc_html($post['title']); ?></strong></td>
                            <td><?php echo esc_html(implode(', ', $post['categories'])); ?></td>
                            <td><?php echo esc_html($post['author']); ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($post['date'])); ?></td>
                            <td><?php echo esc_html($post['edits']['last_editor']); ?></td>
                            <td>
                                <?php printf(esc_html__('%s this period', 'website-updates'), '<strong>' . number_format_i18n($post['edits']['period']) . '</strong>'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php printf(esc_html__('%s total', 'website-updates'), number_format_i18n($post['edits']['total'])); ?></span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($post['url']); ?>" target="_blank" class="button button-small">
                                    <?php _e('View', 'website-updates'); ?>
                                </a>
                                <a href="<?php echo get_edit_post_link($post['id']); ?>" class="button button-small">
                                    <?php _e('Edit', 'website-updates'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($data['details']['posts']['updated'])): ?>
                <?php $updated_posts_count = count($data['details']['posts']['updated']); ?>
                <h4><?php _e('Updated Posts', 'website-updates'); ?> (<?php printf(_n('%s updated post', '%s updated posts', $updated_posts_count, 'website-updates'), number_format_i18n($updated_posts_count)); ?>)</h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Title', 'website-updates'); ?></th>
                            <th><?php _e('Categories', 'website-updates'); ?></th>
                            <th><?php _e('Author', 'website-updates'); ?></th>
                            <th><?php _e('Last Modified', 'website-updates'); ?></th>
                            <th><?php _e('Last Edited By', 'website-updates'); ?></th>
                            <th><?php _e('Edits', 'website-updates'); ?></th>
                            <th><?php _e('Actions', 'website-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['details']['posts']['updated'] as $post): ?>
                        <tr>
                            <td><strong><?php echo esc_html($post['title']); ?></strong></td>
                            <td><?php echo esc_html(implode(', ', $post['categories'])); ?></td>
                            <td><?php echo esc_html($post['author']); ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($post['modified'])); ?></td>
                            <td><?php echo esc_html($post['edits']['last_editor']); ?></td>
                            <td>
                                <?php printf(esc_html__('%s this period', 'website-updates'), '<strong>' . number_format_i18n($post['edits']['period']) . '</strong>'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php printf(esc_html__('%s total', 'website-updates'), number_format_i18n($post['edits']['total'])); ?></span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($post['url']); ?>" target="_blank" class="button button-small">
                                    <?php _e('View', 'website-updates'); ?>
                                </a>
                                <a href="<?php echo get_edit_post_link($post['id']); ?>" class="button button-small">
                                    <?php _e('Edit', 'website-updates'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($data['details']['media']) && isset($data['details']['media']['included'])): ?>
            <div class="detail-section">
                <?php
                // Calculate totals
                global $wpdb;
                $total_media = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
                $media_items = isset($data['details']['media']['items']) ? $data['details']['media']['items'] : array();
                $added_count = count($media_items);
                
                // Calculate total size of uploaded media in this period
                $total_size = 0;
                foreach ($media_items as $media) {
                    // Convert size back to bytes for calculation
                    $size_str = $media['size'];
                    if ($size_str !== 'N/A') {
                        // This is approximate - you could store actual bytes if needed
                        if (strpos($size_str, 'MB') !== false) {
                            $total_size += floatval($size_str) * 1024 * 1024;
                        } elseif (strpos($size_str, 'KB') !== false) {
                            $total_size += floatval($size_str) * 1024;
                        } elseif (strpos($size_str, 'GB') !== false) {
                            $total_size += floatval($size_str) * 1024 * 1024 * 1024;
                        }
                    }
                }
                $total_size_formatted = size_format($total_size);
                ?>
                <h3><?php _e('Media Library Statistics', 'website-updates'); ?> (<?php printf(_n('%s file uploaded', '%s files uploaded', $added_count, 'website-updates'), number_format_i18n($added_count)); ?>)</h3>
                
                <div style="background: #f6f7f7; padding: 20px; border-radius: 4px; margin-bottom: 20px;">
                    <table class="wp-list-table widefat" style="background: white;">
                        <tbody>
                            <tr>
                                <td style="width: 40%; padding: 15px;"><strong><?php _e('Total Media Files in Library:', 'website-updates'); ?></strong></td>
                                <td style="padding: 15px; font-size: 16px;"><strong><?php echo number_format($total_media); ?></strong> files</td>
                            </tr>
                            <tr class="alternate">
                                <td style="padding: 15px;"><strong><?php _e('Files Uploaded in This Period:', 'website-updates'); ?></strong></td>
                                <td style="padding: 15px; font-size: 16px; color: #2271b1;"><strong><?php echo number_format($added_count); ?></strong> files</td>
                            </tr>
                            <tr>
                                <td style="padding: 15px;"><strong><?php _e('Total Size Uploaded:', 'website-updates'); ?></strong></td>
                                <td style="padding: 15px; font-size: 16px;"><?php echo $total_size_formatted; ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($data['details']['users']) && isset($data['details']['users']['included'])): ?>
            <div class="detail-section">
                <?php $new_users_count = count($data['details']['users']['items']); ?>
                <h3><?php _e('New Users', 'website-updates'); ?> (<?php printf(_n('%s new user', '%s new users', $new_users_count, 'website-updates'), number_format_i18n($new_users_count)); ?>)</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Username', 'website-updates'); ?></th>
                            <th><?php _e('Display Name', 'website-updates'); ?></th>
                            <th><?php _e('Email', 'website-updates'); ?></th>
                            <th><?php _e('Role', 'website-updates'); ?></th>
                            <th><?php _e('Registered', 'website-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['details']['users']['items'] as $user): ?>
                        <tr>
                            <td><strong><?php echo esc_html($user['username']); ?></strong></td>
                            <td><?php echo esc_html($user['display_name']); ?></td>
                            <td><?php echo esc_html($user['email']); ?></td>
                            <td><?php echo esc_html($user['role']); ?></td>
                            <td><?php echo date('M j, Y', strtotime($user['registered'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (isset($data['details']['plugins']) && isset($data['details']['plugins']['included'])): ?>
            <div class="detail-section">
                <?php $software_updates_count = count($data['details']['plugins']['items']); ?>
                <h3><?php _e('Software Updates', 'website-updates'); ?> (<?php printf(_n('%s update', '%s updates', $software_updates_count, 'website-updates'), number_format_i18n($software_updates_count)); ?>)</h3>
                <?php if (!empty($data['details']['plugins']['items'])): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Name', 'website-updates'); ?></th>
                            <th><?php _e('Type', 'website-updates'); ?></th>
                            <th><?php _e('Version', 'website-updates'); ?></th>
                            <th><?php _e('Updated By', 'website-updates'); ?></th>
                            <th><?php _e('Date Updated', 'website-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['details']['plugins']['items'] as $update): ?>
                        <tr>
                            <td><strong><?php echo esc_html($update['name']); ?></strong></td>
                            <td><?php echo esc_html($update['type_label']); ?></td>
                            <td>
                                <?php if ($update['old_version'] !== ''): ?>
                                <?php echo esc_html($update['old_version']); ?> &rarr; <strong><?php echo esc_html($update['new_version']); ?></strong>
                                <?php else: ?>
                                <strong><?php echo esc_html($update['new_version']); ?></strong>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($update['author']); ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($update['date'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="padding: 15px; background: #f6f7f7; border-left: 4px solid #72aee6; margin: 10px 0;">
                    <?php _e('No plugin, theme or WordPress updates were recorded during this period. Updates are recorded from version 1.0.8 onward.', 'website-updates'); ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
}

// Initialize the plugin
function website_updates_report_init() {
    Website_Updates_Report::get_instance();
}
add_action('plugins_loaded', 'website_updates_report_init');

// Activation hook to create database table
register_activation_hook(__FILE__, array('Website_Updates_Report', 'create_activity_table'));

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('website_updates_send_scheduled_report');
});