<?php
/**
 * Plugin Name: Surflab Link Manager
 * Description: Advanced link management toolkit for WordPress with bulk operations and SEO features
 * Version: 1.3.1
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

// --- Class Definitions (Order Matters: Define classes before they are instantiated) ---

/**
 * Handles logging and debug information
 */
class Surflab_Logger {
    private $debug_mode;

    /**
     * @param bool $debug_mode Whether debug logging is enabled
     */
    public function __construct($debug_mode = false) { // Default to false
        $this->debug_mode = $debug_mode;
    }

    /**
     * Log message to WordPress debug log
     * @param string $message Message to log
     */
    public function log($message) {
        // Ensure WP_DEBUG_LOG is enabled in wp-config.php for this to work
        if ($this->debug_mode && defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[Surflab Debug] ' . print_r($message, true)); // Use print_r for arrays/objects
        }
    }

    /**
     * Check if debug mode is enabled
     * @return bool Debug mode status
     */
    public function is_debug_enabled() {
        return $this->debug_mode;
    }
}

/**
 * Manages plugin settings and sanitization
 */
class Surflab_Settings {
    private $options;
    const OPTION_NAME = 'surflab_link_settings';

    public function __construct() {
        $this->load_settings();
    }

    /**
     * Load settings from WordPress options
     */
    private function load_settings() {
        $this->options = get_option(self::OPTION_NAME, $this->get_defaults());
    }

    /**
     * Get default settings values
     * @return array Default settings array
     */
    public function get_defaults() {
        return [
            'external_nofollow' => true,
            'external_target_blank' => true,
            'internal_nofollow' => false,
            'internal_target_blank' => false,
            'external_sponsored' => false,
            'external_ugc' => false,
            'external_noreferrer' => true,
            'external_noopener' => true
        ];
    }

    /**
     * Get single setting value
     * @param string $key Setting name
     * @return mixed|null Setting value or null if not found
     */
    public function get($key) {
        return $this->options[$key] ?? null;
    }

    /**
     * Get all settings
     * @return array All current settings
     */
    public function get_all() {
        return $this->options;
    }

    /**
     * Save settings to database
     * @param array $settings New settings values
     */
    public function save(array $settings) {
        $sanitized = $this->sanitize($settings);
        update_option(self::OPTION_NAME, $sanitized);
        $this->options = $sanitized; // Update internal state
    }

    /**
     * Sanitize settings input - ensures boolean values
     * @param array $input Raw settings input
     * @return array Sanitized settings
     */
    public function sanitize(array $input) {
        $defaults = $this->get_defaults();
        $sanitized = [];
        foreach (array_keys($defaults) as $key) {
            // Check if the key exists and is truthy (e.g., '1', true, 'on')
            $sanitized[$key] = !empty($input[$key]);
        }
        return $sanitized;
    }
}

/**
 * Modifies links in content based on settings
 */
class Surflab_Link_Modifier {
    private $settings; // Instance of Surflab_Settings
    private $logger;   // Instance of Surflab_Logger

    /**
     * @param Surflab_Settings $settings Settings instance
     * @param Surflab_Logger $logger Logger instance
     */
    public function __construct(Surflab_Settings $settings, Surflab_Logger $logger) {
        $this->settings = $settings;
        $this->logger = $logger;
        // Only apply content filter on the frontend
        if (!is_admin()) {
            add_filter('the_content', [$this, 'modify_content_links']);
        }
    }

    /**
     * Main content modification filter callback
     * @param string $content Post content
     * @return string Modified content
     */
    public function modify_content_links($content) {
        // Don't modify content in feeds or REST API requests unless specifically desired
        if (is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $content;
        }
        if (empty($content)) {
            return $content;
        }

        $this->logger->log("Running modify_content_links filter.");
        $dom = new DOMDocument();
        // Suppress errors for invalid HTML and use UTF-8
        libxml_use_internal_errors(true);
        // Need to wrap content to handle fragments properly
        if (!@$dom->loadHTML('<?xml encoding="UTF-8"><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
             $this->logger->log("Failed to load HTML content into DOMDocument.");
             libxml_clear_errors();
             return $content; // Return original content on failure
        }
        libxml_clear_errors();

        $links = $dom->getElementsByTagName('a');
        $modified = false;
        if ($links && $links->length > 0) {
             $this->logger->log("Found {$links->length} links to process.");
            foreach ($links as $link) {
                if ($this->modify_single_link($link)) {
                    $modified = true;
                }
            }
        } else {
             $this->logger->log("No links found in content.");
        }

        if ($modified) {
             $this->logger->log("Content modified, saving HTML.");
             // Extract content from the wrapper div's first child (the div we added)
             $wrapper_div = $dom->getElementsByTagName('div')->item(0);
             $inner_html = '';
             if ($wrapper_div) {
                 foreach ($wrapper_div->childNodes as $node) {
                     $inner_html .= $dom->saveHTML($node);
                 }
             }

             // Fallback if extraction fails (shouldn't happen with the wrapper)
             if (empty($inner_html)) {
                 $inner_html = $dom->saveHTML();
                 // Attempt to remove doctype, html, body tags if saveHTML added them
                 $inner_html = preg_replace('~<(?:!DOCTYPE|/?(?:html|body))[^>]*>\s*~i', '', $inner_html);
             }

             return $inner_html;
        } else {
             $this->logger->log("No modifications made to links.");
             return $content; // Return original content if no changes
        }
    }

    /**
     * Modify individual link attributes
     * @param DOMElement $link Link element to modify
     * @return bool True if the link was modified, false otherwise
     */
    private function modify_single_link(DOMElement $link) {
        $href = $link->getAttribute('href');
        // Skip links without href or only an anchor or non-web protocols
        if (empty($href) || strpos($href, '#') === 0 || preg_match('/^(mailto|tel|javascript|file):/i', $href)) {
            return false;
        }

        $original_rel = $link->getAttribute('rel');
        $original_target = $link->getAttribute('target');
        $modified = false;

        $is_external = $this->is_external($href);
        $this->set_link_attributes($link, $is_external);

        // Check if attributes actually changed
        if ($link->getAttribute('rel') !== $original_rel || $link->getAttribute('target') !== $original_target) {
            $modified = true;
             $this->logger->log("Modified link '{$href}'. New rel: '{$link->getAttribute('rel')}', New target: '{$link->getAttribute('target')}'");
        }

        return $modified;
    }

    /**
     * Set link attributes based on type and settings
     * @param DOMElement $link Link element
     * @param bool $is_external Whether link is external
     */
    private function set_link_attributes(DOMElement $link, $is_external) {
        $settings = $this->settings->get_all();
        $rel_parts = [];

        // Get existing rel attributes, split by space, filter empty
        $existing_rel_str = $link->getAttribute('rel');
        $rel_parts = array_filter(explode(' ', $existing_rel_str));

        if ($is_external) {
            if ($settings['external_target_blank'] && !$link->hasAttribute('target')) {
                $link->setAttribute('target', '_blank');
            }
            // If target is _blank, ensure noopener is present for security
            if ($link->getAttribute('target') === '_blank') {
                 $rel_parts[] = 'noopener';
            }

            if ($settings['external_nofollow']) $rel_parts[] = 'nofollow';
            if ($settings['external_sponsored']) $rel_parts[] = 'sponsored';
            if ($settings['external_ugc']) $rel_parts[] = 'ugc';
            if ($settings['external_noreferrer']) $rel_parts[] = 'noreferrer';
            // Explicitly add noopener if setting is checked (might be redundant if target=_blank added it)
            if ($settings['external_noopener']) $rel_parts[] = 'noopener';

        } else { // Internal links
            if ($settings['internal_target_blank'] && !$link->hasAttribute('target')) {
                $link->setAttribute('target', '_blank');
                 // Add noopener if internal link opens in new tab? Optional, but good practice.
                 $rel_parts[] = 'noopener';
            }
            if ($settings['internal_nofollow']) $rel_parts[] = 'nofollow';
        }

        // Clean up and set the final rel attribute
        $rel_parts = array_unique(array_filter($rel_parts)); // Remove duplicates and empty values
        if (!empty($rel_parts)) {
            sort($rel_parts); // Ensure consistent order
            $link->setAttribute('rel', implode(' ', $rel_parts));
        } else {
            // Remove rel attribute if it becomes empty
            $link->removeAttribute('rel');
        }
    }

    /**
     * Check if URL is external (Public for use by other classes)
     * @param string $url URL to check
     * @return bool True if external
     */
    public function is_external($url) {
        if (empty($url)) return false;

        // Treat protocol-relative URLs starting with // as external unless they match the host
        if (strpos($url, '//') === 0 && strpos($url, '/'.'/') !== 0) { // Avoid matching ///
            $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https:' : 'http:') . $url;
        }

        $home_host = parse_url(home_url(), PHP_URL_HOST);
        if (!$home_host) return false; // Cannot determine home host

        // Remove www. from home host for comparison
        $home_host = strtolower(preg_replace('/^www\./i', '', $home_host));

        $link_parts = parse_url($url);
        $link_host = $link_parts['host'] ?? null;

        if (empty($link_host)) {
            // No host found (e.g., relative path like /page, mailto:, tel:, #anchor) - consider internal
            return false;
        }

        // Remove www. from link host for comparison
        $link_host = strtolower(preg_replace('/^www\./i', '', $link_host));

        // Use case-insensitive comparison
        return strcasecmp($link_host, $home_host) !== 0;
    }
}

/**
 * Handles link tracking and reporting
 */
class Surflab_Link_Reporter {
    private $logger;
    public $table_name; // Made public for easier access in Admin_Manager
    private $link_modifier_helper; // Instance of Surflab_Link_Modifier

