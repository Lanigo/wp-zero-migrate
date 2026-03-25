<?php
/**
 * Plugin Name: WP Zero Migrate
 * Plugin URI: https://github.com/Lanigo/wp-zero-migrate
 * Description: A WordPress migration plugin with export/import support and no artificial size limits.
 * Version: 0.1.0
 * Author: Lanigo
 * Author URI: https://github.com/Lanigo
 */

// Prevent direct access to this file.
defined('ABSPATH') || exit;

// Register a custom top-level admin menu item in the WordPress dashboard.
// I'm not running this function myself right here.
// I'm defining it first, then telling WordPress later when to run it.
function wpzm_register_admin_menu() {

	// Add a new top-level admin menu page.
	add_menu_page(
		'WP Zero Migrate',        // Page title: shown in the browser tab/header area.
		'WP Zero Migrate',        // Menu title: shown in the left admin sidebar.
		'manage_options',         // Capability required to access this page (usually admins only).
		'wp-zero-migrate',        // Menu slug: internal unique identifier for this page.
		'wpzm_render_admin_page', // Function to run when this page is opened.
		'dashicons-migrate'       // WordPress Dashicon for the menu item.
	);
}

// Handle the export form submission and return a result array.
// This lets me return both a message and a message type.
function wpzm_handle_export_action() {

	// If the export button was not clicked, return no result.
	if (!isset($_POST['wpzm_run_export'])) {
		return null;
	}

	// If the nonce is missing or invalid, return an error result.
	if (
		!isset($_POST['wpzm_nonce']) ||
		!wp_verify_nonce($_POST['wpzm_nonce'], 'wpzm_run_export_action')
	) {
		return array(
	        'action'  => 'export',
	        'type'    => 'error',
	        'message' => 'Security check failed.',
        );
	}

	// Define export directory path.
    $export_dir = WP_CONTENT_DIR . '/wpzm-exports';

	// Create a unique timestamp for this export.
	$timestamp = date('Y-m-d-H-i-s');
	$export_path = $export_dir . '/export-' . $timestamp;

    // Ensure base export directory exists.
	if (!file_exists($export_dir)) {
		wp_mkdir_p($export_dir);
	}

	// Create this specific export folder.
	if (!file_exists($export_path)) {
		wp_mkdir_p($export_path);
	}

	// Verify it was created.
	if (!file_exists($export_path)) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to create export folder.',
		);
	}

	// Build the path for a simple export info file.
	$info_file = $export_path . '/export-info.txt';

	// Get the list of active plugins from the database.
	$active_plugins = get_option('active_plugins', array());

	// Get upload directory information.
	$upload_dir = wp_upload_dir();

	// Prepare the future database export file path.
	$database_file = $export_path . '/database.sql';

	// Build structured export data for JSON.
	$manifest_data = array(
		'timestamp'        => $timestamp,
		'site_name'        => get_bloginfo('name'),
		'site_url'         => home_url(),
		'database_name'    => DB_NAME,
		'database_prefix'  => $GLOBALS['wpdb']->prefix,
		'database_file'    => $database_file,
		'wp_version'       => get_bloginfo('version'),
		'php_version'      => PHP_VERSION,
		'server_software'  => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
		'locale'           => get_locale(),
		'theme' => array(
			'name'       => wp_get_theme()->get('Name'),
			'stylesheet' => wp_get_theme()->get_stylesheet(),
		),
		'uploads' => array(
			'basedir' => $upload_dir['basedir'],
			'baseurl' => $upload_dir['baseurl'],
			'subdir'  => $upload_dir['subdir'],
		),
		'plugins' => $active_plugins,
	);

	// Convert the array to JSON.
	$manifest_json = json_encode($manifest_data, JSON_PRETTY_PRINT);
	$manifest_file = $export_path . '/manifest.json';
	$manifest_written = file_put_contents($manifest_file, $manifest_json);

	if ($manifest_written === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to write manifest.json file.',
		);
	}

	// Create the text content we want to save in the file.
	$info_content = "WP Zero Migrate Export\n";
	$info_content .= "Created: " . $timestamp . "\n";
	$info_content .= "Site Name: " . get_bloginfo('name') . "\n";
	$info_content .= "Site URL: " . home_url() . "\n";
	$info_content .= "Database Name: " . DB_NAME . "\n";
	$info_content .= "Database Prefix: " . $GLOBALS['wpdb']->prefix . "\n";
	$info_content .= "Database Export File: " . $database_file . "\n";
	$info_content .= "Upload Base Directory: " . $upload_dir['basedir'] . "\n";
	$info_content .= "Upload Base URL: " . $upload_dir['baseurl'] . "\n";
	$info_content .= "Current Upload Subdirectory: " . $upload_dir['subdir'] . "\n";
	$info_content .= "WordPress Version: " . get_bloginfo('version') . "\n";
	$info_content .= "PHP Version: " . PHP_VERSION . "\n";
	$info_content .= "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
	$info_content .= "Locale: " . get_locale() . "\n";
	$info_content .= "Active Theme: " . wp_get_theme()->get('Name') . "\n";
	$info_content .= "Theme Stylesheet: " . wp_get_theme()->get_stylesheet() . "\n";
	$info_content .= "\n";
	$info_content .= "--------------------\n";
	$info_content .= "Plugins\n";
	$info_content .= "--------------------\n";
	$info_content .= "Active Plugins: " . count($active_plugins) . "\n";
	$info_content .= "Plugin List:\n";

	foreach ($active_plugins as $plugin) {
		$info_content .= " - " . $plugin . "\n";
	}	

	// Start SQL export content.
	$database_content = "-- WP Zero Migrate database export\n";
	$database_content .= "-- Created: " . $timestamp . "\n\n";


