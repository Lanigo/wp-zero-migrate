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

	// If the import button was not clicked, return no result.
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

	// Check whether a file was uploaded.
	if (
		!isset($_FILES['wpzm_import_zip']) ||
		empty($_FILES['wpzm_import_zip']['name'])
	) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Please choose a zip file to import.',
		);
	}

	$original_destination_site_url = home_url();

	// Collect non-fatal issues that should be shown in the final import report.
	$import_warnings = array();

	// Record the major import stages that completed, so the final result can
	// show a more trustworthy step-by-step summary.
	$import_steps = array();

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

	$upload_moved = move_uploaded_file(
		$_FILES['wpzm_import_zip']['tmp_name'],
		$uploaded_zip_path
	);

	if ($upload_moved === false) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to store uploaded zip file.',
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

	// Build a human-readable import summary that will be shown in the admin UI.
	$summary_message = 'Import package validated successfully. ';
	$summary_message .= 'Site: ' . $site_name . '. ';
	$summary_message .= 'Theme: ' . $theme_name . '. ';
	$summary_message .= 'Active Plugins: ' . $active_plugin_count . '. ';
	$summary_message .= 'Uploads Copied: ' . ($uploads_copied ? 'Yes' : 'No') . '. ';
	$summary_message .= 'Uploads File Count: ' . $uploads_file_count . '. ';
	$summary_message .= 'Uploads Size (bytes): ' . $uploads_export_size . '.';
	$summary_message .= ' Source DB Prefix: ' . (!empty($source_database_prefix) ? $source_database_prefix : '[missing]') . '.';
	$summary_message .= ' Destination DB Prefix: ' . (!empty($destination_database_prefix) ? $destination_database_prefix : '[missing]') . '.';
	$summary_message .= ' Prefix Remap Needed: ' . ($prefix_remap_needed ? 'Yes' : 'No') . '.';

	$uploads_imported = wpzm_copy_directory($uploads_dir, $destination_uploads_dir);

	if ($uploads_imported === false) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to import uploads into destination uploads directory.',
		);
	}

	$import_steps[] = 'Uploads imported';

	$themes_imported = wpzm_copy_directory($themes_dir, $destination_themes_dir);

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

	$plugins_imported = wpzm_copy_directory($plugins_dir, $destination_plugins_dir);

	if ($plugins_imported === false) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to import plugins into destination plugins directory.',
		);
	}

	$import_steps[] = 'Plugin files imported';

	$sql_statements = wpzm_parse_sql_statements($database_file);

	if ($sql_statements === false) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'Failed to parse database.sql into executable statements.',
		);
	}

	$sql_statement_count = count($sql_statements);

	$summary_message .= ' Uploads Imported: ' . ($uploads_imported ? 'Yes' : 'No') . '.';
	$summary_message .= ' Themes Imported: ' . ($themes_imported ? 'Yes' : 'No') . '.';
	$summary_message .= ' Plugins Imported: ' . ($plugins_imported ? 'Yes' : 'No') . '.';
	$summary_message .= ' SQL Statements Parsed: ' . $sql_statement_count . '.';

	$import_steps[] = 'Database imported';

	if (!empty($plugin_activation_warnings)) {
		foreach ($plugin_activation_warnings as $warning_message) {
			$import_warnings[] = $warning_message;
		}
	}

	global $wpdb;

	if (empty($sql_statements)) {
		return array(
			'action'  => 'import',
			'type'    => 'error',
			'message' => 'No SQL statements were available to execute.',
		);
	}

	$executed_sql_count = 0;

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

		$import_steps[] = 'URL replacement completed';

		$summary_message .= ' Site URL Updated: Yes.';
		$summary_message .= ' URL Replacements - Options: ' . $options_replaced . '.';
		$summary_message .= ' Posts: ' . $posts_replaced . '.';
		$summary_message .= ' Postmeta: ' . $postmeta_replaced . '.';
		$summary_message .= ' Usermeta: ' . $usermeta_replaced . '.';
		$summary_message .= ' Commentmeta: ' . $commentmeta_replaced . '.';
	} else {
		$summary_message .= ' Site URL Updated: No.';
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
				$plugin_activation_warnings[] = 'Activation failed for: ' . $plugin_path . '. Error: ' . $activation_result->get_error_message();
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
		$import_steps[] = 'Plugin restoration completed';
	} else {
		$import_warnings[] = 'No active plugin paths were found in the manifest, so plugin restoration was skipped.';
		$import_steps[] = 'Plugin restoration skipped';
	}

	// Append collected warnings and completed steps to the final admin-facing import summary.
	if (!empty($import_warnings)) {
		$summary_message .= ' Import Warnings: ' . count($import_warnings) . '.';

		foreach ($import_warnings as $warning_message) {
			$summary_message .= ' Warning: ' . $warning_message . '.';
		}
	} else {
		$summary_message .= ' Site URL Updated: No.';
		$import_warnings[] = 'Source site URL or destination site URL was missing, so URL replacement did not run.';
		$import_steps[] = 'URL replacement skipped';
	}

	if (!empty($import_warnings)) {
		$summary_message .= ' Import Warnings: ' . count($import_warnings) . '.';

		foreach ($import_warnings as $warning_message) {
			$summary_message .= ' Warning: ' . $warning_message . '.';
		}
	} else {
		$summary_message .= ' Import Warnings: 0.';
	}

	if (!empty($import_steps)) {
		$summary_message .= ' Import Steps Completed: ' . count($import_steps) . '.';

		foreach ($import_steps as $step_message) {
			$summary_message .= ' Step: ' . $step_message . '.';
		}
	}
	
	wp_cache_flush();

	return array(
		'action'  => 'import',
		'type'    => 'success',
		'message' => $summary_message,
	);
}

