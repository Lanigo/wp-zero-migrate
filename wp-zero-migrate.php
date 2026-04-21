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

	// Prepare the future database export file path.
	$database_file = $export_path . '/database.sql';

	// Prepare the future file export directory path.
	$files_export_dir = $export_path . '/files';

	// Prepare the future uploads directory path.
	$uploads_export_dir = $files_export_dir . '/uploads';

	// Get upload directory information.
	$upload_dir = wp_upload_dir();

	// Prepare the future zip export file path.
	$zip_export_file = $export_path . '/export-package.zip';

	// Prepare the active theme export paths.
	$theme = wp_get_theme();
	$active_theme_stylesheet = $theme->get_stylesheet();
	$active_theme_source_dir = get_theme_root() . '/' . $active_theme_stylesheet;
	$themes_export_dir = $files_export_dir . '/themes';
	$active_theme_export_dir = $themes_export_dir . '/' . $active_theme_stylesheet;

	// Prepare the active plugins export paths.
	$plugins_export_dir = $files_export_dir . '/plugins';
	$active_plugin_paths = get_option('active_plugins', array());
	$active_plugin_export_results = array();

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

	// Create the files export directory.
	if (!file_exists($files_export_dir)) {
		wp_mkdir_p($files_export_dir);
	}

	if (!file_exists($files_export_dir)) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to create files export directory.',
		);
	}

	// Create the plugins export directory.
	if (!file_exists($plugins_export_dir)) {
		wp_mkdir_p($plugins_export_dir);
	}

	if (!file_exists($plugins_export_dir)) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to create plugins export directory.',
		);
	}

	// Copy the uploads directory into the export package.
	$uploads_copied = wpzm_copy_directory($upload_dir['basedir'], $uploads_export_dir);

	if ($uploads_copied === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to copy uploads directory.',
		);
	}

	// Copy the active theme directory into the export package.
	$theme_copied = wpzm_copy_directory($active_theme_source_dir, $active_theme_export_dir);

	if ($theme_copied === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to copy active theme directory.',
		);
	}

	// Calculate metrics for the copied active theme directory.
	$theme_export_size = wpzm_get_directory_size($active_theme_export_dir);

	if ($theme_export_size === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to calculate active theme export size.',
		);
	}

	$theme_file_count = wpzm_count_files_in_directory($active_theme_export_dir);

	if ($theme_file_count === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to count files in active theme export directory.',
		);
	}

	// Calculate the size of the copied uploads directory.
	$uploads_export_size = wpzm_get_directory_size($uploads_export_dir);

	if ($uploads_export_size === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to calculate uploads export size.',
		);
	}

	// Count the number of files copied into the uploads export directory.
	$uploads_file_count = wpzm_count_files_in_directory($uploads_export_dir);

	if ($uploads_file_count === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to count files in uploads export directory.',
		);
	}

	// Copy each active plugin directory into the export package.
	foreach ($active_plugin_paths as $plugin_path) {

		$plugin_folder = dirname($plugin_path);

		// Skip strange root-level plugin file cases for now.
		if ($plugin_folder === '.' || empty($plugin_folder)) {
			continue;
		}

		$plugin_source_dir = WP_PLUGIN_DIR . '/' . $plugin_folder;
		$plugin_export_dir = $plugins_export_dir . '/' . $plugin_folder;

		$plugin_copied = wpzm_copy_directory($plugin_source_dir, $plugin_export_dir);

		if ($plugin_copied === false) {
			return array(
				'action'  => 'export',
				'type'    => 'error',
				'message' => 'Failed to copy active plugin directory: ' . $plugin_folder,
			);
		}

		$active_plugin_export_results[] = array(
			'plugin_path' => $plugin_path,
			'folder'      => $plugin_folder,
			'source_dir'  => $plugin_source_dir,
			'export_dir'  => $plugin_export_dir,
			'copied'      => true,
		);
	}

	// Calculate metrics for the exported active plugins directory.
	$plugins_export_size = wpzm_get_directory_size($plugins_export_dir);

	if ($plugins_export_size === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to calculate plugins export size.',
		);
	}

	$plugins_file_count = wpzm_count_files_in_directory($plugins_export_dir);

	if ($plugins_file_count === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to count files in plugins export directory.',
		);
	}

	// Build the path for a simple export info file.
	$info_file = $export_path . '/export-info.txt';

	// Get the list of active plugins from the database.
	$active_plugins = get_option('active_plugins', array());

	// Build structured export data for JSON.
	$manifest_data = array(
		'timestamp'        => $timestamp,
		'site_name'        => get_bloginfo('name'),
		'site_url'         => home_url(),
		'database_name'    => DB_NAME,
		'database_prefix'  => $GLOBALS['wpdb']->prefix,
		'database_file'    => $database_file,
		'files_export_dir' => $files_export_dir,
		'zip_export_file'     => $zip_export_file,
		'uploads_copied'   => $uploads_copied,
		'uploads_export_size' => $uploads_export_size,
		'uploads_file_count'  => $uploads_file_count,
		'wp_version'       => get_bloginfo('version'),
		'php_version'      => PHP_VERSION,
		'server_software'  => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
		'locale'           => get_locale(),
		'theme' => array(
			'name'       => wp_get_theme()->get('Name'),
			'stylesheet' => wp_get_theme()->get_stylesheet(),
			'template'   => wp_get_theme()->get_template(),
			'source_dir'       => $active_theme_source_dir,
			'export_dir'       => $active_theme_export_dir,
			'copied'           => $theme_copied,
			'export_size'      => $theme_export_size,
			'file_count'       => $theme_file_count,
		),
		'uploads' => array(
			'basedir' => $upload_dir['basedir'],
			'baseurl' => $upload_dir['baseurl'],
			'subdir'  => $upload_dir['subdir'],
			'export_dir' => $uploads_export_dir,
			'copied'  => $theme_copied,
			'export_size'      => $theme_export_size,
			'file_count'       => $theme_file_count,
		),
		'plugins' => array(
			'active_plugin_paths'   => $active_plugin_paths,
			'plugins_export_dir'    => $plugins_export_dir,
			'export_size'           => $plugins_export_size,
			'file_count'            => $plugins_file_count,
			'exported_plugins'      => $active_plugin_export_results,
		),
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
	$info_content .= "Files Export Directory: " . $files_export_dir . "\n";
	$info_content .= "Zip Export File: " . $zip_export_file . "\n";
	$info_content .= "Upload Base Directory: " . $upload_dir['basedir'] . "\n";
	$info_content .= "Upload Base URL: " . $upload_dir['baseurl'] . "\n";
	$info_content .= "Current Upload Subdirectory: " . $upload_dir['subdir'] . "\n";
	$info_content .= "Uploads Export Directory: " . $uploads_export_dir . "\n";
	$info_content .= "Uploads Copied: " . ($uploads_copied ? 'Yes' : 'No') . "\n";
	$info_content .= "Uploads Export Size (bytes): " . $uploads_export_size . "\n";
	$info_content .= "Uploads File Count: " . $uploads_file_count . "\n";
	$info_content .= "WordPress Version: " . get_bloginfo('version') . "\n";
	$info_content .= "PHP Version: " . PHP_VERSION . "\n";
	$info_content .= "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
	$info_content .= "Locale: " . get_locale() . "\n";
	$info_content .= "Active Theme: " . wp_get_theme()->get('Name') . "\n";
	$info_content .= "Theme Stylesheet: " . wp_get_theme()->get_stylesheet() . "\n";
	$info_content .= "Theme Source Directory: " . $active_theme_source_dir . "\n";
	$info_content .= "Theme Export Directory: " . $active_theme_export_dir . "\n";
	$info_content .= "Theme Copied: " . ($theme_copied ? 'Yes' : 'No') . "\n";
	$info_content .= "Theme Export Size (bytes): " . $theme_export_size . "\n";
	$info_content .= "Theme File Count: " . $theme_file_count . "\n";
	$info_content .= "\n";
	$info_content .= "--------------------\n";
	$info_content .= "Plugins\n";
	$info_content .= "--------------------\n";
	$info_content .= "Active Plugins: " . count($active_plugin_paths) . "\n";
	$info_content .= "Plugins Export Directory: " . $plugins_export_dir . "\n";
	$info_content .= "Plugins Export Size (bytes): " . $plugins_export_size . "\n";
	$info_content .= "Plugins File Count: " . $plugins_file_count . "\n";
	$info_content .= "Plugin List:\n";

	foreach ($active_plugin_export_results as $plugin_result) {
		$info_content .= "- " . $plugin_result['plugin_path'] . "\n";
		$info_content .= "  Source: " . $plugin_result['source_dir'] . "\n";
		$info_content .= "  Export: " . $plugin_result['export_dir'] . "\n";
	}		

	// Start SQL export content.
	$database_content = "-- WP Zero Migrate database export\n";
	$database_content .= "-- Created: " . $timestamp . "\n\n";


// Define which tables to export.
$tables_to_export = array(
	$GLOBALS['wpdb']->prefix . 'options',
	$GLOBALS['wpdb']->prefix . 'posts',
	$GLOBALS['wpdb']->prefix . 'postmeta',
	$GLOBALS['wpdb']->prefix . 'users',
	$GLOBALS['wpdb']->prefix . 'usermeta',
	$GLOBALS['wpdb']->prefix . 'terms',
	$GLOBALS['wpdb']->prefix . 'term_taxonomy',
	$GLOBALS['wpdb']->prefix . 'term_relationships',
	$GLOBALS['wpdb']->prefix . 'comments',
	$GLOBALS['wpdb']->prefix . 'commentmeta',
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

	// Define which export artifacts should go into the zip package.
	$zip_items = array(
		$info_file,
		$manifest_file,
		$database_file,
		$files_export_dir,
	);

	$zip_created = wpzm_create_zip_archive($zip_export_file, $export_path, $zip_items);

	if ($zip_created === false) {
		return array(
			'action'  => 'export',
			'type'    => 'error',
			'message' => 'Failed to create export-package.zip.',
		);
	}

	// Store the latest successful zip export path.
	update_option('wpzm_latest_zip_export_file', $zip_export_file);

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
	    'message' => 'Export folder, files, metadata, database.sql, and export-package.zip created: ' . $export_path,
    );
}

