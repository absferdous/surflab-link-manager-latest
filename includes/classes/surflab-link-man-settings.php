<?php
if (!defined('ABSPATH')) exit;

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