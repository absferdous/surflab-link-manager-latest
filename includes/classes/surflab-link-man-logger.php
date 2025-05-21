<?php
if (!defined('ABSPATH')) exit;

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