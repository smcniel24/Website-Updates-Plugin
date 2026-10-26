<?php
/**
 * Updates the plugin from GitHub releases of a public repo. No site setup needed.
 *
 * Optional: if the repo is ever made private, define a read-only token in wp-config.php:
 *     define('WEBSITE_UPDATES_GITHUB_TOKEN', 'github_pat_...');
 *
 * Publishing an update: bump the plugin version, push, then publish a GitHub
 * release whose tag is the version (e.g. "v1.0.9" or "1.0.9").
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Website_Updates_GitHub_Updater {

    const CACHE_KEY = 'website_updates_github_release';
    const CACHE_TTL = 3600;             // 1 hour
    const ERROR_CACHE_TTL = 900;        // 15 minutes

    private $plugin_file;   // e.g. website-updates/website-updates.php
    private $slug;          // e.g. website-updates
    private $repo;          // e.g. owner/repo
    private $version;

    public function __construct($plugin_file, $repo, $version) {
        $this->plugin_file = plugin_basename($plugin_file);
        $this->slug = dirname($this->plugin_file);
        $this->repo = $repo;
        $this->version = $version;

        // Fires for plugins whose "Update URI" header points at github.com (WordPress 5.8+)
        add_filter('update_plugins_github.com', array($this, 'check_for_update'), 10, 3);
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_pre_download', array($this, 'download_package'), 10, 4);
        add_filter('upgrader_source_selection', array($this, 'fix_source_folder'), 10, 4);
        add_action('upgrader_process_complete', array($this, 'clear_cache_after_update'), 10, 2);
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
    }

    /**
     * Tell WordPress about a newer release on GitHub
     */
    public function check_for_update($update, $plugin_data, $plugin_file) {
        if ($plugin_file !== $this->plugin_file) {
            return $update;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $update;
        }

        return array(
            'id' => 'github.com/' . $this->repo,
            'slug' => $this->slug,
            'plugin' => $this->plugin_file,
            'version' => $release['version'],
            'url' => $release['html_url'],
            'package' => $release['package'],
        );
    }

    /**
     * Fill in the "View details" popup on the Plugins and Updates screens
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $result;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->plugin_file, false, false);

        return (object) array(
            'name' => $plugin_data['Name'],
            'slug' => $this->slug,
            'version' => $release['version'],
            'author' => $plugin_data['Author'],
            'homepage' => $plugin_data['PluginURI'],
            'last_updated' => $release['published_at'],
            'download_link' => $release['package'],
            'sections' => array(
                'changelog' => $this->markdown_to_html($release['body']),
            ),
        );
    }

    /**
     * Download private release files ourselves: GitHub needs the token, but the
     * redirect target (signed S3/codeload URL) rejects requests that still carry it
     */
    public function download_package($reply, $package, $upgrader, $hook_extra = array()) {
        if (strpos($package, 'https://api.github.com/repos/' . $this->repo . '/') !== 0) {
            return $reply;
        }

        $headers = $this->api_headers();
        if (strpos($package, '/releases/assets/') !== false) {
            $headers['Accept'] = 'application/octet-stream';
        }

        $response = wp_remote_get($package, array(
            'headers' => $headers,
            'redirection' => 0,
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 300 && $code < 400) {
            $location = wp_remote_retrieve_header($response, 'location');
            if (empty($location)) {
                return new WP_Error('website_updates_download', __('GitHub did not return a download location.', 'website-updates'));
            }
            return download_url($location, 300);
        }

        if ($code === 200) {
            $tmp = wp_tempnam($package);
            if (!$tmp || false === file_put_contents($tmp, wp_remote_retrieve_body($response))) {
                return new WP_Error('website_updates_download', __('Could not save the update file.', 'website-updates'));
            }
            return $tmp;
        }

        return new WP_Error('website_updates_download', $this->error_message($code));
    }

    /**
     * GitHub zips unpack to "owner-repo-hash/"; rename to the plugin's folder so it replaces itself
     */
    public function fix_source_folder($source, $remote_source, $upgrader, $hook_extra = array()) {
        global $wp_filesystem;

        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $source;
        }

        $desired = trailingslashit($remote_source) . $this->slug . '/';
        if (trailingslashit($source) === $desired) {
            return $source;
        }

        if (!$wp_filesystem->move($source, $desired, true)) {
            return new WP_Error('website_updates_rename', __('Could not rename the update folder.', 'website-updates'));
        }

        return $desired;
    }

    public function clear_cache_after_update($upgrader, $hook_extra) {
        if (!empty($hook_extra['type']) && $hook_extra['type'] === 'plugin') {
            delete_site_transient(self::CACHE_KEY);
        }
    }

    /**
     * Show the last update-check error, if any, under the plugin on the Plugins screen
     */
    public function plugin_row_meta($links, $file) {
        if ($file !== $this->plugin_file) {
            return $links;
        }

        $error = get_site_transient(self::CACHE_KEY);
        if (is_array($error) && !empty($error['error'])) {
            $links[] = '<span style="color: #b32d2e;">' . esc_html($error['error']) . '</span>';
        }

        return $links;
    }

    /**
     * Latest published release (cached). Returns null if unavailable.
     */
    private function get_latest_release() {
        // "Check again" on Dashboard > Updates bypasses the cache
        $force = is_admin() && !empty($_GET['force-check']);

        $cached = get_site_transient(self::CACHE_KEY);
        if (!$force && is_array($cached)) {
            return empty($cached['error']) ? $cached : null;
        }

        $response = wp_remote_get('https://api.github.com/repos/' . $this->repo . '/releases/latest', array(
            'headers' => $this->api_headers(),
            'timeout' => 15,
        ));

        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        $body = $code === 200 ? json_decode(wp_remote_retrieve_body($response), true) : null;

        if (!is_array($body) || empty($body['tag_name'])) {
            $message = is_wp_error($response) ? $response->get_error_message() : $this->error_message($code);
            set_site_transient(self::CACHE_KEY, array('error' => $message), self::ERROR_CACHE_TTL);
            return null;
        }

        // Prefer an attached .zip asset; otherwise use GitHub's source zip for the tag
        $package = $body['zipball_url'];
        if (!empty($body['assets'])) {
            foreach ($body['assets'] as $asset) {
                if (substr(strtolower($asset['name']), -4) === '.zip') {
                    $package = $asset['url'];
                    break;
                }
            }
        }

        $release = array(
            'version' => ltrim($body['tag_name'], 'vV'),
            'html_url' => $body['html_url'],
            'published_at' => $body['published_at'],
            'body' => isset($body['body']) ? $body['body'] : '',
            'package' => $package,
        );

        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    private function get_token() {
        return defined('WEBSITE_UPDATES_GITHUB_TOKEN') ? WEBSITE_UPDATES_GITHUB_TOKEN : '';
    }

    private function api_headers() {
        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        );
        if ($this->get_token()) {
            $headers['Authorization'] = 'Bearer ' . $this->get_token();
        }
        return $headers;
    }

    private function error_message($code) {
        if ($code === 401) {
            return __('Update check failed: the GitHub token is invalid or expired.', 'website-updates');
        }
        if ($code === 404) {
            return __('Update check failed: the GitHub repo was not found, is private, or has no published release yet.', 'website-updates');
        }
        if ($code === 403 || $code === 429) {
            return __('Update check failed: GitHub is temporarily limiting requests. It will retry automatically.', 'website-updates');
        }
        /* translators: %s: HTTP status code */
        return sprintf(__('Update check failed: GitHub returned HTTP %s.', 'website-updates'), $code ? $code : '—');
    }

    /**
     * Minimal Markdown (headings, bullet lists, bold, paragraphs) for release notes
     */
    private function markdown_to_html($markdown) {
        if (trim($markdown) === '') {
            return '<p>' . esc_html__('No release notes provided.', 'website-updates') . '</p>';
        }

        $html = '';
        $in_list = false;

        foreach (preg_split('/\r\n|\r|\n/', $markdown) as $line) {
            $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', esc_html(trim($line)));

            if (preg_match('/^\s*[-*]\s+/', $line)) {
                if (!$in_list) {
                    $html .= '<ul>';
                    $in_list = true;
                }
                $html .= '<li>' . preg_replace('/^[-*]\s+/', '', $text) . '</li>';
                continue;
            }

            if ($in_list) {
                $html .= '</ul>';
                $in_list = false;
            }

            if (preg_match('/^(#{1,6})\s+/', $text, $m)) {
                $html .= '<h4>' . substr($text, strlen($m[0])) . '</h4>';
            } elseif ($text !== '') {
                $html .= '<p>' . $text . '</p>';
            }
        }

        if ($in_list) {
            $html .= '</ul>';
        }

        return $html;
    }
}