    public function __construct(Surflab_Logger $logger) {
        global $wpdb;
        $this->logger = $logger;
        $this->table_name = $wpdb->prefix . 'surflab_links';
        // We need the is_external logic. Instantiate necessary dependencies.
        $temp_settings = new Surflab_Settings();
        $this->link_modifier_helper = new Surflab_Link_Modifier($temp_settings, $logger);
    }

    /**
     * Create database table on plugin activation
     */
    public function create_link_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        // Increased URL length, added index
        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            url TEXT NOT NULL,
            domain VARCHAR(255) NOT NULL,
            anchor_text TEXT NOT NULL,
            is_external TINYINT(1) NOT NULL,
            link_count INT(11) NOT NULL DEFAULT 1,
            last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY domain (domain),
            KEY is_external (is_external),
            KEY url (url(191)) -- Index prefix for TEXT column
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql); // dbDelta handles table creation and updates
        $this->logger->log('Link table schema checked/updated via dbDelta.');
    }

     /**
     * Scan post content for links and update the database.
     * Hooked to save_post.
     *
     * @param int $post_id The ID of the post being saved.
     * @param WP_Post $post The post object.
     */
    public function scan_post_links($post_id, $post) {
        $this->logger->log("--- Starting scan_post_links for post ID: {$post_id} ---");

        // --- Pre-checks ---
        if (wp_is_post_revision($post_id)) {
             $this->logger->log("Post {$post_id} is a revision. Skipping scan.");
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
             $this->logger->log("Doing autosave for post {$post_id}. Skipping scan.");
            return;
        }
        if (wp_is_post_autosave($post_id)) {
             $this->logger->log("Post {$post_id} is an autosave. Skipping scan.");
             return;
        }

        $post_type = get_post_type($post_id);
        if (!$post_type) {
             $this->logger->log("Could not get post type for post ID {$post_id}. Skipping scan.");
             return;
        }

        // Check permissions based on post type
        $post_type_object = get_post_type_object($post_type);
        if (!$post_type_object || !isset($post_type_object->cap->edit_post)) {
             $this->logger->log("Cannot determine edit capability for post type '{$post_type}'. Skipping scan for post {$post_id}.");
             return;
        }
        if (!current_user_can($post_type_object->cap->edit_post, $post_id)) {
             $this->logger->log("User cannot edit post {$post_id} of type '{$post_type}'. Skipping scan.");
             return;
        }
        $this->logger->log("User permissions check passed for post {$post_id}.");

        // Check if post type is allowed
        $allowed_post_types = apply_filters('surflab_scannable_post_types', ['post', 'page']);
        if (!in_array($post_type, $allowed_post_types)) {
             $this->logger->log("Post type '{$post_type}' not allowed for scanning. Skipping post {$post_id}.");
            return;
        }
        $this->logger->log("Post type '{$post_type}' is allowed for scanning post {$post_id}.");

        // Check post status - only scan published posts by default
        $allowed_statuses = apply_filters('surflab_scannable_post_statuses', ['publish']);
        $current_status = get_post_status($post_id);
        if (!$current_status || !in_array($current_status, $allowed_statuses)) {
             $this->logger->log("Post status '{$current_status}' is not in allowed statuses [" . implode(',', $allowed_statuses) . "]. Clearing links and skipping scan for post {$post_id}.");
             // Clear links if post is no longer in an allowed status
             global $wpdb;
             $wpdb->delete($this->table_name, ['post_id' => $post_id], ['%d']);
            return;
        }
        $this->logger->log("Post status '{$current_status}' is allowed. Proceeding with scan for post {$post_id}.");

        // --- Database Operation: Clear old links ---
        global $wpdb;
        $this->logger->log("Attempting to clear existing links for post ID: {$post_id} from table {$this->table_name}");
        $deleted_rows = $wpdb->delete($this->table_name, ['post_id' => $post_id], ['%d']);
        if ($deleted_rows === false) {
             $this->logger->log("Error clearing existing links for post ID {$post_id}: " . $wpdb->last_error);
             // Decide if we should continue if clearing failed? Maybe not.
             // return;
        } else {
             $this->logger->log("Cleared {$deleted_rows} existing link entries for post ID: {$post_id}");
        }

        // --- Link Extraction ---
        $content = $post->post_content;
        if (empty($content)) {
             $this->logger->log("Post ID {$post_id} has no content to scan.");
            return; // No links to find
        }
        $this->logger->log("Post {$post_id} content is not empty. Loading into DOMDocument.");

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // Wrap content to handle fragments and ensure UTF-8
        $load_success = @$dom->loadHTML('<?xml encoding="UTF-8"><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xml_errors = libxml_get_errors();
        if (!empty($xml_errors)) {
             $this->logger->log("libxml errors during loadHTML for post {$post_id}: " . print_r($xml_errors, true));
             libxml_clear_errors();
        }
        if (!$load_success) {
             $this->logger->log("Failed to load HTML content for post {$post_id}. Skipping link extraction.");
             return;
        }
        $this->logger->log("Successfully loaded HTML for post {$post_id}.");

        $links = $dom->getElementsByTagName('a');
        $link_data = []; // Aggregate links before inserting
        $this->logger->log("Found " . ($links ? $links->length : 0) . " 'a' tags in post {$post_id}.");

        if ($links && $links->length > 0) {
            foreach ($links as $link) {
                $href = $link->getAttribute('href');
                $href = html_entity_decode($href); // Decode entities like &
                $anchor_text = trim($link->textContent);
                $this->logger->log("Processing link: href='{$href}', anchor='{$anchor_text}'");

                // Skip empty, anchor, or non-http(s)/relative links
                if (empty($href) || strpos($href, '#') === 0 ) {
                     $this->logger->log("Skipping empty or anchor link: {$href}");
                     continue;
                }
                if (!preg_match('/^(https?:)?\/\//i', $href) && strpos($href, '/') !== 0) {
                     if (!preg_match('/^(mailto|tel|javascript|file):/i', $href)) {
                         $this->logger->log("Skipping non-web/non-relative link: {$href}");
                     } else {
                          $this->logger->log("Skipping mailto/tel/etc link: {$href}");
                     }
                     continue;
                }


                // Resolve relative URLs starting with /
                if (strpos($href, '/') === 0 && strpos($href, '//') !== 0) {
                    $original_href = $href;
                    $href = home_url($href);
                     $this->logger->log("Resolved relative URL '{$original_href}' to: {$href}");
                }

                $parsed_url = parse_url($href);
                $domain = $parsed_url['host'] ?? '';
                $domain = strtolower(preg_replace('/^www\./i', '', $domain)); // Normalize domain

                if (empty($domain)) {
                     // This might happen for invalid URLs after resolution, skip them
                     $this->logger->log("Skipping link with no resolvable domain after potential resolution: {$href}");
                     continue;
                }

                $is_external = $this->link_modifier_helper->is_external($href);
                $this->logger->log("Link '{$href}' determined as " . ($is_external ? 'external' : 'internal') . " (Domain: {$domain})");

                // Aggregate links: Key by URL + Anchor
                $key = md5($href . '|' . $anchor_text);

                if (!isset($link_data[$key])) {
                    $link_data[$key] = [
                        'post_id' => $post_id,
                        'url' => $href,
                        'domain' => $domain,
                        'anchor_text' => $anchor_text,
                        'is_external' => $is_external ? 1 : 0,
                        'link_count' => 0,
                    ];
                }
                $link_data[$key]['link_count']++;
                $this->logger->log("Aggregated link: key='{$key}', count={$link_data[$key]['link_count']}");
            }
        }

        // --- Database Operation: Insert new links ---
        if (!empty($link_data)) {
            $inserted_count = 0;
            $total_links_to_insert = count($link_data);
            $this->logger->log("Preparing to insert {$total_links_to_insert} unique link entries for post {$post_id}.");
            foreach ($link_data as $data) {
                 $this->logger->log("Inserting: post_id={$data['post_id']}, url={$data['url']}, domain={$data['domain']}, anchor='{$data['anchor_text']}', external={$data['is_external']}, count={$data['link_count']}");
                $result = $wpdb->insert(
                    $this->table_name,
                    [
                        'post_id' => $data['post_id'],
                        'url' => $data['url'],
                        'domain' => $data['domain'],
                        'anchor_text' => $data['anchor_text'],
                        'is_external' => $data['is_external'],
                        'link_count' => $data['link_count'],
                    ],
                    [ '%d', '%s', '%s', '%s', '%d', '%d' ] // Data formats
                );
                 if ($result === false) {
                     $this->logger->log("Failed to insert link data for post {$post_id} (URL: {$data['url']}): " . $wpdb->last_error);
                 } else {
                     $inserted_count++;
                     $this->logger->log("Insert successful for URL: {$data['url']}");
                 }
            }
             $this->logger->log("Attempted to insert {$total_links_to_insert} unique link entries, successfully inserted {$inserted_count} for post ID: {$post_id}");
        } else {
             $this->logger->log("No valid, trackable links found to insert for post ID: {$post_id}");
        }
         $this->logger->log("--- Finished scan_post_links for post ID: {$post_id} ---");
    }

    /**
     * Generate enhanced link report with filtering (Unused by current UI)
     * @param array $filters Report filters
     * @param int $per_page Items per page
     * @param int $page Current page
     * @return array Report data with pagination
     */
    public function generate_enhanced_report($filters = [], $per_page = 20, $page = 1) {
        global $wpdb;
        $this->logger->log("Generating enhanced report with filters: " . print_r($filters, true));

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(url LIKE %s OR anchor_text LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }

        if (!empty($filters['domain'])) {
            $where[] = 'domain = %s';
            $params[] = $filters['domain'];
        }

        $where_clause = implode(' AND ', $where);

        // Query to get paginated results
        $query = "SELECT
                url,
                domain,
                anchor_text,
                is_external,
                COUNT(DISTINCT post_id) as post_count,
                GROUP_CONCAT(DISTINCT CONCAT(post_id, ':', link_count) ORDER BY post_id) as post_data
            FROM {$this->table_name}
            WHERE {$where_clause}
            GROUP BY url, anchor_text, domain, is_external -- Group more precisely
            ORDER BY post_count DESC, url ASC"; // Add secondary sort

        // Query to get total count
        $total_query = "SELECT COUNT(DISTINCT url, anchor_text, domain, is_external)
                        FROM {$this->table_name}
                        WHERE {$where_clause}";

        $total = $wpdb->get_var($wpdb->prepare($total_query, $params));
        $this->logger->log("Total unique links found: {$total}");

        $offset = ($page - 1) * $per_page;
        $query .= $wpdb->prepare(" LIMIT %d, %d", $offset, $per_page);

        $results = $wpdb->get_results($wpdb->prepare($query, $params));
        $this->logger->log("Fetched " . count($results) . " links for page {$page}.");

        // Process results to include post details
        if ($results) {
            foreach ($results as &$row) {
                $posts = [];
                if (!empty($row->post_data)) {
                    $post_data_pairs = explode(',', $row->post_data);
                    foreach ($post_data_pairs as $data) {
                        // Ensure data format is correct before exploding
                        if (strpos($data, ':') !== false) {
                            list($post_id, $count) = explode(':', $data, 2); // Limit to 2 parts
                            $post = get_post(absint($post_id)); // Sanitize ID
                            if ($post) {
                                $posts[] = (object)[
                                    'ID' => $post_id,
                                    'post_title' => $post->post_title,
                                    'edit_link' => get_edit_post_link($post_id),
                                    'count' => absint($count) // Sanitize count
                                ];
                            }
                        }
                    }
                }
                $row->posts = $posts;
                unset($row->post_data); // Remove raw data
            }
        }

        return [
            'data' => $results ?: [],
            'pagination' => [
                'total_items' => $total,
                'per_page' => $per_page,
                'current_page' => $page,
                'total_pages' => ceil($total / $per_page)
            ]
        ];
    }

    /**
     * Get unique domains from tracked links
     * @return array List of unique domains
     */
    public function get_unique_domains() {
        global $wpdb;
        return $wpdb->get_col("SELECT DISTINCT domain FROM {$this->table_name} WHERE domain != '' ORDER BY domain");
    }

}

