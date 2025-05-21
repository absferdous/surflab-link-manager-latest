<?php
if (!defined('ABSPATH')) exit;

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

       $post_ids = array_filter(array_map('absint', explode(',', $post_ids_str)));
if (empty($post_ids)) {
    wp_die('Invalid post IDs provided');
}
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