// Convert a PHP value into a SQL-safe literal for export statements.
function wpzm_format_sql_value($value) {

	global $wpdb;

	if (is_null($value)) {
		return 'NULL';
	}

	if (is_int($value) || is_float($value)) {
		return (string) $value;
	}

	// Escape string values using the active database connection so exported SQL
	// is safer and more portable than a simple addslashes() approach.
	return "'" . $wpdb->_real_escape((string) $value) . "'";
}

// Recursively copy a directory and its contents.
function wpzm_copy_directory($source, $destination) {

	if (!is_dir($source)) {
		return false;
	}

	if (!file_exists($destination)) {
		wp_mkdir_p($destination);
	}

	$items = scandir($source);

	if ($items === false) {
		return false;
	}

	foreach ($items as $item) {

		if ($item === '.' || $item === '..' || $item === '.git') {
			continue;
		}

		$source_path = $source . '/' . $item;
		$destination_path = $destination . '/' . $item;

		if (is_dir($source_path)) {
			$result = wpzm_copy_directory($source_path, $destination_path);

			if ($result === false) {
				return false;
			}
		} else {
			if (!copy($source_path, $destination_path)) {
				return false;
			}
		}
	}

	return true;
}

// Recursively calculate the total size of a directory.
function wpzm_get_directory_size($directory) {

	if (!is_dir($directory)) {
		return false;
	}

	$total_size = 0;
	$items = scandir($directory);

	if ($items === false) {
		return false;
	}

	foreach ($items as $item) {

		if ($item === '.' || $item === '..') {
			continue;
		}

		$item_path = $directory . '/' . $item;

		if (is_dir($item_path)) {
			$subdirectory_size = wpzm_get_directory_size($item_path);

			if ($subdirectory_size === false) {
				return false;
			}

			$total_size += $subdirectory_size;
		} else {
			$total_size += filesize($item_path);
		}
	}

	return $total_size;
}

// Recursively count the number of files in a directory.
function wpzm_count_files_in_directory($directory) {

	if (!is_dir($directory)) {
		return false;
	}

	$file_count = 0;
	$items = scandir($directory);

	if ($items === false) {
		return false;
	}

	foreach ($items as $item) {

		if ($item === '.' || $item === '..') {
			continue;
		}

		$item_path = $directory . '/' . $item;

		if (is_dir($item_path)) {
			$subdirectory_count = wpzm_count_files_in_directory($item_path);

			if ($subdirectory_count === false) {
				return false;
			}

			$file_count += $subdirectory_count;
		} else {
			$file_count++;
		}
	}

	return $file_count;
}

// Create a zip archive from a directory and selected files.
function wpzm_create_zip_archive($zip_file, $export_path, $files_to_include) {

	if (!class_exists('ZipArchive')) {
		return false;
	}

	$zip = new ZipArchive();

	if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		return false;
	}

	$normalized_export_path = wp_normalize_path($export_path);

	foreach ($files_to_include as $item) {

		if (!file_exists($item)) {
			continue;
		}

		// Add a single file.
		if (is_file($item)) {
			$normalized_item = wp_normalize_path($item);
			$local_name = str_replace($normalized_export_path . '/', '', $normalized_item);
			$zip->addFile($normalized_item, $local_name);
			continue;
		}

		// Add a directory and its contents.
		if (is_dir($item)) {
			$normalized_item = wp_normalize_path($item);
			$local_dir = str_replace($normalized_export_path . '/', '', $normalized_item);

			// Add the directory itself so empty folders are preserved.
			$zip->addEmptyDir($local_dir);

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($item, RecursiveDirectoryIterator::SKIP_DOTS),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ($iterator as $file) {
				$file_path = wp_normalize_path($file->getPathname());
				$local_name = str_replace($normalized_export_path . '/', '', $file_path);

				if ($file->isDir()) {
					$zip->addEmptyDir($local_name);
				} else {
					$zip->addFile($file_path, $local_name);
				}
			}
		}
	}

	$zip->close();

	return file_exists($zip_file);
}