/**
 * Handles bulk removal operations
 */
class Surflab_Bulk_Removal {
    private $settings; // Instance of Surflab_Settings
    private $logger;   // Instance of Surflab_Logger
    private $link_modifier_helper; // Instance of Surflab_Link_Modifier

    public function __construct(Surflab_Settings $settings, Surflab_Logger $logger) {
        $this->settings = $settings;
        $this->logger = $logger;
        // Instantiate helper for is_external check, passing dependencies
        $this->link_modifier_helper = new Surflab_Link_Modifier($settings, $logger);
        add_action('admin_post_surflab_bulk_remove_links', [$this, 'handle_request']);
    }

    /**
     * Handle bulk removal form submission via admin-post.php
     */
    public function handle_request() {
        $this->logger->log("--- Starting handle_request for bulk removal ---");
        $this->verify_auth(); // Check nonce and permissions

        $post_ids_str = $_POST['post_ids'] ?? '';
        $link_type = $_POST['link_type'] ?? 'all'; // 'all', 'external', 'internal'
        $this->logger->log("Received post IDs string: '{$post_ids_str}', link type: '{$link_type}'");

        $post_ids = !empty($post_ids_str) ? array_map('absint', explode(',', $post_ids_str)) : [];
        $processed_count = 0;
        $removed_count = 0;

        if (empty($post_ids)) {
             $this->logger->log("No valid post IDs provided for bulk removal.");
        } else {
             $this->logger->log("Processing " . count($post_ids) . " post IDs for bulk removal.");
            foreach ($post_ids as $post_id) {
                if ($post_id > 0) {
                    $removed_in_post = $this->process_post_for_removal($post_id, $link_type);
                    if ($removed_in_post !== false) { // Check if processing occurred (returned int)
                        $removed_count += $removed_in_post;
                        $processed_count++;
                    }
                } else {
                     $this->logger->log("Skipping invalid post ID: {$post_id}");
                }
            }
        }

        $this->logger->log("Bulk removal finished. Processed: {$processed_count}, Removed: {$removed_count}");
        // Store results in transient for display on redirect
        set_transient('surflab_bulk_action_count', [
            'removed' => $removed_count,
            'processed' => $processed_count
        ], 30); // Expires in 30 seconds

        // Redirect back to the bulk removal tab
        $redirect_url = admin_url('tools.php?page=surflab-link-manager&tab=bulk&status=processed');
        wp_safe_redirect($redirect_url); // Use wp_safe_redirect for security
        exit;
    }

