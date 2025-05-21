<?php
if (!defined('ABSPATH')) exit;


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