// Handle the import form submission and return a result array.
function wpzm_handle_import_action() {

	// Allow import to run either from upload OR from a predefined server path (temporary test mode).
	if (!isset($_POST['wpzm_run_import'])) {
		return null;
	}


	// Verify the nonce before processing the import submission.
	if (
		!isset($_POST['wpzm_import_nonce']) ||
		!wp_verify_nonce($_POST['wpzm_import_nonce'], 'wpzm_import_package_action')
	) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Import security check failed.',
		);
	}

	// Allow the import to use either an uploaded zip file or a server path.
	$import_server_path = isset($_POST['wpzm_import_server_path'])
		? trim(wp_unslash($_POST['wpzm_import_server_path']))
		: '';

	$has_uploaded_zip = (
		isset($_FILES['wpzm_import_zip']) &&
		!empty($_FILES['wpzm_import_zip']['name'])
	);

	$has_server_zip_path = ($import_server_path !== '');

	if (!$has_uploaded_zip && !$has_server_zip_path) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Please choose a zip file or enter a server path to a zip file.',
		);
	}

		// Validate the server path before trying to copy from it.
	if ($has_server_zip_path) {
		if (!file_exists($import_server_path)) {
			return array(
				'action'  => 'import',
				'type'    => 'error',
				'message' => 'The server zip path does not exist.',
			);
		}

		if (!is_file($import_server_path)) {
			return array(
				'action'  => 'import',
				'type'    => 'error',
				'message' => 'The server zip path is not a file.',
			);
		}

		if (!is_readable($import_server_path)) {
			return array(
				'action'  => 'import',
				'type'    => 'error',
				'message' => 'The server zip file is not readable.',
			);
		}

		if (strtolower(pathinfo($import_server_path, PATHINFO_EXTENSION)) !== 'zip') {
			return array(
				'action'  => 'import',
				'type'    => 'error',
				'message' => 'The server path must point to a .zip file.',
			);
		}
	}

	$original_destination_site_url = home_url();

	// Save a temporary checkpoint so silent failures can be traced after the request dies.
	update_option('wpzm_import_debug_checkpoint', 'Import request started');

	// Prevent WP-Cron from spawning during this import request.
	// This avoids shutdown-time database noise while the import is still using the DB connection heavily.
	remove_action('shutdown', 'wp_ob_end_flush_all', 1);
	add_filter('wp_doing_cron', '__return_false');

	// Collect non-fatal issues that should be shown in the final import report.
	$import_warnings = array();

	// Record the major import stages that completed, so the final result can
	// show a more trustworthy step-by-step summary.
	$import_steps = array();

	// Provide post-import guidance so the developer knows what to verify next.
	$next_actions = array();

	// Track plugin activation-specific issues separately so they can be shown
	// in their own section instead of being buried inside general warnings.
	$plugin_activation_issues = array();

	// Track whether URL replacement completed, was skipped, or failed.
	$url_replacement_status = 'not_started';

	// Track the outcome of the uploads count comparison so the final report can
	// say whether the destination count matched, was lower, or was higher.
	$uploads_count_comparison_status = 'not_checked';

	// Track the overall plugin restoration outcome so the final report can say
	// whether plugins were restored cleanly, with issues, or skipped.
	$plugin_restoration_status = 'not_started';

	// Track a simple top-level health status for the import report UI.
	$import_health_status = 'healthy';

	// Store human-readable reasons explaining WHY a migration is not fully healthy.
	// This allows the UI to explain issues instead of forcing the user to investigate.
	$import_health_reasons = array();

	// Create a unique ID for this import run so checklist progress can be
	// stored separately for each report instead of leaking across imports.
	$import_id = time();

	// Create a timestamped import working directory.
	$import_base_dir = WP_CONTENT_DIR . '/wpzm-imports';
	$timestamp = date('Y-m-d-H-i-s');
	$import_path = $import_base_dir . '/import-' . $timestamp;

	if (!file_exists($import_base_dir)) {
		wp_mkdir_p($import_base_dir);
	}

	if (!file_exists($import_path)) {
		wp_mkdir_p($import_path);
	}

	if (!file_exists($import_path)) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to create import working directory.',
		);
	}

	$uploaded_zip_path = $import_path . '/import-package.zip';

	// Copy the chosen zip source into the import working directory.
	if ($has_uploaded_zip) {
		$zip_stored = move_uploaded_file(
			$_FILES['wpzm_import_zip']['tmp_name'],
			$uploaded_zip_path
		);
	} else {
		$zip_stored = copy($import_server_path, $uploaded_zip_path);
	}

	if ($zip_stored === false) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to store the import zip file.',
		);
	}

	$extracted_path = $import_path . '/extracted';

	if (!file_exists($extracted_path)) {
		wp_mkdir_p($extracted_path);
	}

	if (!file_exists($extracted_path)) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to create extraction directory.',
		);
	}
	
	if (!class_exists('ZipArchive')) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'ZipArchive is not available on this server.',
		);
	}

	$zip = new ZipArchive();

	if ($zip->open($uploaded_zip_path) !== true) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to open uploaded zip package.',
		);
	}

	$zip_extracted = $zip->extractTo($extracted_path);

	// Record that the uploaded package was extracted successfully.
	update_option('wpzm_import_debug_checkpoint', 'Zip extracted');

	$zip->close();

	if ($zip_extracted === false) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to extract import package.',
		);
	}

	$manifest_file = $extracted_path . '/manifest.json';
	$database_file = $extracted_path . '/database.sql';
	$files_dir = $extracted_path . '/files';
	$uploads_dir = $files_dir . '/uploads';
	$themes_dir = $files_dir . '/themes';
	$plugins_dir = $files_dir . '/plugins';

	if (!file_exists($manifest_file)) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Import package is missing manifest.json.',
		);
	}

	if (!file_exists($database_file)) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Import package is missing database.sql.',
		);
	}

	if (!is_dir($files_dir)) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Import package is missing the files directory.',
		);
	}

	if (!is_dir($uploads_dir)) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Import package is missing the uploads directory.',
		);
	}

	if (!is_dir($themes_dir)) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Import package is missing the themes directory.',
		);
	}

	if (!is_dir($plugins_dir)) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Import package is missing the plugins directory.',
		);
	}

	$manifest_content = file_get_contents($manifest_file);

	if ($manifest_content === false) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to read manifest.json.',
		);
	}

	$manifest_data = json_decode($manifest_content, true);

	// Record that the manifest file was read and decoded.
	update_option('wpzm_import_debug_checkpoint', 'Manifest decoded');

	if ($manifest_data === null) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Invalid JSON in manifest.json.',
		);
	}

	$site_name = isset($manifest_data['site_name']) ? $manifest_data['site_name'] : 'Unknown Site';
	$source_site_url = isset($manifest_data['site_url']) ? $manifest_data['site_url'] : '';
	$destination_site_url = $original_destination_site_url;
	$theme_name = isset($manifest_data['theme']['name']) ? $manifest_data['theme']['name'] : 'Unknown Theme';
	$source_database_prefix = isset($manifest_data['database_prefix']) ? $manifest_data['database_prefix'] : '';
	$destination_database_prefix = $GLOBALS['wpdb']->prefix;

	// Detect whether imported SQL table names need to be remapped from the source
	// database prefix to the current destination site's database prefix.
	$prefix_remap_needed = (
		!empty($source_database_prefix) &&
		!empty($destination_database_prefix) &&
		$source_database_prefix !== $destination_database_prefix
	);

	if (empty($source_database_prefix)) {
		$import_warnings[] = 'Source database prefix was missing from the manifest.';
	}

	$theme_stylesheet = isset($manifest_data['theme']['stylesheet']) ? $manifest_data['theme']['stylesheet'] : '';
	$theme_template = isset($manifest_data['theme']['template']) ? $manifest_data['theme']['template'] : '';
	$active_plugin_paths = isset($manifest_data['plugins']['active_plugin_paths']) && is_array($manifest_data['plugins']['active_plugin_paths']) ? $manifest_data['plugins']['active_plugin_paths'] : array();

	// Detect whether Elementor was active on the source site so post-import
	// guidance can suggest Elementor-specific cleanup only when relevant.
	$source_site_used_elementor = in_array('elementor/elementor.php', $active_plugin_paths, true);

	$active_plugin_count = isset($manifest_data['plugins']['active_plugin_paths']) ? count($manifest_data['plugins']['active_plugin_paths']) : 0;
	$uploads_copied = isset($manifest_data['uploads_copied']) ? $manifest_data['uploads_copied'] : false;
	$uploads_file_count = isset($manifest_data['uploads_file_count']) ? $manifest_data['uploads_file_count'] : 0;
	$uploads_export_size = isset($manifest_data['uploads_export_size']) ? $manifest_data['uploads_export_size'] : 0;

	// Warn when key manifest values are missing or look incomplete.
	// These are not hard failures yet, but they reduce confidence in the package.
	if (!isset($manifest_data['uploads_file_count'])) {
		$import_warnings[] = 'Uploads file count was missing from the manifest.';
	}

	if (!isset($manifest_data['uploads_export_size'])) {
		$import_warnings[] = 'Uploads export size was missing from the manifest.';
	}

	if (!isset($manifest_data['uploads_copied'])) {
		$import_warnings[] = 'Uploads copied status was missing from the manifest.';
	}

	if (!isset($manifest_data['plugins']['active_plugin_paths'])) {
		$import_warnings[] = 'Active plugin paths were missing from the manifest.';
	}

	if (!isset($manifest_data['theme']['stylesheet'])) {
		$import_warnings[] = 'Theme stylesheet was missing from the manifest.';
	}

	// Warn when manifest values exist but suggest the export may be incomplete.
	if ($uploads_copied === false) {
		$import_warnings[] = 'Manifest reports that uploads were not copied successfully during export.';
	}

	if ($uploads_file_count === 0) {
		$import_warnings[] = 'Manifest reports zero uploaded files.';
	}

	if ($uploads_export_size === 0) {
		$import_warnings[] = 'Manifest reports zero upload bytes.';
	}

	$actual_destination_uploads_file_count = null;

	$destination_upload_dir = wp_upload_dir();
	$destination_uploads_dir = $destination_upload_dir['basedir'];
	$destination_themes_dir = get_theme_root();
	$destination_plugins_dir = WP_PLUGIN_DIR;

	$import_steps[] = 'Import package validated';

	if ($prefix_remap_needed) {
		$import_steps[] = 'Database prefix remap detected';
	} else {
		$import_steps[] = 'Database prefix remap not needed';
	}

	// Record that manifest quality checks were completed before import work begins.
	$import_steps[] = 'Manifest metadata reviewed';

	// Build a shorter high-level summary for the admin UI.
	$summary_message = 'Import completed. ';
	$summary_message .= 'Site: ' . $site_name . '. ';
	$summary_message .= 'Theme: ' . $theme_name . '. ';
	$summary_message .= 'Active Plugins: ' . $active_plugin_count . '. ';
	$summary_message .= 'Source DB Prefix: ' . (!empty($source_database_prefix) ? $source_database_prefix : '[missing]') . '. ';
	$summary_message .= 'Destination DB Prefix: ' . (!empty($destination_database_prefix) ? $destination_database_prefix : '[missing]') . '. ';
	$summary_message .= 'Prefix Remap Needed: ' . ($prefix_remap_needed ? 'Yes' : 'No') . '.';

	$uploads_imported = wpzm_copy_directory($uploads_dir, $destination_uploads_dir);

	// Record that uploads import has started.
	update_option('wpzm_import_debug_checkpoint', 'Uploads copied');

	if ($uploads_imported === false) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to import uploads into destination uploads directory.',
		);
	}

	$import_steps[] = 'Uploads imported';

	// Count the files now present in the destination uploads directory so the
	// import report can compare the real result against the manifest metadata.
	$actual_destination_uploads_file_count = wpzm_count_files_in_directory($destination_uploads_dir);

	if ($actual_destination_uploads_file_count === false) {
		$import_warnings[] = 'Failed to count files in the destination uploads directory after import.';
	} else {
		$import_steps[] = 'Destination uploads counted';
	}

	if (
		$actual_destination_uploads_file_count !== false &&
		$actual_destination_uploads_file_count !== null &&
		$uploads_file_count > 0
	) {
		if ($actual_destination_uploads_file_count === $uploads_file_count) {
			$uploads_count_comparison_status = 'matched';
			$import_steps[] = 'Uploads count matched manifest';
		} elseif ($actual_destination_uploads_file_count < $uploads_file_count) {
			$uploads_count_comparison_status = 'lower';
			$import_warnings[] = 'Destination uploads file count is lower than the manifest count. Manifest: ' . $uploads_file_count . '. Destination: ' . $actual_destination_uploads_file_count . '.';
			$import_steps[] = 'Uploads count was lower than manifest';
		} else {
			$uploads_count_comparison_status = 'higher';
			$import_steps[] = 'Uploads count was higher than manifest';
		}
	}

	if (
		$actual_destination_uploads_file_count !== false &&
		$actual_destination_uploads_file_count !== null &&
		$uploads_file_count <= 0
	) {
		$import_warnings[] = 'Uploads count comparison could not be completed because the manifest uploads file count was zero or missing.';
	}

	if ($actual_destination_uploads_file_count !== false && $actual_destination_uploads_file_count !== null) {
		$summary_message .= ' Destination Uploads File Count: ' . $actual_destination_uploads_file_count . '.';

		if ($uploads_count_comparison_status === 'matched') {
			$summary_message .= ' Uploads Count Match: Yes.';
		} elseif ($uploads_count_comparison_status === 'lower') {
			$summary_message .= ' Uploads Count Match: No. Destination count is lower than manifest.';
		} elseif ($uploads_count_comparison_status === 'higher') {
			$summary_message .= ' Uploads Count Match: No. Destination count is higher than manifest.';
		}
	}

	$themes_imported = wpzm_copy_directory($themes_dir, $destination_themes_dir);

	// Record that theme files import has completed.
	update_option('wpzm_import_debug_checkpoint', 'Themes copied');

	if ($themes_imported === false) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to import themes into destination themes directory.',
		);
	}

	$import_steps[] = 'Themes imported';

	if (!empty($theme_stylesheet)) {
		$expected_theme_dir = $destination_themes_dir . '/' . $theme_stylesheet;

		if (!is_dir($expected_theme_dir)) {
			return array(
				'action'  => 'import',
				'type'    => 'error',
				'message' => 'Theme files were copied, but the expected active theme folder was not found: ' . $theme_stylesheet,
			);
		}
	}

	if (!empty($theme_stylesheet)) {
		$stylesheet_updated = update_option('stylesheet', $theme_stylesheet);

		if ($stylesheet_updated === false && get_option('stylesheet') !== $theme_stylesheet) {
			return array(
				'action'  => 'import',
				'type'    => 'error',
				'message' => 'Theme files were imported, but failed to restore the active theme stylesheet option.',
			);
		}
	}

	if (!empty($theme_template)) {
		$template_updated = update_option('template', $theme_template);

		if ($template_updated === false && get_option('template') !== $theme_template) {
			return array(
				'action'  => 'import',
				'type'    => 'error',
				'message' => 'Theme files were imported, but failed to restore the active theme template option.',
			);
		}
	}

	if (!empty($theme_stylesheet)) {
		switch_theme($theme_stylesheet);

		if (get_option('stylesheet') !== $theme_stylesheet) {
			return array(
				'action'  => 'import',
				'type'    => 'error',
				'message' => 'Theme options were restored, but WordPress failed to switch to the imported theme.',
			);
		}

		$import_steps[] = 'Theme restored';
	} else {
		$import_warnings[] = 'Theme stylesheet was missing from the manifest, so theme restoration was skipped.';
		$import_steps[] = 'Theme restoration skipped';
	}

	// Copy packaged plugins into the destination plugins directory, but protect
	// WP Zero Migrate itself from being overwritten by an older bundled copy.
	$plugin_directories = scandir($plugins_dir);

	// Record that theme restoration finished and plugin import is about to begin.
	update_option('wpzm_import_debug_checkpoint', 'Preparing plugin import');

	if ($plugin_directories === false) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to read the packaged plugins directory.',
		);
	}

	$plugins_imported = true;

	foreach ($plugin_directories as $plugin_directory_name) {
		if ($plugin_directory_name === '.' || $plugin_directory_name === '..') {
			continue;
		}

		if ($plugin_directory_name === 'wp-zero-migrate') {
			continue;
		}

		$source_plugin_directory = $plugins_dir . '/' . $plugin_directory_name;
		$destination_plugin_directory = $destination_plugins_dir . '/' . $plugin_directory_name;

		if (!is_dir($source_plugin_directory)) {
			continue;
		}

		$single_plugin_imported = wpzm_copy_directory($source_plugin_directory, $destination_plugin_directory);

		if ($single_plugin_imported === false) {
			$plugins_imported = false;
			return array(
				'action'  => 'import',
				'type'    => 'error',
				'message' => 'Failed to import plugin directory: ' . $plugin_directory_name,
			);
		}
	}

	$import_steps[] = 'Plugin files imported';
	$import_steps[] = 'Protected WP Zero Migrate from plugin overwrite';

	$sql_statements = wpzm_parse_sql_statements($database_file);

	// Record that file copying finished and SQL parsing is about to begin.
	update_option('wpzm_import_debug_checkpoint', 'Preparing SQL parse');

	// Record that the SQL file was parsed into statements.
	update_option('wpzm_import_debug_checkpoint', 'SQL parsed');

	if ($sql_statements === false) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to parse database.sql into executable statements.',
		);
	}

	$sql_statement_count = count($sql_statements);

	$import_steps[] = 'Database imported';

	global $wpdb;

	if (empty($sql_statements)) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'No SQL statements were available to execute.',
		);
	}

	$executed_sql_count = 0;

	// Record that SQL execution is about to begin.
	update_option('wpzm_import_debug_checkpoint', 'Starting SQL execution');

	// Execute each parsed SQL statement in order, remapping table prefixes when needed.
	foreach ($sql_statements as $index => $sql_statement) {

		if (!empty($source_database_prefix) && !empty($destination_database_prefix)) {
			$sql_statement = str_replace(
				'`' . $source_database_prefix,
				'`' . $destination_database_prefix,
				$sql_statement
			);
		}

		$sql_result = $wpdb->query($sql_statement);

		if ($sql_result === false) {
			// Keep the SQL preview short so the error message is useful without becoming unreadable.
			$sql_preview = substr(preg_replace('/\s+/', ' ', trim($sql_statement)), 0, 200);

			return array(
				'action'  => 'import',
				'type'    => 'error',
				'message' => 'SQL import failed at statement #' . ($index + 1) . '. MySQL error: ' . $wpdb->last_error . '. SQL preview: ' . $sql_preview,
			);
		}

		$executed_sql_count++;

		// Persist the current SQL statement count so silent failures show how far import got.
		update_option('wpzm_import_debug_checkpoint', 'Executed SQL statement #' . $executed_sql_count);
	}

	$summary_message .= ' SQL Statements Executed: ' . $executed_sql_count . '.';

	// After the database import, update the destination site URL and replace
	// old source URLs across key WordPress tables and meta values.
		if (!empty($source_site_url) && !empty($destination_site_url)) {
		update_option('siteurl', $destination_site_url);
		update_option('home', $destination_site_url);

		$options_replaced = wpzm_replace_url_in_options($source_site_url, $destination_site_url);
		$posts_replaced = wpzm_replace_url_in_posts($source_site_url, $destination_site_url);
		$postmeta_replaced = wpzm_replace_url_in_meta_table($wpdb->postmeta, 'meta_id', 'meta_value', $source_site_url, $destination_site_url);
		$usermeta_replaced = wpzm_replace_url_in_meta_table($wpdb->usermeta, 'umeta_id', 'meta_value', $source_site_url, $destination_site_url);
		$commentmeta_replaced = wpzm_replace_url_in_meta_table($wpdb->commentmeta, 'meta_id', 'meta_value', $source_site_url, $destination_site_url);

		if (
			$options_replaced === false ||
			$posts_replaced === false ||
			$postmeta_replaced === false ||
			$usermeta_replaced === false ||
			$commentmeta_replaced === false
		) {
			return array(
				'action'  => 'import',
				'type'    => 'error',
				'message' => 'Database import succeeded, but URL replacement failed.',
			);
		}

		$url_replacement_status = 'completed';
		$import_steps[] = 'URL replacement completed';

		$summary_message .= ' Site URL Updated: Yes.';
	} else {
		// If either site URL is missing, skip replacement and report it clearly.
		$url_replacement_status = 'skipped';
		$summary_message .= ' Site URL Updated: No.';
		$import_warnings[] = 'Source site URL or destination site URL was missing, so URL replacement did not run.';
		$import_steps[] = 'URL replacement skipped';
	}

	// Restore the active plugin state after the database and URL work is complete.
	// This keeps plugin dependency issues from interrupting the more critical import stages.
	if (!function_exists('activate_plugin') || !function_exists('is_plugin_active') || !function_exists('deactivate_plugins')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$protected_plugins = array(
		'wp-zero-migrate/wp-zero-migrate.php',
	);

	$current_active_plugins = get_option('active_plugins', array());

	if (!is_array($current_active_plugins)) {
		$current_active_plugins = array();
	}

	$plugin_activation_warnings = array();

	foreach ($current_active_plugins as $current_plugin_path) {
		if (in_array($current_plugin_path, $protected_plugins, true)) {
			continue;
		}

		if (!in_array($current_plugin_path, $active_plugin_paths, true) && is_plugin_active($current_plugin_path)) {
			deactivate_plugins($current_plugin_path, true);
		}
	}

	if (!empty($active_plugin_paths)) {
		foreach ($active_plugin_paths as $plugin_path) {
			if (in_array($plugin_path, $protected_plugins, true) && is_plugin_active($plugin_path)) {
				continue;
			}

			if (is_plugin_active($plugin_path)) {
				continue;
			}

			$activation_result = activate_plugin($plugin_path);

			if (is_wp_error($activation_result)) {
				$plugin_activation_issues[] = 'Activation failed for: ' . $plugin_path . '. Error: ' . $activation_result->get_error_message();
				continue;
			}
		}
	}

	if (!empty($active_plugin_paths)) {
		foreach ($active_plugin_paths as $plugin_path) {
			$plugin_folder = dirname($plugin_path);

			if ($plugin_folder === '.' || empty($plugin_folder)) {
				continue;
			}

			$expected_plugin_dir = $destination_plugins_dir . '/' . $plugin_folder;

			if (!is_dir($expected_plugin_dir)) {
				return array(
					'action'  => 'import',
					'type'    => 'error',
					'message' => 'Plugin files were copied, but expected active plugin folder was not found: ' . $plugin_folder,
				);
			}
		}
	}

	if (!empty($active_plugin_paths)) {
		foreach ($active_plugin_paths as $plugin_path) {
			$expected_plugin_file = $destination_plugins_dir . '/' . $plugin_path;

			if (!file_exists($expected_plugin_file)) {
				return array(
					'action'  => 'import',
					'type'    => 'error',
					'message' => 'Plugin folder exists, but expected active plugin file was not found: ' . $plugin_path,
				);
			}
		}
	}

	if (!empty($active_plugin_paths)) {
		if (!empty($plugin_activation_issues)) {
			$plugin_restoration_status = 'completed_with_issues';
			$import_steps[] = 'Plugin restoration completed with activation issues';
		} else {
			$plugin_restoration_status = 'completed_cleanly';
			$import_steps[] = 'Plugin restoration completed cleanly';
		}
	} else {
		$plugin_restoration_status = 'skipped';
		$import_warnings[] = 'No active plugin paths were found in the manifest, so plugin restoration was skipped.';
		$import_steps[] = 'Plugin restoration skipped';
	}

	// Keep the summary message focused on the high-level import outcome.
	// Detailed warnings, completed steps, and next actions are rendered separately in the admin UI.

		// Build post-import checklist items in two groups:
	// items that are always worth checking, and items that only matter when
	// a specific condition or issue was detected during import.
	$always_check_actions = array();
	$only_if_needed_actions = array();

	$always_check_actions[] = 'Visit the site frontend and confirm the imported theme, menus, and styling are loading correctly.';
	$always_check_actions[] = 'Open Settings > Permalinks and resave permalinks if pages or posts are not loading correctly.';
	$always_check_actions[] = 'Check the Media Library and key pages to confirm uploads were imported correctly.';

	if ($source_site_used_elementor) {
		$only_if_needed_actions[] = 'Elementor was active on the source site. Run Elementor URL replacement and regenerate CSS if needed.';
	}

	// Only show plugin follow-up guidance when activation problems actually occurred.
	if (!empty($plugin_activation_issues)) {
		$only_if_needed_actions[] = 'Review plugin activation issues and dependency order, especially WooCommerce add-ons that may require WooCommerce to be active first.';
	}

	if ($uploads_count_comparison_status === 'lower') {
		$only_if_needed_actions[] = 'Check the uploads folder because fewer files were found in the destination site than expected.';
	}

	if ($url_replacement_status === 'skipped') {
		$only_if_needed_actions[] = 'Review site URLs manually because automatic URL replacement did not run.';
	}

	$next_actions = array(
		'always_check'   => array(),
		'only_if_needed' => array(),
	);

	if (!empty($always_check_actions)) {
		$next_actions['always_check'] = $always_check_actions;
	}

	if (!empty($only_if_needed_actions)) {
		$next_actions['only_if_needed'] = $only_if_needed_actions;
	}

	if ($url_replacement_status === 'not_started') {
		$import_warnings[] = 'URL replacement status was never finalized during import.';
	}

	if ($plugin_restoration_status === 'completed_cleanly') {
		$summary_message .= ' Plugin Restoration: Completed cleanly.';
	} elseif ($plugin_restoration_status === 'completed_with_issues') {
		$summary_message .= ' Plugin Restoration: Completed with activation issues.';
	} elseif ($plugin_restoration_status === 'skipped') {
		$summary_message .= ' Plugin Restoration: Skipped.';
	}

	if ($plugin_restoration_status === 'not_started') {
		$import_warnings[] = 'Plugin restoration status was never finalized during import.';
	}

	// Derive a simple top-level import health state from the outcomes that matter most.
	if (
		$uploads_count_comparison_status === 'lower' ||
		$url_replacement_status === 'not_started' ||
		$plugin_restoration_status === 'not_started'
	) {
		$import_health_status = 'attention_needed';

		// Record a clear explanation when fewer uploads were found than expected.
		if ($uploads_count_comparison_status === 'lower') {
			$import_health_reasons[] = 'Destination uploads count was lower than the manifest count.';
		}

		// Record a clear explanation when URL replacement status was never finalized.
		if ($url_replacement_status === 'not_started') {
			$import_health_reasons[] = 'URL replacement status was never finalized.';
		}

		// Record a clear explanation when plugin restoration status was never finalized.
		if ($plugin_restoration_status === 'not_started') {
			$import_health_reasons[] = 'Plugin restoration status was never finalized.';
		}
	} elseif (
		!empty($import_warnings) ||
		!empty($plugin_activation_issues) ||
		$url_replacement_status === 'skipped' ||
		$plugin_restoration_status === 'completed_with_issues' ||
		$plugin_restoration_status === 'skipped'
	) {
		$import_health_status = 'needs_review';

		// Record a clear explanation when plugin activation did not fully succeed.
		if (!empty($plugin_activation_issues)) {
			$import_health_reasons[] = 'Some plugins could not be activated.';
		}

		// Record a clear explanation when automatic URL replacement did not run.
		if ($url_replacement_status === 'skipped') {
			$import_health_reasons[] = 'Automatic URL replacement did not run.';
		}

		// Record a clear explanation when plugin restoration completed with issues.
		if ($plugin_restoration_status === 'completed_with_issues') {
			$import_health_reasons[] = 'Plugin restoration completed with issues.';
		}

		// Record a clear explanation when plugin restoration was skipped.
		if ($plugin_restoration_status === 'skipped') {
			$import_health_reasons[] = 'Plugin restoration was skipped.';
		}

		// Add a fallback explanation when review is needed but no specific reason was added yet.
		if (empty($import_health_reasons) && !empty($import_warnings)) {
			$import_health_reasons[] = 'Import completed with warnings that should be reviewed.';
		}
	} else {
		$import_health_status = 'healthy';
	}

	// Remove duplicate health reasons to keep the report clean and readable.
	if (!empty($import_health_reasons)) {
		$import_health_reasons = array_values(array_unique($import_health_reasons));
	}

	// Record that the import reached the end of the main workflow successfully.
	update_option('wpzm_import_debug_checkpoint', 'Import completed main workflow');
	
	wp_cache_flush();

	// Save the latest successful import report with a little context so it is
	// easier to understand later after reloads or repeated test runs.
		$last_import_report = array(
		'import_id' 						=> $import_id,
		'timestamp'       					=> current_time('mysql'),
		'site_name'       					=> $site_name,
		'source_site_url' 					=> $source_site_url,
		'theme_name'     					=> $theme_name,
		'url_replacement_status'          	=> $url_replacement_status,
		'uploads_count_comparison_status' 	=> $uploads_count_comparison_status,
		'plugin_restoration_status'      	=> $plugin_restoration_status,
		'import_health_status'             	=> $import_health_status,

		// Save human-readable health reasons so the saved report can explain the status.
		'import_health_reasons'             => $import_health_reasons,

		'message'         					=> $summary_message,
		'warnings'        					=> $import_warnings,
		'plugin_activation_issues'			=> $plugin_activation_issues,
		'steps'           					=> $import_steps,
		'next_actions'    					=> $next_actions,
	);

	update_option('wpzm_last_import_report', $last_import_report);

	// Return the main summary plus structured warnings and steps for clearer admin UI output.
		return array(
		'action'       						=> 'import',
		'type'         						=> 'success',
		'timestamp'       					=> current_time('mysql'),
		'site_name'       					=> $site_name,
		'source_site_url' 					=> $source_site_url,
		'theme_name'     					=> $theme_name,
		'url_replacement_status'          	=> $url_replacement_status,
		'uploads_count_comparison_status' 	=> $uploads_count_comparison_status,
		'plugin_restoration_status'       	=> $plugin_restoration_status,
		'import_health_status'             	=> $import_health_status,

		// Return human-readable health reasons so the live result can explain the status.
		'import_health_reasons'             => $import_health_reasons,

		'message'      						=> $summary_message,
		'warnings'     						=> $import_warnings,
		'plugin_activation_issues' 			=> $plugin_activation_issues,
		'steps'        						=> $import_steps,
		'next_actions' 						=> $next_actions,
	);
}