    /**
     * Verify user authorization and nonce
     */
    private function verify_auth() {
        // Check nonce first
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']), 'surflab_bulk_remove_action')) {
             $this->logger->log("Nonce verification failed for bulk removal.");
             wp_die('Security check failed (Nonce). Please try again.');
        }
        // Check capability
        if (!current_user_can('manage_options')) { // Use appropriate capability
             $this->logger->log("User permission check failed for bulk removal.");
             wp_die('You do not have sufficient permissions to perform this action.');
        }
         $this->logger->log("Nonce and permissions verified for bulk removal.");
    }

/**
 * Process individual post for link removal
 * @param int $post_id Post ID to process
 * @param string $link_type Type of links to remove ('all', 'external', 'internal')
 * @return int|false Number of links removed, or false on error/no post
 */
private function process_post_for_removal($post_id, $link_type) {
    $this->logger->log("Processing post ID {$post_id} for {$link_type} link removal.");
    $post = get_post($post_id);
    if (!$post) {
        $this->logger->log("Could not retrieve post object for ID {$post_id}.");
        return false;
    }

    // Skip revisions and autosaves
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        $this->logger->log("Skipping revision/autosave for post ID {$post_id}.");
        return 0;
    }

    $original_content = $post->post_content;
    if (empty($original_content)) {
        $this->logger->log("Post {$post_id} has no content. Skipping removal.");
        return 0;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8"><div>' . $original_content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $count = 0;
    $links = $dom->getElementsByTagName('a');
    $links_to_process = [];
    
    foreach ($links as $link) {
        $links_to_process[] = $link;
    }

    // Process links in reverse to maintain DOM integrity
    for ($i = count($links_to_process) - 1; $i >= 0; $i--) {
        $link = $links_to_process[$i];
        if ($this->should_remove_link($link, $link_type)) {
            $this->logger->log("Removing link: " . $dom->saveHTML($link) . " from post {$post_id}");
            if ($this->replace_link_with_text($link)) {
                $count++;
            }
        }
    }

    if ($count > 0) {
        // Extract modified content
        $wrapper_div = $dom->getElementsByTagName('div')->item(0);
        $new_content = '';
        
        if ($wrapper_div) {
            foreach ($wrapper_div->childNodes as $node) {
                $new_content .= $dom->saveHTML($node);
            }
        } else {
            $this->logger->log("Error: Missing wrapper div in post {$post_id}.");
            return false;
        }

        if ($new_content !== $original_content) {
            $this->logger->log("Updating post {$post_id} with {$count} links removed.");
            
            // Temporarily disable the save_post hook
            remove_action('save_post', [$GLOBALS['surflab_plugin_instance']->link_reporter, 'scan_post_links'], 10);

            $update_result = wp_update_post([
                'ID' => $post_id,
                'post_content' => $new_content
            ], true);

            // Restore the hook
            add_action('save_post', [$GLOBALS['surflab_plugin_instance']->link_reporter, 'scan_post_links'], 10, 2);

            if (is_wp_error($update_result)) {
                $this->logger->log("Error updating post {$post_id}: " . $update_result->get_error_message());
                return false;
            } else {
                $this->logger->log("Successfully updated post {$post_id}.");
                
                // CRUCIAL UPDATE: Trigger manual rescan after content change
                if (isset($GLOBALS['surflab_plugin_instance']->link_reporter)) {
                    $GLOBALS['surflab_plugin_instance']->link_reporter->scan_post_links(
                        $post_id, 
                        get_post($post_id)
                    );
                    $this->logger->log("Triggered link rescan for post {$post_id}");
                }
            }
        } else {
            $this->logger->log("Content unchanged for post {$post_id}. No update needed.");
        }
    } else {
        $this->logger->log("No links removed from post {$post_id}.");
    }

    return $count;
}

    /**
     * Determine if link should be removed based on type
     * @param DOMElement $link Link element
     * @param string $link_type Removal type filter ('all', 'external', 'internal')
     * @return bool Whether to remove the link
     */
    private function should_remove_link(DOMElement $link, $link_type) {
        $href = $link->getAttribute('href');
        // Skip links without href or only an anchor
        if (empty($href) || strpos($href, '#') === 0) {
            return false;
        }

        // Use the helper's is_external method for consistency
        $is_external = $this->link_modifier_helper->is_external($href);

        return match ($link_type) {
            'external' => $is_external,
            'internal' => !$is_external,
            'all' => true, // Remove all valid links if type is 'all'
            default => false, // Unknown type, don't remove
        };
    }

    /**
     * Replace link element with its text content (preserves child nodes)
     * @param DOMElement $link Link element to replace
     * @return bool True on success, false on failure
     */
    private function replace_link_with_text(DOMElement $link) {
        if (!$link->parentNode) {
             $this->logger->log("Cannot replace link: Parent node not found.");
             return false; // Cannot replace if not attached
        }
        // Create a fragment to hold the link's children
        $fragment = $link->ownerDocument->createDocumentFragment();
        while ($link->firstChild) {
            // Move child nodes from link to fragment
            $fragment->appendChild($link->firstChild);
        }

        // Replace the link with the fragment containing its former children
        $link->parentNode->replaceChild($fragment, $link);
        return true;
    }
}

/**
 * Handles admin interface (menus, settings page, AJAX)
 */
class Surflab_Admin_Manager {
    private $settings; // Instance of Surflab_Settings
    private $logger;   // Instance of Surflab_Logger
    private $link_reporter; // Instance of Surflab_Link_Reporter

    public function __construct(Surflab_Settings $settings, Surflab_Logger $logger, Surflab_Link_Reporter $link_reporter) {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->link_reporter = $link_reporter; // Use passed instance

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_plugin_settings']);
        // AJAX action for bulk removal post search (no counts needed)
        add_action('wp_ajax_surflab_search_posts', [$this, 'ajax_search_posts_callback']);
        // AJAX action for link report post search (with counts)
        add_action('wp_ajax_surflab_search_posts_with_counts', [$this, 'ajax_search_posts_with_counts_callback']);
        // Enqueue scripts/styles for admin pages
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Enqueue necessary CSS and JS for plugin admin pages.
     */
    public function enqueue_admin_assets($hook_suffix) {
        // Only load on our plugin page (Tools -> Link Manager)
        // The hook suffix for add_management_page is 'tools_page_{menu_slug}'
        if ($hook_suffix !== 'tools_page_surflab-link-manager') {
            return;
        }
        $this->logger->log("Enqueueing assets for hook: {$hook_suffix}");
        // Add any specific CSS or JS files here if needed later
        // wp_enqueue_style('surflab-admin-css', plugin_dir_url(__FILE__) . 'css/admin.css', [], '1.0');
        // wp_enqueue_script('surflab-admin-js', plugin_dir_url(__FILE__) . 'js/admin.js', ['jquery'], '1.0', true);

        // Pass ajax url and nonce to javascript files if needed
        // wp_localize_script('surflab-admin-js', 'surflab_ajax', [
        //     'ajax_url' => admin_url('admin-ajax.php'),
        //     'nonce' => wp_create_nonce('surflab_ajax_nonce') // Example nonce
        // ]);
    }


    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_management_page( // Add under "Tools" menu
            'Surflab Link Manager',          // Page title
            'Link Manager',                  // Menu title
            'manage_options',                // Capability required
            'surflab-link-manager',          // Menu slug
            [$this, 'render_admin_page']     // Callback function to render the page
        );
    }