// Define which tables to export.
$tables_to_export = array(
	$GLOBALS['wpdb']->prefix . 'options',
	$GLOBALS['wpdb']->prefix . 'posts',
	$GLOBALS['wpdb']->prefix . 'postmeta',
);

	// Export each table in order.
	foreach ($tables_to_export as $table_name) {

		$table_sql = wpzm_export_table_sql($table_name);

		if ($table_sql === false) {
			return array(
				'action'  => 'export',
				'type'    => 'error',
				'message' => 'Failed to export table: ' . $table_name,
			);
		}

		if (empty($table_sql)) {
			return array(
				'action'  => 'export',
				'type'    => 'error',
				'message' => 'Table SQL came back empty for: ' . $table_name,
			);
		}

		$database_content .= $table_sql;
	}

	$database_written = file_put_contents($database_file, $database_content);

	if ($database_written === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to write database.sql placeholder file.',
		);
	}

	// Write the content to the file.
	$file_written = file_put_contents($info_file, $info_content);

	// If writing failed, return an error.
	if ($file_written === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Export folder was created, but export-info.txt could not be written.',
		);
	}

    // Success: directory is ready.
    return array(
	    'action'  => 'export',
	    'type'    => 'success',
	    'message' => 'Export folder, info file, manifest.json, and database.sql created: ' . $export_path,
    );
}

// Convert a PHP value into a basic SQL-safe literal.
function wpzm_format_sql_value($value) {

	if (is_null($value)) {
		return 'NULL';
	}

	if (is_int($value) || is_float($value)) {
		return (string) $value;
	}

	return "'" . addslashes((string) $value) . "'";
}

function wpzm_render_admin_page() {
	// Ask my helper function whether there is any export result to show.
	$result = wpzm_handle_export_action();
	?>

	<div class="wrap">
		<h1>WP Zero Migrate</h1>
		<p>Your migration plugin is alive.</p>

		<?php if (!empty($result)) : ?>
	        <div class="notice notice-<?php echo esc_attr($result['type']); ?>">
		        <p><?php echo esc_html($result['message']); ?></p>
	        </div>
        <?php endif; ?>

        <form method="post">
	        <?php wp_nonce_field('wpzm_run_export_action', 'wpzm_nonce'); ?>
	<p>
		        <button type="submit" name="wpzm_run_export" class="button button-primary">
			Run Export
		        </button>
	        </p>
        </form>
	</div>
	<?php
}

// Hook my menu registration function into WordPress.
// Meaning:
// "When WordPress is building the admin menu, also run wpzm_register_admin_menu."
add_action('admin_menu', 'wpzm_register_admin_menu');

// Export a single database table to SQL format.
function wpzm_export_table_sql($table_name) {

	global $wpdb;

	$sql_content = '';

	// Get table structure.
	$table_structure = $wpdb->get_row("SHOW CREATE TABLE `$table_name`", ARRAY_A);

	if (empty($table_structure['Create Table'])) {
		return false;
	}

	// Add structure section.
	$sql_content .= "-- Exporting table: " . $table_name . "\n";
	$sql_content .= "DROP TABLE IF EXISTS `$table_name`;\n";
	$sql_content .= $table_structure['Create Table'] . ";\n\n";

	// Add insert section header.
	$sql_content .= "-- Inserting data for table: " . $table_name . "\n";

	// Get table rows.
	$rows = $wpdb->get_results("SELECT * FROM `$table_name`", ARRAY_A);

	foreach ($rows as $row) {

		$columns = array();
		$values  = array();

		foreach ($row as $column => $value) {
			$columns[] = "`" . $column . "`";
			$values[]  = wpzm_format_sql_value($value);
		}

		$sql_content .= "INSERT INTO `$table_name` (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ");\n";
	}

	$sql_content .= "\n";

	return $sql_content;
}