// Parse a SQL file into executable statements.
// Parse a SQL file into executable statements.
function wpzm_parse_sql_statements($sql_file_path) {

	if (!file_exists($sql_file_path)) {
		return false;
	}

	$sql_content = file_get_contents($sql_file_path);

	if ($sql_content === false) {
		return false;
	}

	$statements = array();
	$current_statement = '';
	$length = strlen($sql_content);
	$in_single_quote = false;
	$in_double_quote = false;

	for ($i = 0; $i < $length; $i++) {
		$character = $sql_content[$i];
		$previous_character = ($i > 0) ? $sql_content[$i - 1] : '';

		// Skip full-line SQL comments before they get added to the current statement.
		if (
			!$in_single_quote &&
			!$in_double_quote &&
			$character === '-' &&
			($i + 1) < $length &&
			$sql_content[$i + 1] === '-' &&
			($i === 0 || $sql_content[$i - 1] === "\n")
		) {
			while ($i < $length && $sql_content[$i] !== "\n") {
				$i++;
			}
			continue;
		}

		$current_statement .= $character;

		// Toggle single-quoted string state when the quote is not escaped.
		if ($character === "'" && !$in_double_quote && $previous_character !== '\\') {
			$in_single_quote = !$in_single_quote;
			continue;
		}

		// Toggle double-quoted string state when the quote is not escaped.
		if ($character === '"' && !$in_single_quote && $previous_character !== '\\') {
			$in_double_quote = !$in_double_quote;
			continue;
		}

		// End the statement only when a semicolon appears outside quoted strings.
		if ($character === ';' && !$in_single_quote && !$in_double_quote) {
			$trimmed_statement = trim($current_statement);

			// Skip standalone SQL comment lines that may have been collected.
			if ($trimmed_statement !== '' && strpos($trimmed_statement, '--') !== 0) {
				$statements[] = $trimmed_statement;
			}

			$current_statement = '';
		}
	}

	// Allow trailing whitespace or trailing SQL comment lines after the final statement.
	$remaining_sql = trim($current_statement);

	if ($remaining_sql !== '') {
		$remaining_lines = preg_split('/\R/', $remaining_sql);
		$has_real_sql_leftover = false;

		foreach ($remaining_lines as $remaining_line) {
			$trimmed_remaining_line = trim($remaining_line);

			if ($trimmed_remaining_line === '') {
				continue;
			}

			if (strpos($trimmed_remaining_line, '--') === 0) {
				continue;
			}

			$has_real_sql_leftover = true;
			break;
		}

		if ($has_real_sql_leftover) {
			return false;
		}
	}

	return $statements;
}

