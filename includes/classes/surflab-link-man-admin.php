<?php
if (!defined('ABSPATH')) exit;

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
         // Enqueue main CSS file
        // If CSS is in /css/ directory at plugin root:
// wp_enqueue_style(
//     'surflab-admin-css',
//     plugin_dir_url(__FILE__) . 'assets/css/surf-link-man.css',
//     [],
//     filemtime(plugin_dir_path(__FILE__) . 'assets/css/surf-link-man.css')
// );
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
     $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
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
                 $post = get_post($post_id);
                // Get valid edit link only if user has permission
            $edit_link = current_user_can('edit_post', $post_id) 
    ? html_entity_decode(get_edit_post_link($post_id, 'js')) // Decode HTML entities
    : '';
                $posts_data[$post_id] = [ // Use post ID as key for easy lookup later
                    'id' => $post_id,
                    'title' => html_entity_decode(get_the_title()),
                    'post_type' => $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type,
                    'post_status' => $post->post_status,
                    'date' => get_the_date(),
                    'edit_link' => $edit_link,
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
            // Add this at the top of your script
const escapeHtml = (text) => {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, (m) => map[m]);
};
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
                    // 1. Create safe title with fallback
        const title = post.title?.trim() || '(Untitled)';
        const editUrl = post.edit_link 
            ? decodeURIComponent(post.edit_link)
            : '';
                    // 2. Create edit link HTML only if available
        const editLink = editUrl 
            ? `<a href="${escapeHtml(editUrl)}" class="button-link" target="_blank">Edit</a>`
            : '<em>No access</em>';
            
        // 3. Add status indicator
        const statusBadge = `<span class="status-dot status-${post.post_status}"></span>`;
                    const titleLink = post.edit_link ? $('<a>').attr('href', post.edit_link).addClass('row-title').text(post.title)[0].outerHTML : $('<strong>').text(post.title)[0].outerHTML;

                    const row = `
                        <tr id="post-${post.id}">
                            <td class="title column-title has-row-actions column-primary page-title" data-colname="Title">
                            ${statusBadge}
                            <strong>${escapeHtml(post.title)}</strong>
                                
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