    /**
     * Register plugin settings fields and sections
     */
    public function register_plugin_settings() {
        // Register the setting itself
        register_setting(
            'surflab_link_settings_group',     // Option group name (used in settings_fields)
            Surflab_Settings::OPTION_NAME,     // Option name in wp_options table
            [
                'sanitize_callback' => [$this->settings, 'sanitize'], // Use sanitize method from Settings class
                'default' => $this->settings->get_defaults() // Provide defaults
            ]
        );

        // --- Main Settings Section ---
        add_settings_section(
            'surflab_main_settings_section',   // Section ID
            'Link Attribute Settings',         // Section Title displayed to user
            function() { echo '<p>Configure automatic attributes for internal and external links.</p>'; }, // Section description callback
            'surflab-link-manager-settings'    // Page slug where this section is shown
        );

        // Add fields to the main section
        $this->add_settings_field_helper('external_nofollow', 'External Links: Add nofollow', 'surflab_main_settings_section', 'surflab-link-manager-settings');
        $this->add_settings_field_helper('external_target_blank', 'External Links: Open in new tab (_blank)', 'surflab_main_settings_section', 'surflab-link-manager-settings');
        $this->add_settings_field_helper('external_sponsored', 'External Links: Add sponsored', 'surflab_main_settings_section', 'surflab-link-manager-settings');
        $this->add_settings_field_helper('external_ugc', 'External Links: Add ugc', 'surflab_main_settings_section', 'surflab-link-manager-settings');
        $this->add_settings_field_helper('external_noopener', 'External Links: Add noopener (Recommended with _blank)', 'surflab_main_settings_section', 'surflab-link-manager-settings');
        $this->add_settings_field_helper('external_noreferrer', 'External Links: Add noreferrer', 'surflab_main_settings_section', 'surflab-link-manager-settings');

        $this->add_settings_field_helper('internal_nofollow', 'Internal Links: Add nofollow', 'surflab_main_settings_section', 'surflab-link-manager-settings');
        $this->add_settings_field_helper('internal_target_blank', 'Internal Links: Open in new tab (_blank)', 'surflab_main_settings_section', 'surflab-link-manager-settings');
    }

    /**
     * Helper function to add a settings field
     * @param string $name Field name (option key within the main option array)
     * @param string $title Field title/label
     * @param string $section Section ID
     * @param string $page Page slug
     */
    private function add_settings_field_helper($name, $title, $section, $page) {
        add_settings_field(
            'surflab_setting_' . $name,     // Unique Field ID
            $title,                         // Field Title
            [$this, 'render_checkbox_field'], // Callback to render the input
            $page,                          // Page slug where field is shown
            $section,                       // Section ID this field belongs to
            ['name' => $name]               // Args passed to the render callback
        );
    }