// Recursively replace URLs in plain, array, object, or serialized values.
function wpzm_replace_url_in_value($value, $old_url, $new_url) {

	if (is_array($value)) {
		foreach ($value as $key => $item) {
			$value[$key] = wpzm_replace_url_in_value($item, $old_url, $new_url);
		}
		return $value;
	}

	if (is_object($value)) {
		// Skip incomplete objects because PHP cannot safely modify their properties
		// unless the original class has been loaded before unserialization.
		if (get_class($value) === '__PHP_Incomplete_Class') {
			return $value;
		}

		foreach (get_object_vars($value) as $property => $item) {
			$value->$property = wpzm_replace_url_in_value($item, $old_url, $new_url);
		}

		return $value;
	}

	if (is_string($value)) {
		if (is_serialized($value)) {
			$unserialized = maybe_unserialize($value);
			$replaced = wpzm_replace_url_in_value($unserialized, $old_url, $new_url);
			return maybe_serialize($replaced);
		}

		return str_replace($old_url, $new_url, $value);
	}

	return $value;
}

// Replace URLs inside a meta table value column.
function wpzm_replace_url_in_meta_table($table_name, $id_column, $value_column, $old_url, $new_url) {

	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT `$id_column`, `$value_column` FROM `$table_name`",
		ARRAY_A
	);

	if (!is_array($rows)) {
		return false;
	}

	$updated_count = 0;

	foreach ($rows as $row) {
		$original_value = $row[$value_column];
		$replaced_value = wpzm_replace_url_in_value($original_value, $old_url, $new_url);

		if ($replaced_value !== $original_value) {
			$result = $wpdb->update(
				$table_name,
				array($value_column => $replaced_value),
				array($id_column => $row[$id_column]),
				array('%s'),
				array('%d')
			);

			if ($result === false) {
				return false;
			}

			$updated_count++;
		}
	}

	return $updated_count;
}

