<?php
if (!defined('ABSPATH')) exit;

// Load class files in dependency order
require_once __DIR__ . '/classes/surflab-link-man-logger.php';
require_once __DIR__ . '/classes/surflab-link-man-settings.php';
require_once __DIR__ . '/classes/surflab-link-man-link-modifier.php';
require_once __DIR__ . '/classes/surflab-link-man-reporter.php';
require_once __DIR__ . '/classes/surflab-link-man-bulk-removal.php';
require_once __DIR__ . '/classes/surflab-link-man-admin.php';
require_once __DIR__ . '/classes/surflab-link-man-plugin.php';

// Initialize the plugin
// new Surflab_Plugin();