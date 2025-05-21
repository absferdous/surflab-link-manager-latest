<?php
if (!defined('ABSPATH')) exit;
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