// Replace URLs inside wp_options values.
function wpzm_replace_url_in_options($old_url, $new_url) {

	global $wpdb;

	$options_table = $wpdb->options;

	$rows = $wpdb->get_results(
		"SELECT option_id, option_value FROM `$options_table`",
		ARRAY_A
	);

	if (!is_array($rows)) {
		return false;
	}

	$updated_count = 0;

	foreach ($rows as $row) {
		$original_value = $row['option_value'];
		$replaced_value = wpzm_replace_url_in_value($original_value, $old_url, $new_url);

		if ($replaced_value !== $original_value) {
			$result = $wpdb->update(
				$options_table,
				array('option_value' => $replaced_value),
				array('option_id' => $row['option_id']),
				array('%s'),
				array('%d')
			);

			if ($result === false) {
				return false;
			}

			$updated_count++;
		}
	}

	return $updated_count;
}

// Replace URLs inside post content and excerpt.
function wpzm_replace_url_in_posts($old_url, $new_url) {

	global $wpdb;

	$posts_table = $wpdb->posts;

	$rows = $wpdb->get_results(
		"SELECT ID, post_content, post_excerpt FROM `$posts_table`",
		ARRAY_A
	);

	if (!is_array($rows)) {
		return false;
	}

	$updated_count = 0;

	foreach ($rows as $row) {
		$new_content = wpzm_replace_url_in_value($row['post_content'], $old_url, $new_url);
		$new_excerpt = wpzm_replace_url_in_value($row['post_excerpt'], $old_url, $new_url);

		if ($new_content !== $row['post_content'] || $new_excerpt !== $row['post_excerpt']) {
			$result = $wpdb->update(
				$posts_table,
				array(
					'post_content' => $new_content,
					'post_excerpt' => $new_excerpt,
				),
				array('ID' => $row['ID']),
				array('%s', '%s'),
				array('%d')
			);

			if ($result === false) {
				return false;
			}

			$updated_count++;
		}
	}

	return $updated_count;
}

// Handle clearing the saved last import report from the admin page.
function wpzm_handle_clear_last_import_report_action() {

	if (!isset($_POST['wpzm_clear_last_import_report'])) {
		return null;
	}

	if (
		!isset($_POST['wpzm_clear_last_import_report_nonce']) ||
		!wp_verify_nonce($_POST['wpzm_clear_last_import_report_nonce'], 'wpzm_clear_last_import_report_action')
	) {
		return array(
			'action'  => 'clear_last_import_report',
			'type'    => 'error',
			'message' => 'Security check failed while clearing the last import report.',
		);
	}

	delete_option('wpzm_last_import_report');

	return array(
		'action'  => 'clear_last_import_report',
		'type'    => 'success',
		'message' => 'Last import report cleared.',
	);
}

// Return a simple inline style for import outcome status labels.
function wpzm_get_status_badge_style($status) {

	switch ($status) {
		case 'completed':
		case 'matched':
		case 'higher':
		case 'completed_cleanly':
			return 'display:inline-block; padding:2px 8px; border-radius:12px; background:#d1e7dd; color:#0f5132; font-weight:600;';

		case 'skipped':
		case 'completed_with_issues':
			return 'display:inline-block; padding:2px 8px; border-radius:12px; background:#fff3cd; color:#664d03; font-weight:600;';

		case 'lower':
			return 'display:inline-block; padding:2px 8px; border-radius:12px; background:#f8d7da; color:#842029; font-weight:600;';

		default:
			return 'display:inline-block; padding:2px 8px; border-radius:12px; background:#e9ecef; color:#495057; font-weight:600;';
	}
}

// Convert internal import status values into human-readable labels for the admin UI.
function wpzm_format_status_label($status) {

	$status_labels = array(
		'completed' => 'Completed',
		'matched' => 'Matched',
		'completed_cleanly' => 'Completed cleanly',
		'completed_with_issues' => 'Completed with issues',
		'skipped' => 'Skipped',
		'higher' => 'More files found',
		'lower' => 'Fewer files found',
		'not_checked' => 'Not checked',
		'not_started' => 'Not started',
	);

	if (isset($status_labels[$status])) {
		return $status_labels[$status];
	}

	return ucwords(str_replace('_', ' ', $status));
}

// Convert the top-level import health value into a human-readable label.
function wpzm_format_import_health_label($status) {

	$health_labels = array(
		'healthy' => 'Healthy',
		'needs_review' => 'Needs review',
		'attention_needed' => 'Attention needed',
	);

	if (isset($health_labels[$status])) {
		return $health_labels[$status];
	}

	return ucwords(str_replace('_', ' ', $status));
}

// Return a simple inline style for the top-level import health badge.
function wpzm_get_import_health_badge_style($status) {

	switch ($status) {
		case 'healthy':
			return 'display:inline-block; padding:3px 10px; border-radius:14px; background:#d1e7dd; color:#0f5132; font-weight:600;';

		case 'needs_review':
			return 'display:inline-block; padding:3px 10px; border-radius:14px; background:#fff3cd; color:#664d03; font-weight:600;';

		case 'attention_needed':
			return 'display:inline-block; padding:3px 10px; border-radius:14px; background:#f8d7da; color:#842029; font-weight:600;';

		default:
			return 'display:inline-block; padding:3px 10px; border-radius:14px; background:#e9ecef; color:#495057; font-weight:600;';
	}
}