    /**
     * Render checkbox input for a settings field
     * @param array $args Field arguments containing 'name'
     */
    public function render_checkbox_field($args) {
        $option_name = Surflab_Settings::OPTION_NAME;
        // Get fresh options including defaults
        $settings = get_option($option_name, $this->settings->get_defaults());
        $name = $args['name'];
        // Ensure the key exists, default to false if not set
        $checked = isset($settings[$name]) ? (bool)$settings[$name] : false;
        ?>
        <label for="surflab_setting_<?php echo esc_attr($name); ?>">
            <input type="checkbox"
                   id="surflab_setting_<?php echo esc_attr($name); ?>"
                   name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($name); ?>]"
                   value="1" <?php checked($checked); ?>>
             <?php // Description can be added here if needed ?>
        </label>
        <?php
    }

    /**
     * Render the main admin page with tabs
     */
    public function render_admin_page() {
        $active_tab = $_GET['tab'] ?? 'settings'; // Default to settings tab
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php
            // Display saved/error notices from Settings API
            settings_errors(); // This displays notices registered with add_settings_error() or default notices

            // Display transient notice for bulk actions
            $action_count = get_transient('surflab_bulk_action_count');
            if ($action_count !== false) { // Check if transient exists
                $message = sprintf(
                    'Bulk action complete. Processed %d posts, removed %d links.',
                    esc_html($action_count['processed']),
                    esc_html($action_count['removed'])
                );
                // Use WordPress admin notices API
                add_settings_error(
                    'surflab_bulk_action_notice', // Slug for notice
                    'bulk_action_success',        // CSS class
                    $message,                     // Message
                    'success'                     // Type ('success', 'error', 'warning', 'info')
                );
                settings_errors('surflab_bulk_action_notice'); // Display our custom notice
                delete_transient('surflab_bulk_action_count'); // Clear after display
            }
            ?>

            <nav class="nav-tab-wrapper wp-clearfix">
                <a href="?page=surflab-link-manager&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    Settings
                </a>
                <a href="?page=surflab-link-manager&tab=report" class="nav-tab <?php echo $active_tab === 'report' ? 'nav-tab-active' : ''; ?>">
                    Link Report
                </a>
                <a href="?page=surflab-link-manager&tab=bulk" class="nav-tab <?php echo $active_tab === 'bulk' ? 'nav-tab-active' : ''; ?>">
                    Bulk Removal
                </a>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php if ($active_tab === 'settings') : ?>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('surflab_link_settings_group'); // Match group name from register_setting
                        do_settings_sections('surflab-link-manager-settings'); // Match page slug from add_settings_section
                        submit_button('Save Settings');
                        ?>
                    </form>
                <?php elseif ($active_tab === 'report') : ?>
                    <?php $this->render_report_interface(); ?>
                <?php elseif ($active_tab === 'bulk') : ?>
                    <?php $this->render_bulk_removal_interface(); ?>
                <?php endif; ?>
            </div>

        </div>
        <?php
    }

    /**
     * Handle AJAX post search requests (for Bulk Removal - no counts)
     */
    public function ajax_search_posts_callback() {
        // Verify nonce
        if (!check_ajax_referer('surflab_search_nonce', 'security', false)) {
             $this->logger->log("AJAX Error: Nonce verification failed for surflab_search_posts.");
             wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
             return;
        }
        $this->logger->log("AJAX: surflab_search_posts called.");

        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $page = isset($_GET['page']) ? absint($_GET['page']) : 1;
        $posts_per_page = isset($_GET['posts_per_page']) ? absint($_GET['posts_per_page']) : 20;

        $args = [
            'post_type' => ['post', 'page'], // Add other CPTs if needed
            'post_status' => 'publish', // Only show published posts for removal?
            's' => $search,
            'posts_per_page' => $posts_per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        $query = new WP_Query($args);
        $posts_data = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $post_type_obj = get_post_type_object(get_post_type());
                $posts_data[] = [
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'post_type' => $post_type_obj ? esc_html($post_type_obj->labels->singular_name) : esc_html(get_post_type()),
                    'date' => get_the_date(),
                    'edit_link' => get_edit_post_link($post_id) // Get edit link
                ];
            }
        }
        wp_reset_postdata();

        $total_items = $query->found_posts;
        $total_pages = $query->max_num_pages;

        wp_send_json_success([
            'posts' => $posts_data,
            'pagination' => [
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'per_page' => $posts_per_page
            ]
        ]);
    }

    /**
     * Handle AJAX post search requests (for Link Report - with counts)
     */
    public function ajax_search_posts_with_counts_callback() {
        // Verify nonce
        if (!check_ajax_referer('surflab_search_nonce', 'security', false)) {
             $this->logger->log("AJAX Error: Nonce verification failed for surflab_search_posts_with_counts.");
             wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
             return;
        }
        $this->logger->log("AJAX: surflab_search_posts_with_counts called.");

        global $wpdb;
        $link_table = $this->link_reporter->table_name; // Use property from reporter instance

        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $page = isset($_GET['page']) ? absint($_GET['page']) : 1;
        $posts_per_page = isset($_GET['posts_per_page']) ? absint($_GET['posts_per_page']) : 20;

        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => 'publish', // Consider which statuses to include in report
            's' => $search,
            'posts_per_page' => $posts_per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        $query = new WP_Query($args);
        $posts_data = [];
        $post_ids = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $post_type_obj = get_post_type_object(get_post_type());
                $posts_data[$post_id] = [ // Use post ID as key for easy lookup later
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'post_type' => $post_type_obj ? esc_html($post_type_obj->labels->singular_name) : esc_html(get_post_type()),
                    'date' => get_the_date(),
                    'edit_link' => get_edit_post_link($post_id),
                    'outbound_internal_count' => 0, // Initialize counts
                    'outbound_external_count' => 0
                ];
                $post_ids[] = $post_id;
            }
        }
        wp_reset_postdata();

        // Fetch link counts if we found posts
        if (!empty($post_ids)) {
             $this->logger->log("Fetching link counts for post IDs: " . implode(',', $post_ids));
            $post_ids_placeholder = implode(',', array_fill(0, count($post_ids), '%d'));
            $sql = $wpdb->prepare(
                "SELECT post_id, is_external, SUM(link_count) as total_count
                 FROM {$link_table}
                 WHERE post_id IN ({$post_ids_placeholder})
                 GROUP BY post_id, is_external",
                $post_ids // Pass array directly to prepare
            );
            $link_counts = $wpdb->get_results($sql);

            if ($link_counts) {
                 $this->logger->log("Found link counts: " . print_r($link_counts, true));
                foreach ($link_counts as $count_data) {
                    if (isset($posts_data[$count_data->post_id])) {
                        if ($count_data->is_external == 1) { // is_external is stored as 0 or 1
                            $posts_data[$count_data->post_id]['outbound_external_count'] = (int) $count_data->total_count;
                        } else {
                            $posts_data[$count_data->post_id]['outbound_internal_count'] = (int) $count_data->total_count;
                        }
                    }
                }
            } else {
                 $this->logger->log("No link counts found in DB for these post IDs.");
            }
        } else {
             $this->logger->log("No posts found matching query, skipping link count fetch.");
        }

        $total_items = $query->found_posts;
        $total_pages = $query->max_num_pages;

        wp_send_json_success([
            'posts' => array_values($posts_data), // Return as a simple array for JS
            'pagination' => [
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'per_page' => $posts_per_page
            ]
        ]);
    }


    /**
     * Render link report interface (HTML + JS)
     */
    private function render_report_interface() {
        ?>
        <div class="card">
            <h2>Link Report</h2>
            <p>This report shows the number of outbound internal and external links found in your published posts and pages. Data is updated when posts are saved.</p>

            <div class="surflab-report-controls">
                <div class="surflab-search-bar">
                    <label for="surflab-report-search-input" class="screen-reader-text">Search Posts</label>
                    <input type="search" id="surflab-report-search-input" placeholder="Search posts/pages..." class="regular-text">
                    <button type="button" class="button" id="surflab-report-search-submit">Search</button>
                </div>

                <table class="wp-list-table widefat fixed striped posts surflab-link-report-table" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th scope="col" id="title" class="manage-column column-title column-primary">Title</th>
                            <th scope="col" id="outbound_internal" class="manage-column" style="width:15%; text-align:center;">Outbound Internal</th>
                            <th scope="col" id="outbound_external" class="manage-column" style="width:15%; text-align:center;">Outbound External</th>
                            <th scope="col" id="date" class="manage-column column-date">Date Published</th>
                        </tr>
                    </thead>
                    <tbody id="surflab-report-post-list">
                        <tr><td colspan="4">Loading posts...</td></tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="col" class="manage-column column-title column-primary">Title</th>
                            <th scope="col" class="manage-column" style="text-align:center;">Outbound Internal</th>
                            <th scope="col" class="manage-column" style="text-align:center;">Outbound External</th>
                            <th scope="col" class="manage-column column-date">Date Published</th>
                        </tr>
                    </tfoot>
                </table>

                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><span id="surflab-report-total-items">0</span> items</span>
                        <span class="pagination-links" id="surflab-report-pagination">
                            <!-- Pagination JS will populate this -->
                        </span>
                    </div>
                    <br class="clear">
                </div>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            let reportCurrentPage = 1;
            let reportCurrentSearch = '';
            const reportPostsPerPage = 20; // Or get from PHP if needed
            const ajaxNonce = '<?php echo wp_create_nonce('surflab_search_nonce'); ?>'; // Nonce for AJAX

            function fetchReportPosts(page = 1, search = '') {
                reportCurrentPage = page;
                reportCurrentSearch = search;
                const listBody = $('#surflab-report-post-list');
                const paginationContainer = $('#surflab-report-pagination');
                const totalItemsSpan = $('#surflab-report-total-items');

                listBody.html('<tr><td colspan="4"><span class="spinner is-active" style="float:none; vertical-align: middle;"></span> Loading...</td></tr>');
                paginationContainer.empty().hide(); // Clear and hide pagination
                totalItemsSpan.text('0');

                $.ajax({
                    url: ajaxurl, // WordPress AJAX URL
                    type: 'GET',
                    data: {
                        action: 'surflab_search_posts_with_counts',
                        security: ajaxNonce,
                        search: reportCurrentSearch,
                        page: reportCurrentPage,
                        posts_per_page: reportPostsPerPage
                    },
                    success: function(response) {
                        if (response.success) {
                            renderReportTable(response.data.posts);
                            renderReportPagination(response.data.pagination);
                            totalItemsSpan.text(response.data.pagination.total_items);
                        } else {
                            listBody.html('<tr><td colspan="4">Error loading posts: ' + (response.data?.message || 'Unknown error') + '</td></tr>');
                            console.error("AJAX Error:", response);
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                         listBody.html('<tr><td colspan="4">AJAX request failed: ' + textStatus + '</td></tr>');
                         console.error("AJAX Request Failed:", textStatus, errorThrown);
                    },
                    complete: function() {
                        // Remove spinner if it's still there
                        listBody.find('.spinner').remove();
                    }
                });
            }

            function renderReportTable(posts) {
                const list = $('#surflab-report-post-list');
                list.empty(); // Clear previous content

                if (!posts || posts.length === 0) {
                    list.html('<tr><td colspan="4">No posts found matching your criteria.</td></tr>');
                    return;
                }

                posts.forEach(post => {
                    // Basic JS escaping for attributes and content
                    const editLink = post.edit_link ? $('<a>').attr('href', post.edit_link).text('Edit')[0].outerHTML : '';
                    const titleLink = post.edit_link ? $('<a>').attr('href', post.edit_link).addClass('row-title').text(post.title)[0].outerHTML : $('<strong>').text(post.title)[0].outerHTML;

                    const row = `
                        <tr id="post-${post.id}">
                            <td class="title column-title has-row-actions column-primary page-title" data-colname="Title">
                                ${titleLink}
                                <div class="row-actions">
                                    <span class="edit">${editLink}</span>
                                </div>
                            </td>
                            <td class="outbound_internal column-outbound_internal" data-colname="Outbound Internal" style="text-align:center;">${post.outbound_internal_count || 0}</td>
                            <td class="outbound_external column-outbound_external" data-colname="Outbound External" style="text-align:center;">${post.outbound_external_count || 0}</td>
                            <td class="date column-date" data-colname="Date">Published<br>${post.date || 'N/A'}</td>
                        </tr>`;
                    list.append(row);
                });
            }

            function renderReportPagination(pagination) {
                const container = $('#surflab-report-pagination');
                container.empty(); // Clear previous pagination

                if (!pagination || pagination.total_pages <= 1) {
                    container.hide();
                    return;
                }
                 container.show();

                const createLink = (page, text, classes = '') => {
                    const pageNum = parseInt(page);
                    const currentPage = parseInt(pagination.current_page);
                    if (!pageNum || pageNum < 1 || pageNum > pagination.total_pages || pageNum === currentPage) {
                        // Disabled link (current page or out of bounds)
                        return `<span class="tablenav-pages-navspan button disabled ${classes}" aria-hidden="true">${text}</span>`;
                    }
                    // Active link
                    return `<a class="button ${classes}" href="#" data-page="${pageNum}"><span class="screen-reader-text">Page ${pageNum}</span><span aria-hidden="true">${text}</span></a>`;
                };

                // First and Previous Page Links
                container.append(createLink(1, '&laquo;', 'first-page'));
                container.append(createLink(pagination.current_page - 1, '&lsaquo;', 'prev-page'));

                // Current Page Input
                container.append(`
                    <span class="paging-input">
                        <label for="report-current-page-selector" class="screen-reader-text">Current Page</label>
                        <input class="current-page" id="report-current-page-selector" type="text" name="paged" value="${pagination.current_page}" size="${pagination.total_pages.toString().length}" aria-describedby="table-paging">
                        <span class="tablenav-paging-text"> of <span class="total-pages">${pagination.total_pages}</span></span>
                    </span>
                `);

                // Next and Last Page Links
                container.append(createLink(pagination.current_page + 1, '&rsaquo;', 'next-page'));
                container.append(createLink(pagination.total_pages, '&raquo;', 'last-page'));
            }

            // --- Event Handlers ---

            // Search Button Click
            $('#surflab-report-search-submit').on('click', function() {
                fetchReportPosts(1, $('#surflab-report-search-input').val());
            });
            // Search Input Enter Key
            $('#surflab-report-search-input').on('keypress', function(e) {
                if (e.which === 13) { // Enter key pressed
                    fetchReportPosts(1, $(this).val());
                }
            });

            // Pagination Link Clicks (delegated)
            $('#surflab-report-pagination').on('click', 'a.button', function(e) {
                e.preventDefault();
                const page = $(this).data('page');
                if (page) {
                    fetchReportPosts(page, reportCurrentSearch);
                }
            });

             // Pagination Input Change
            $('#surflab-report-pagination').on('change', 'input.current-page', function() {
                let page = parseInt($(this).val());
                const totalPages = parseInt($(this).closest('.pagination-links').find('.total-pages').text());
                if (isNaN(page) || page < 1) page = 1;
                if (page > totalPages) page = totalPages;
                fetchReportPosts(page, reportCurrentSearch);
            });

            // Initial load on page ready
            fetchReportPosts();
        });
        </script>
        <?php
    }

    /**
     * Render bulk removal interface (HTML + JS)
     */
    private function render_bulk_removal_interface() {
        ?>
        <div class="card">
            <h2>Bulk Link Removal</h2>
            <p>Select posts/pages below and choose whether to remove all links, only external links, or only internal links from their content. This action modifies post content and cannot be easily undone.</p>

            <form id="surflab-bulk-remove-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="surflab_bulk_remove_links">
                <?php wp_nonce_field('surflab_bulk_remove_action'); // Nonce for security ?>
                <input type="hidden" name="post_ids" id="surflab-bulk-post-ids">

                <div class="surflab-bulk-controls">
                    <div class="surflab-search-bar">
                        <label for="surflab-bulk-search-input" class="screen-reader-text">Search Posts</label>
                        <input type="search" id="surflab-bulk-search-input" placeholder="Search posts/pages..." class="regular-text">
                        <button type="button" class="button" id="surflab-bulk-search-submit">Search</button>
                    </div>

                    <table class="wp-list-table widefat fixed striped posts" style="margin-top: 15px;">
                        <thead>
                            <tr>
                                <td id="cb" class="manage-column column-cb check-column">
                                    <label class="screen-reader-text" for="cb-select-all-bulk-1">Select All</label>
                                    <input id="cb-select-all-bulk-1" type="checkbox">
                                </td>
                                <th scope="col" id="title-bulk" class="manage-column column-title column-primary">Title</th>
                                <th scope="col" id="post_type-bulk" class="manage-column column-author" style="width:10%;">Type</th>
                                <th scope="col" id="date-bulk" class="manage-column column-date">Date Published</th>
                            </tr>
                        </thead>
                        <tbody id="surflab-bulk-post-list">
                            <tr><td colspan="4">Loading posts...</td></tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="manage-column column-cb check-column">
                                    <label class="screen-reader-text" for="cb-select-all-bulk-2">Select All</label>
                                    <input id="cb-select-all-bulk-2" type="checkbox">
                                </td>
                                <th scope="col" class="manage-column column-title">Title</th>
                                <th scope="col" class="manage-column">Type</th>
                                <th scope="col" class="manage-column column-date">Date Published</th>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="tablenav bottom">
                        <div class="alignleft actions bulkactions">
                            <label for="bulk-link-type-selector" class="screen-reader-text">Select link type to remove</label>
                            <select name="link_type" id="bulk-link-type-selector">
                                <option value="all" selected="selected">Remove All Links</option>
                                <option value="external">Remove External Links Only</option>
                                <option value="internal">Remove Internal Links Only</option>
                            </select>
                            <input type="submit" id="doaction-bulk-remove" class="button action button-danger" value="Remove Links from Selected">
                        </div>
                        <div class="tablenav-pages">
                            <span class="displaying-num"><span id="surflab-bulk-total-items">0</span> items</span>
                            <span class="pagination-links" id="surflab-bulk-pagination">
                                <!-- Pagination JS will populate this -->
                            </span>
                        </div>
                        <br class="clear">
                    </div>
                </div>
            </form>
        </div>

        <style>
            .surflab-bulk-controls .surflab-search-bar { margin-bottom: 15px; }
            .button-danger { background: #dc3232; border-color: #dc3232; color: white; text-shadow: none; }
            .button-danger:hover, .button-danger:focus { background: #c02b2b; border-color: #b02828; color: white; }
            #surflab-bulk-post-list tr:hover { background-color: #f0f0f1; }
            .tablenav .actions select { vertical-align: middle; margin-right: 5px;}
        </style>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            let bulkCurrentPage = 1;
            let bulkCurrentSearch = '';
            const bulkPostsPerPage = 20;
            const bulkSelectedPosts = new Set(); // Use a Set to store selected post IDs
            const bulkAjaxNonce = '<?php echo wp_create_nonce('surflab_search_nonce'); ?>'; // Nonce for AJAX

            function fetchBulkPosts(page = 1, search = '') {
                bulkCurrentPage = page;
                bulkCurrentSearch = search;
                const listBody = $('#surflab-bulk-post-list');
                const paginationContainer = $('#surflab-bulk-pagination');
                const totalItemsSpan = $('#surflab-bulk-total-items');

                listBody.html('<tr><td colspan="4"><span class="spinner is-active" style="float:none; vertical-align: middle;"></span> Loading...</td></tr>');
                paginationContainer.empty().hide();
                totalItemsSpan.text('0');
                // Disable bulk action button while loading
                $('#doaction-bulk-remove').prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'GET',
                    data: {
                        action: 'surflab_search_posts', // Action without counts
                        security: bulkAjaxNonce,
                        search: bulkCurrentSearch,
                        page: bulkCurrentPage,
                        posts_per_page: bulkPostsPerPage
                    },
                    success: function(response) {
                        if (response.success) {
                            renderBulkTable(response.data.posts);
                            renderBulkPagination(response.data.pagination);
                            totalItemsSpan.text(response.data.pagination.total_items);
                            updateBulkCheckboxes(); // Restore checked state for current page
                        } else {
                            listBody.html('<tr><td colspan="4">Error loading posts: ' + (response.data?.message || 'Unknown error') + '</td></tr>');
                            console.error("AJAX Error:", response);
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                         listBody.html('<tr><td colspan="4">AJAX request failed: ' + textStatus + '</td></tr>');
                         console.error("AJAX Request Failed:", textStatus, errorThrown);
                    },
                    complete: function() {
                        // Remove spinner if it's still there
                        listBody.find('.spinner').remove();
                        // Re-enable button if posts were loaded
                        if ($('#surflab-bulk-post-list').find('tr').length > 1 || $('#surflab-bulk-post-list').find('td[colspan="4"]').length === 0) {
                             $('#doaction-bulk-remove').prop('disabled', false);
                        }
                    }
                });
            }

            function renderBulkTable(posts) {
                const list = $('#surflab-bulk-post-list');
                list.empty();

                if (!posts || posts.length === 0) {
                    list.html('<tr><td colspan="4">No posts found matching your criteria.</td></tr>');
                    return;
                }

                posts.forEach(post => {
                    const titleLink = post.edit_link ? $('<a>').attr('href', post.edit_link).addClass('row-title').text(post.title)[0].outerHTML : $('<strong>').text(post.title)[0].outerHTML;
                    const row = `
                        <tr id="post-bulk-${post.id}">
                            <th scope="row" class="check-column">
                                <label class="screen-reader-text" for="cb-select-bulk-${post.id}">Select ${post.title}</label>
                                <input id="cb-select-bulk-${post.id}" type="checkbox" name="post[]" value="${post.id}" class="surflab-bulk-post-checkbox">
                            </th>
                            <td class="title column-title has-row-actions column-primary page-title" data-colname="Title">
                                ${titleLink}
                            </td>
                            <td class="post_type column-author" data-colname="Type">${post.post_type || 'N/A'}</td>
                            <td class="date column-date" data-colname="Date">Published<br>${post.date || 'N/A'}</td>
                        </tr>`;
                    list.append(row);
                });
            }

            function renderBulkPagination(pagination) {
                 const container = $('#surflab-bulk-pagination');
                container.empty(); // Clear previous pagination

                if (!pagination || pagination.total_pages <= 1) {
                    container.hide();
                    return;
                }
                 container.show();

                const createLink = (page, text, classes = '') => {
                    const pageNum = parseInt(page);
                    const currentPage = parseInt(pagination.current_page);
                     if (!pageNum || pageNum < 1 || pageNum > pagination.total_pages || pageNum === currentPage) {
                        return `<span class="tablenav-pages-navspan button disabled ${classes}" aria-hidden="true">${text}</span>`;
                    }
                    return `<a class="button ${classes}" href="#" data-page="${pageNum}"><span class="screen-reader-text">Page ${pageNum}</span><span aria-hidden="true">${text}</span></a>`;
                };

                container.append(createLink(1, '&laquo;', 'first-page'));
                container.append(createLink(pagination.current_page - 1, '&lsaquo;', 'prev-page'));
                container.append(`
                    <span class="paging-input">
                        <label for="bulk-current-page-selector" class="screen-reader-text">Current Page</label>
                        <input class="current-page" id="bulk-current-page-selector" type="text" name="paged" value="${pagination.current_page}" size="${pagination.total_pages.toString().length}" aria-describedby="table-paging">
                        <span class="tablenav-paging-text"> of <span class="total-pages">${pagination.total_pages}</span></span>
                    </span>
                `);
                container.append(createLink(pagination.current_page + 1, '&rsaquo;', 'next-page'));
                container.append(createLink(pagination.total_pages, '&raquo;', 'last-page'));
            }

            // --- Checkbox Handling ---
            function updateBulkCheckboxes() {
                // Update individual checkboxes based on the Set
                $('.surflab-bulk-post-checkbox').each(function() {
                    const postId = $(this).val();
                    $(this).prop('checked', bulkSelectedPosts.has(postId));
                });
                // Update "Select All" checkboxes
                const allCheckboxesOnPage = $('.surflab-bulk-post-checkbox');
                const checkedOnPage = allCheckboxesOnPage.filter(':checked');
                const isAllChecked = allCheckboxesOnPage.length > 0 && allCheckboxesOnPage.length === checkedOnPage.length;
                $('#cb-select-all-bulk-1, #cb-select-all-bulk-2').prop('checked', isAllChecked);
            }

            // When an individual checkbox changes
            $('#surflab-bulk-post-list').on('change', '.surflab-bulk-post-checkbox', function() {
                const postId = $(this).val();
                if ($(this).is(':checked')) {
                    bulkSelectedPosts.add(postId);
                } else {
                    bulkSelectedPosts.delete(postId);
                }
                updateBulkCheckboxes(); // Update Select All state
            });

            // When "Select All" checkbox changes
            $('#cb-select-all-bulk-1, #cb-select-all-bulk-2').on('change', function() {
                const isChecked = $(this).prop('checked');
                $('.surflab-bulk-post-checkbox').each(function() {
                    const postId = $(this).val();
                    $(this).prop('checked', isChecked); // Visually check/uncheck
                    if (isChecked) {
                        bulkSelectedPosts.add(postId);
                    } else {
                        bulkSelectedPosts.delete(postId);
                    }
                });
                // Ensure both select-all checkboxes are synced
                $('#cb-select-all-bulk-1, #cb-select-all-bulk-2').prop('checked', isChecked);
            });


            // --- Event Handlers ---

            // Search Button Click
            $('#surflab-bulk-search-submit').on('click', function() {
                fetchBulkPosts(1, $('#surflab-bulk-search-input').val());
            });
            // Search Input Enter Key
            $('#surflab-bulk-search-input').on('keypress', function(e) {
                if (e.which === 13) { // Enter key pressed
                    fetchBulkPosts(1, $(this).val());
                }
            });

            // Pagination Link Clicks (delegated)
            $('#surflab-bulk-pagination').on('click', 'a.button', function(e) {
                e.preventDefault();
                const page = $(this).data('page');
                if (page) {
                    fetchBulkPosts(page, bulkCurrentSearch);
                }
            });

             // Pagination Input Change
            $('#surflab-bulk-pagination').on('change', 'input.current-page', function() {
                let page = parseInt($(this).val());
                const totalPages = parseInt($(this).closest('.pagination-links').find('.total-pages').text());
                if (isNaN(page) || page < 1) page = 1;
                if (page > totalPages) page = totalPages;
                fetchBulkPosts(page, bulkCurrentSearch);
            });

            // Form Submission
            $('#surflab-bulk-remove-form').on('submit', function(e) {
                 const selectedIdsArray = Array.from(bulkSelectedPosts);
                 const selectedIdsString = selectedIdsArray.join(',');
                 $('#surflab-bulk-post-ids').val(selectedIdsString); // Update hidden input

                 console.log('Submitting bulk removal for post IDs:', selectedIdsString); // Debug log

                 if (bulkSelectedPosts.size === 0) {
                     alert('Please select at least one post.');
                     console.log('Bulk removal submission prevented: No posts selected.'); // Debug log
                     e.preventDefault(); // Prevent form submission
                     return false;
                 }

                 // Confirmation dialog
                 const linkTypeText = $('#bulk-link-type-selector option:selected').text();
                 if (!confirm(`Are you sure you want to ${linkTypeText.toLowerCase()} from the ${bulkSelectedPosts.size} selected posts? This action cannot be undone.`)) {
                     console.log('Bulk removal cancelled by user.'); // Debug log
                     e.preventDefault(); // Prevent form submission
                     return false;
                 }

                 console.log('Proceeding with bulk removal submission.'); // Debug log
                 // Allow form submission to proceed
            });

            // Initial load on page ready
            fetchBulkPosts();
        });
        </script>
        <?php
    }

}