// Parse a SQL file into executable statements.
function wpzm_parse_sql_statements($sql_file_path) {

	if (!file_exists($sql_file_path)) {
		return false;
	}

	$lines = file($sql_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

	if ($lines === false) {
		return false;
	}

	$statements = array();
	$current_statement = '';

	foreach ($lines as $line) {

		$trimmed_line = trim($line);

		// Skip SQL comments.
		if (strpos($trimmed_line, '--') === 0) {
			continue;
		}

		// Skip empty lines.
		if ($trimmed_line === '') {
			continue;
		}

		$current_statement .= $line . "\n";

		// If the line ends with a semicolon, the statement is complete.
		if (substr(rtrim($trimmed_line), -1) === ';') {
			$statements[] = trim($current_statement);
			$current_statement = '';
		}
	}

	// If anything is left over, treat that as a parsing failure.
	if (trim($current_statement) !== '') {
		return false;
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

function wpzm_render_admin_page() {
	$export_result = wpzm_handle_export_action();
	$import_result = wpzm_handle_import_action();

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

		<?php if (!empty($import_result)) : ?>
			<div class="notice notice-<?php echo esc_attr($import_result['type']); ?>">
				<p><?php echo esc_html($import_result['message']); ?></p>
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

		<h2>Import Package</h2>
		<p>Upload an export-package.zip file to begin the import process.</p>

		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field('wpzm_import_package_action', 'wpzm_import_nonce'); ?>
			<p>
				<input type="file" name="wpzm_import_zip" accept=".zip">
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
	if ($table_name === $wpdb->prefix . 'options') {
		$rows = $wpdb->get_results(
			"SELECT * FROM `$table_name`
			WHERE option_name NOT LIKE '\_transient\_%'
			AND option_name NOT LIKE '\_site\_transient\_%'
			AND option_name NOT LIKE '\_transient\_timeout\_%'
			AND option_name NOT LIKE '\_site\_transient\_timeout\_%'",
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

		$sql_content .= "INSERT INTO `$table_name` (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ");\n";
	}

	$sql_content .= "\n";

	return $sql_content;
}