function wpzm_render_admin_page() {
	$export_result = wpzm_handle_export_action();
	$import_result = wpzm_handle_import_action();
	$clear_last_import_report_result = wpzm_handle_clear_last_import_report_action();
	$last_import_report = get_option('wpzm_last_import_report', array());

	$latest_zip_file = get_option('wpzm_latest_zip_export_file', '');

	$latest_zip_url = '';

	if (!empty($latest_zip_file) && file_exists($latest_zip_file)) {
		$latest_zip_url = str_replace(WP_CONTENT_DIR, content_url(), $latest_zip_file);
	}
	?>

	<div class="wrap">
		<h1>WP Zero Migrate</h1>
		<p>Your migration plugin is alive.</p>

		<?php if (!empty($export_result)) : ?>
			<div class="notice notice-<?php echo esc_attr($export_result['type']); ?>">
				<p><?php echo esc_html($export_result['message']); ?></p>
			</div>
		<?php endif; ?>
		
		<?php // Show the import summary first, then render structured warnings and completed steps when available. ?>
		<?php if (!empty($import_result)) : ?>
			<div class="notice notice-<?php echo esc_attr($import_result['type']); ?>">
				<?php // Show a little live import context so the current result is easier to identify. ?>
				<?php if (!empty($import_result['timestamp'])) : ?>
					<p><em><?php echo esc_html($import_result['timestamp']); ?></em></p>
				<?php endif; ?>

				<?php if (!empty($import_result['site_name'])) : ?>
					<p><strong>Site:</strong> <?php echo esc_html($import_result['site_name']); ?></p>
				<?php endif; ?>

				<?php if (!empty($import_result['source_site_url'])) : ?>
					<p><strong>Source URL:</strong> <?php echo esc_html($import_result['source_site_url']); ?></p>
				<?php endif; ?>

				<?php if (!empty($import_result['theme_name'])) : ?>
					<p><strong>Theme:</strong> <?php echo esc_html($import_result['theme_name']); ?></p>
				<?php endif; ?>
				<p><?php echo esc_html($import_result['message']); ?></p>

				<?php if (!empty($import_result['import_health_status'])) : ?>
					<div style="padding: 10px 12px; margin: 12px 0; background: #fff; border-left: 4px solid #72aee6;">
						<p><strong>Import Health</strong></p>
						<p>
							<span style="<?php echo esc_attr(wpzm_get_import_health_badge_style($import_result['import_health_status'])); ?>">
								<?php echo esc_html(wpzm_format_import_health_label($import_result['import_health_status'])); ?>
							</span>
						</p>

						<?php // Show human-readable health reasons when the import is not fully healthy. ?>
						<?php if (!empty($import_result['import_health_reasons']) && is_array($import_result['import_health_reasons'])) : ?>
							<ul style="list-style: disc; margin: 8px 0 0 18px;">
								<?php foreach ($import_result['import_health_reasons'] as $health_reason) : ?>
									<li><?php echo esc_html($health_reason); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php // Show key outcome statuses for the current import. ?>
				<?php if (
					!empty($import_result['url_replacement_status']) ||
					!empty($import_result['uploads_count_comparison_status']) ||
					!empty($import_result['plugin_restoration_status'])
				) : ?>
					<div style="padding: 10px 12px; margin: 12px 0; background: #fff; border-left: 4px solid #72aee6;">
						<p><strong>Outcome Summary</strong></p>

						<?php if (!empty($import_result['url_replacement_status'])) : ?>
							<p>
								<strong>URL Replacement:</strong>
								<span style="<?php echo esc_attr(wpzm_get_status_badge_style($import_result['url_replacement_status'])); ?>">
									<?php echo esc_html(wpzm_format_status_label($import_result['url_replacement_status'])); ?>
								</span>
							</p>
						<?php endif; ?>

						<?php if (!empty($import_result['uploads_count_comparison_status'])) : ?>
							<p>
								<strong>Uploads Comparison:</strong>
								<span style="<?php echo esc_attr(wpzm_get_status_badge_style($import_result['uploads_count_comparison_status'])); ?>">
									<?php echo esc_html(wpzm_format_status_label($import_result['uploads_count_comparison_status'])); ?>
								</span>
							</p>
						<?php endif; ?>

						<?php if (!empty($import_result['plugin_restoration_status'])) : ?>
							<p>
								<strong>Plugin Restoration:</strong>
								<span style="<?php echo esc_attr(wpzm_get_status_badge_style($import_result['plugin_restoration_status'])); ?>">
									<?php echo esc_html(wpzm_format_status_label($import_result['plugin_restoration_status'])); ?>
								</span>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if (!empty($import_result['warnings']) && is_array($import_result['warnings'])) : ?>
					<p><strong>Warnings</strong></p>
					<ul style="list-style: disc; margin-left: 20px;">
						<?php foreach ($import_result['warnings'] as $warning_message) : ?>
							<li><?php echo esc_html($warning_message); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php // Show plugin activation problems separately because they often need manual follow-up. ?>
				<?php if (!empty($import_result['plugin_activation_issues']) && is_array($import_result['plugin_activation_issues'])) : ?>
					<p><strong>Plugin Activation Issues</strong></p>
					<ul style="list-style: disc; margin-left: 20px;">
						<?php foreach ($import_result['plugin_activation_issues'] as $plugin_issue) : ?>
							<li><?php echo esc_html($plugin_issue); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if (!empty($import_result['steps']) && is_array($import_result['steps'])) : ?>
					<p><strong>What Completed</strong></p>
					<ul style="list-style: disc; margin-left: 20px;">
						<?php foreach ($import_result['steps'] as $step_message) : ?>
							<li><?php echo esc_html($step_message); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php // Show a simple post-import checklist to guide the next verification steps. ?>
				<?php if (!empty($import_result['next_actions']) && is_array($import_result['next_actions'])) : ?>
					<p style="display:flex; justify-content:space-between; align-items:center;">
						<strong>What to check next</strong>
						<button type="button" class="button wpzm-clear-checklist">Clear progress</button>
					</p>

					<?php if (!empty($import_result['next_actions']['always_check'])) : ?>
						<p style="margin-top: 10px; margin-bottom: 6px; font-weight: 600;">Always check</p>
						<ul style="list-style: none; margin-left: 0; padding-left: 0;">
							<?php foreach ($import_result['next_actions']['always_check'] as $index => $next_action) : ?>
								<li style="margin-bottom: 6px;">
									<label>
										<input
											type="checkbox"
											class="wpzm-checklist-checkbox"
											data-checklist-group="live-always-check"
											data-checklist-index="<?php echo esc_attr($index); ?>"
											<?php // Attach the current import ID so checklist progress is scoped to this report only. ?>
											data-import-id="<?php echo esc_attr($import_id); ?>"
											style="margin-right: 6px;"
										>
										<?php echo esc_html($next_action); ?>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if (!empty($import_result['next_actions']['only_if_needed'])) : ?>
						<p style="margin-top: 10px; margin-bottom: 6px; font-weight: 600;">Only if needed</p>
						<ul style="list-style: none; margin-left: 0; padding-left: 0;">
							<?php foreach ($import_result['next_actions']['only_if_needed'] as $index => $next_action) : ?>
								<li style="margin-bottom: 6px;">
									<label>
										<input
											type="checkbox"
											class="wpzm-checklist-checkbox"
											data-checklist-group="live-only-if-needed"
											data-checklist-index="<?php echo esc_attr($index); ?>"
											<?php // Attach the current import ID so checklist progress is scoped to this report only. ?>
											data-import-id="<?php echo esc_attr($import_id); ?>"
											style="margin-right: 6px;"
										>
										<?php echo esc_html($next_action); ?>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if (!empty($clear_last_import_report_result)) : ?>
			<div class="notice notice-<?php echo esc_attr($clear_last_import_report_result['type']); ?>">
				<p><?php echo esc_html($clear_last_import_report_result['message']); ?></p>
			</div>
		<?php endif; ?>
		
		<?php // Show the last saved successful import report when there is no fresh import result in this request. ?>
		<?php if (empty($import_result) && !empty($last_import_report) && is_array($last_import_report)) : ?>
			<div class="notice notice-info">
				
				<p><strong>Last Import Report</strong></p>
				<?php // Show a little saved context so the report is easier to identify later. ?>
				<?php if (!empty($last_import_report['timestamp'])) : ?>
					<p><em><?php echo esc_html($last_import_report['timestamp']); ?></em></p>
				<?php endif; ?>

				<?php if (!empty($last_import_report['site_name'])) : ?>
					<p><strong>Site:</strong> <?php echo esc_html($last_import_report['site_name']); ?></p>
				<?php endif; ?>

				<?php if (!empty($last_import_report['source_site_url'])) : ?>
					<p><strong>Source URL:</strong> <?php echo esc_html($last_import_report['source_site_url']); ?></p>
				<?php endif; ?>

				<?php if (!empty($last_import_report['theme_name'])) : ?>
					<p><strong>Theme:</strong> <?php echo esc_html($last_import_report['theme_name']); ?></p>
				<?php endif; ?>

				<?php // Show saved structured outcome statuses so the report is easier to interpret later. ?>
				<?php if (!empty($last_import_report['url_replacement_status'])) : ?>
					<p>
						<strong>URL Replacement:</strong>
						<span style="<?php echo esc_attr(wpzm_get_status_badge_style($last_import_report['url_replacement_status'])); ?>">
							<?php echo esc_html(wpzm_format_status_label($last_import_report['url_replacement_status'])); ?>
						</span>
					</p>
				<?php endif; ?>

				<?php if (!empty($last_import_report['uploads_count_comparison_status'])) : ?>
					<p>
						<strong>Uploads Comparison:</strong>
						<span style="<?php echo esc_attr(wpzm_get_status_badge_style($last_import_report['uploads_count_comparison_status'])); ?>">
							<?php echo esc_html(wpzm_format_status_label($last_import_report['uploads_count_comparison_status'])); ?>
						</span>
					</p>
				<?php endif; ?>

				<?php if (!empty($last_import_report['plugin_restoration_status'])) : ?>
					<p>
						<strong>Plugin Restoration:</strong>
						<span style="<?php echo esc_attr(wpzm_get_status_badge_style($last_import_report['plugin_restoration_status'])); ?>">
							<?php echo esc_html(wpzm_format_status_label($last_import_report['plugin_restoration_status'])); ?>
						</span>
					</p>
				<?php endif; ?>

				<p><?php echo esc_html($last_import_report['message']); ?></p>

				<?php if (!empty($last_import_report['import_health_status'])) : ?>
					<div style="padding: 10px 12px; margin: 12px 0; background: #fff; border-left: 4px solid #72aee6;">
						<p><strong>Import Health</strong></p>
						<p>
							<span style="<?php echo esc_attr(wpzm_get_import_health_badge_style($last_import_report['import_health_status'])); ?>">
								<?php echo esc_html(wpzm_format_import_health_label($last_import_report['import_health_status'])); ?>
							</span>
						</p>

						<?php // Show saved human-readable health reasons so the saved report explains its own status. ?>
						<?php if (!empty($last_import_report['import_health_reasons']) && is_array($last_import_report['import_health_reasons'])) : ?>
							<ul style="list-style: disc; margin: 8px 0 0 18px;">
								<?php foreach ($last_import_report['import_health_reasons'] as $health_reason) : ?>
									<li><?php echo esc_html($health_reason); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if (!empty($last_import_report['plugin_activation_issues']) && is_array($last_import_report['plugin_activation_issues'])) : ?>
					<p><strong>Plugin Activation Issues</strong></p>
					<ul style="list-style: disc; margin-left: 20px;">
						<?php foreach ($last_import_report['plugin_activation_issues'] as $plugin_issue) : ?>
							<li><?php echo esc_html($plugin_issue); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if (!empty($last_import_report['warnings']) && is_array($last_import_report['warnings'])) : ?>
					<p><strong>Warnings</strong></p>
					<ul style="list-style: disc; margin-left: 20px;">
						<?php foreach ($last_import_report['warnings'] as $warning_message) : ?>
							<li><?php echo esc_html($warning_message); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if (!empty($last_import_report['steps']) && is_array($last_import_report['steps'])) : ?>
					<p><strong>What Completed</strong></p>
					<ul style="list-style: disc; margin-left: 20px;">
						<?php foreach ($last_import_report['steps'] as $step_message) : ?>
							<li><?php echo esc_html($step_message); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if (!empty($last_import_report['next_actions']) && is_array($last_import_report['next_actions'])) : ?>
					<p style="display:flex; justify-content:space-between; align-items:center;">
						<strong>What to check next</strong>
						<button type="button" class="button wpzm-clear-checklist">Clear progress</button>
					</p>

					<?php if (!empty($last_import_report['next_actions']['always_check'])) : ?>
						<p style="margin-top: 10px; margin-bottom: 6px; font-weight: 600;">Always check</p>
						<ul style="list-style: none; margin-left: 0; padding-left: 0;">
							<?php foreach ($last_import_report['next_actions']['always_check'] as $index => $next_action) : ?>
								<li style="margin-bottom: 6px;">
									<label>
										<input
											type="checkbox"
											class="wpzm-checklist-checkbox"
											data-checklist-group="saved-always-check"
											data-checklist-index="<?php echo esc_attr($index); ?>"
											<?php // Reuse the saved import ID so this report restores its own checklist progress. ?>
											data-import-id="<?php echo esc_attr($last_import_report['import_id']); ?>"
											style="margin-right: 6px;"
										>
										<?php echo esc_html($next_action); ?>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if (!empty($last_import_report['next_actions']['only_if_needed'])) : ?>
						<p style="margin-top: 10px; margin-bottom: 6px; font-weight: 600;">Only if needed</p>
						<ul style="list-style: none; margin-left: 0; padding-left: 0;">
							<?php foreach ($last_import_report['next_actions']['only_if_needed'] as $index => $next_action) : ?>
								<li style="margin-bottom: 6px;">
									<label>
										<input
											type="checkbox"
											class="wpzm-checklist-checkbox"
											data-checklist-group="saved-only-if-needed"
											data-checklist-index="<?php echo esc_attr($index); ?>"
											<?php // Reuse the saved import ID so this report restores its own checklist progress. ?>
											data-import-id="<?php echo esc_attr($last_import_report['import_id']); ?>"
											style="margin-right: 6px;"
										>
										<?php echo esc_html($next_action); ?>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>

				<?php // Let the user clear the saved report once it is no longer useful. ?>
				<form method="post" style="margin-top: 12px;">
					<?php wp_nonce_field('wpzm_clear_last_import_report_action', 'wpzm_clear_last_import_report_nonce'); ?>
					<p>
						<button type="submit" name="wpzm_clear_last_import_report" class="button">
							Clear Last Import Report
						</button>
					</p>
				</form>
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
		<hr>

		<hr>

		<?php // Remind the user that stale export packages can overwrite the destination plugin with older bundled files. ?>
		<h2>Import Package</h2>
		<p>Upload an export-package.zip file to begin the import process.</p>
		<p><strong>Important:</strong> If you changed WP Zero Migrate or other source-site files, create a fresh export package before testing import. The import package includes plugin files and may overwrite the destination plugin with the version bundled in the zip.</p>

		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field('wpzm_import_package_action', 'wpzm_import_nonce'); ?>
			<p>
				<input type="file" name="wpzm_import_zip" accept=".zip">

				<p style="margin: 12px 0 6px;">
					<strong>Or import from server path</strong>
				</p>
				<input
					type="text"
					name="wpzm_import_server_path"
					value=""
					placeholder="<?php echo esc_attr(WP_CONTENT_DIR . '/wpzm-imports/export-package.zip'); ?>"
					style="width: 100%; max-width: 700px;"
				>
				<p class="description" style="margin-top: 6px;">
					Leave both blank to do nothing. Use either an uploaded zip or a full server path to a zip file.
				</p>
			</p>

			<p>
				<button type="submit" name="wpzm_run_import" class="button">
					Import Package
				</button>
			</p>
		</form>

				<?php if (!empty($latest_zip_url)) : ?>
				<p>
					<a href="<?php echo esc_url($latest_zip_url); ?>" class="button" download>
						Download Latest Export
					</a>
				</p>
		<?php endif; ?>

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				var checklistCheckboxes = document.querySelectorAll('.wpzm-checklist-checkbox');
				var clearButtons = document.querySelectorAll('.wpzm-clear-checklist');

				checklistCheckboxes.forEach(function (checkbox) {
					var checklistGroup = checkbox.getAttribute('data-checklist-group');
					var checklistIndex = checkbox.getAttribute('data-checklist-index');
					// Scope checklist storage to a specific import so one migration report does
					// not overwrite the checklist progress of another.
					var importId = checkbox.getAttribute('data-import-id') || 'default';
					var storageKey = 'wpzm_checklist_' + importId + '_' + checklistGroup + '_' + checklistIndex;

					if (localStorage.getItem(storageKey) === '1') {
						checkbox.checked = true;
					}

					checkbox.addEventListener('change', function () {
						if (checkbox.checked) {
							localStorage.setItem(storageKey, '1');
						} else {
							localStorage.removeItem(storageKey);
						}
					});
				});
				clearButtons.forEach(function (button) {
					button.addEventListener('click', function () {
						checklistCheckboxes.forEach(function (checkbox) {
							var checklistGroup = checkbox.getAttribute('data-checklist-group');
							var checklistIndex = checkbox.getAttribute('data-checklist-index');
							var storageKey = 'wpzm_checklist_' + checklistGroup + '_' + checklistIndex;

							checkbox.checked = false;
							localStorage.removeItem(storageKey);
						});
					});
				});
			});
		</script>
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

	if ($table_name === $wpdb->prefix . 'options') {
		$rows = $wpdb->get_results(
			"SELECT * FROM `$table_name`
			WHERE option_name NOT LIKE '\_transient\_%'
			AND option_name NOT LIKE '\_site\_transient\_%'
			AND option_name NOT LIKE '\_transient\_timeout\_%'
			AND option_name NOT LIKE '\_site\_transient\_timeout\_%'
			AND option_name NOT IN ('siteurl', 'home')",
			ARRAY_A
		);
	} else {
		$rows = $wpdb->get_results("SELECT * FROM `$table_name`", ARRAY_A);
	}

	foreach ($rows as $row) {

		$columns = array();
		$values  = array();

		foreach ($row as $column => $value) {
			$columns[] = "`" . $column . "`";
			$values[]  = wpzm_format_sql_value($value);
		}

		// Use REPLACE for options so duplicate option_name entries do not abort the import.
		if ($table_name === $wpdb->prefix . 'options') {
			$sql_content .= "REPLACE INTO `$table_name` (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ");\n";
		} else {
			$sql_content .= "INSERT INTO `$table_name` (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ");\n";
		}
	}

	$sql_content .= "\n";

	return $sql_content;
}