/**
 * Main plugin class that initializes all components
 */
class Surflab_Plugin {
    public $settings;
    public $logger;
    public $link_modifier;
    public $link_reporter;
    public $bulk_removal;
    public $admin_manager;

    /**
     * Initialize plugin components and register hooks
     */
    public function __construct() {
        // Basic components first
        $this->logger = new Surflab_Logger(true); // Enable debug logging
        $this->settings = new Surflab_Settings();

        // Components that depend on logger/settings
        $this->link_modifier = new Surflab_Link_Modifier($this->settings, $this->logger);
        $this->link_reporter = new Surflab_Link_Reporter($this->logger);
        $this->bulk_removal = new Surflab_Bulk_Removal($this->settings, $this->logger);

        // Admin manager needs access to other components potentially
        $this->admin_manager = new Surflab_Admin_Manager($this->settings, $this->logger, $this->link_reporter);

        // Register hooks
        register_activation_hook(__FILE__, [$this->link_reporter, 'create_link_table']);
        add_action('save_post', [$this->link_reporter, 'scan_post_links'], 10, 2);

        // Store instance for access in hooks if needed (e.g., removing save_post hook temporarily)
        $GLOBALS['surflab_plugin_instance'] = $this;

        $this->logger->log("Surflab Plugin Initialized.");
    }
}

// --- Instantiate the Plugin ---
new Surflab_Plugin();
