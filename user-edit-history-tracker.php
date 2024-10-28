<?php
/*
Plugin Name: User Edit History Tracker
Description: Tracks and displays user edit history on the backend in a Tools submenu.
Version: 2.0
Author: Tanveer
Author URI: https://www.linkedin.com/in/tanvir-dh/
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Register activation hook
register_activation_hook(__FILE__, 'ueht_create_db');

// Create or update the database table for logs
function ueht_create_db() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'edit_history_logs';
    $charset_collate = $wpdb->get_charset_collate();
    
    // Main table creation query
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id mediumint(9) NOT NULL,
        post_id mediumint(9) NOT NULL,
        action_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        action_type varchar(50) NOT NULL,
        activity_name varchar(255) NOT NULL,
        user_ip varchar(45) NOT NULL,
        location varchar(255),
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Check and add missing columns
    ueht_check_columns($table_name);
}

// Check and add missing columns
function ueht_check_columns($table_name) {
    global $wpdb;

    // Check for the existence of each column and add if missing
    $columns = [
        'user_ip' => 'VARCHAR(45)',
        'location' => 'VARCHAR(255)',
        'activity_name' => 'VARCHAR(255)'
    ];

    foreach ($columns as $column => $definition) {
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE '{$column}'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD {$column} {$definition}");
        }
    }
}

// Hook into post save, update, and delete
add_action('save_post', 'ueht_track_user_edit', 10, 3);
add_action('delete_post', 'ueht_track_user_delete', 10);

function ueht_track_user_edit($post_id, $post, $update) {
    // Skip autosaves and revisions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    global $wpdb;
    $user_id = get_current_user_id();
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $location = ueht_get_location($user_ip);
    
    if ($update) {
        $action_type = 'update';
        $activity_name = 'Post Updated';
    } else {
        $action_type = 'create';
        $activity_name = 'Post Created';
    }

    // Check if post is being drafted
    if ($post->post_status === 'draft') {
        $action_type = 'draft';
        $activity_name = 'Post Drafted';
    }

    $wpdb->insert(
        $wpdb->prefix . 'edit_history_logs',
        [
            'user_id' => $user_id,
            'post_id' => $post_id,
            'action_type' => $action_type,
            'activity_name' => $activity_name,
            'user_ip' => $user_ip,
            'location' => $location
        ]
    );
}

// Track post deletion
function ueht_track_user_delete($post_id) {
    global $wpdb;
    $user_id = get_current_user_id();
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $location = ueht_get_location($user_ip);
    
    $action_type = 'delete';
    $activity_name = 'Post Deleted';

    $wpdb->insert(
        $wpdb->prefix . 'edit_history_logs',
        [
            'user_id' => $user_id,
            'post_id' => $post_id,
            'action_type' => $action_type,
            'activity_name' => $activity_name,
            'user_ip' => $user_ip,
            'location' => $location
        ]
    );
}

// Fetch location based on IP
function ueht_get_location($ip) {
    $api_key = get_option('ueht_api_key'); // Get the API key from the options
    $response = wp_remote_get("https://ipinfo.io/{$ip}/json?token={$api_key}");
    if (is_wp_error($response)) {
        return 'Location not found';
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);

    if (!empty($data->city) && !empty($data->region) && !empty($data->country)) {
        return "{$data->city}, {$data->region}, {$data->country}";
    } else {
        return 'Location not found';
    }
}

// Add Tools submenu for edit history
add_action('admin_menu', 'ueht_admin_menu');
function ueht_admin_menu() {
    add_submenu_page(
        'tools.php',
        'Edit History Logs',
        'Edit History Logs',
        'manage_options',
        'edit-history-logs',
        'ueht_display_logs_page'
    );

    add_submenu_page(
        'tools.php',
        'Edit History Settings',
        'Edit History Settings',
        'manage_options',
        'edit-history-settings',
        'ueht_settings_page'
    );
}

// Display logs on the Tools page with pagination
function ueht_display_logs_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'edit_history_logs';
    
    // Pagination variables
    $limit = 10; // Number of rows per page
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($page - 1) * $limit;

    // Get total rows for pagination
    $total_rows = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $total_pages = ceil($total_rows / $limit);
    
    // Fetch logs for the current page
    $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY action_time DESC LIMIT $limit OFFSET $offset");

    echo '<div class="wrap"><h1>User Edit History</h1>';
    echo '<table class="widefat fixed" cellspacing="0">';
    echo '<thead><tr><th>User</th><th>Post/Page</th><th>Action</th><th>Activity</th><th>IP Address</th><th>Location</th><th>Time</th></tr></thead>';
    echo '<tbody>';

    foreach ($logs as $log) {
        $user_info = get_userdata($log->user_id);
        $user_name = $user_info ? $user_info->user_login : 'Unknown User';
        $post_title = get_the_title($log->post_id);
        
        // Convert action_time to local time
        $action_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->action_time));

        echo "<tr>
                <td>{$user_name}</td>
                <td><a href='" . get_edit_post_link($log->post_id) . "'>{$post_title}</a></td>
                <td>{$log->action_type}</td>
                <td>{$log->activity_name}</td>
                <td>{$log->user_ip}</td>
                <td>{$log->location}</td>
                <td>{$action_time}</td>
              </tr>";
    }

    echo '</tbody></table>';
    
    // Pagination links
    echo '<div class="tablenav"><div class="tablenav-pages">';
    for ($i = 1; $i <= $total_pages; $i++) {
        $class = ($i === $page) ? 'current' : '';
        echo "<span class='tablenav-page $class'><a href='" . esc_url(add_query_arg('paged', $i)) . "'>$i</a></span> ";
    }
    echo '</div></div>'; // Close pagination

    echo '</div>'; // Close wrap
}

// Display the settings page for the API key
function ueht_settings_page() {
    if (isset($_POST['submit'])) {
        update_option('ueht_api_key', sanitize_text_field($_POST['api_key']));
        echo '<div class="updated"><p>API Key saved!</p></div>';
    }
    
    $api_key = get_option('ueht_api_key', '');
    echo '<div class="wrap"><h1>Edit History Settings</h1>';
    echo '<form method="post" action="">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">API Key</th>
                    <td><input type="text" name="api_key" value="' . esc_attr($api_key) . '" style="width: 300px;" /></td>
                </tr>
            </table>
            <p class="submit"><input type="submit" name="submit" class="button-primary" value="Save API Key" /></p>
          </form>';
    echo '<h3>How to Get an API Key</h3>
          <p>You can get your API key from <a href="https://ipinfo.io/signup" target="_blank">IPinfo.io</a>. After signing up, you will find your API key in your dashboard.</p>';
    echo '</div>';
}
