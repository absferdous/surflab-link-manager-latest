<?php
if (!defined('ABSPATH')) exit;
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
