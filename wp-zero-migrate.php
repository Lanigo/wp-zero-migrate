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

	// Create the text content we want to save in the file.
	$info_content = "WP Zero Migrate Export\n";
	$info_content .= "Created: " . $timestamp . "\n";
	$info_content .= "Site Name: " . get_bloginfo('name') . "\n";
	$info_content .= "Site URL: " . home_url() . "\n";
	$info_content .= "WordPress Version: " . get_bloginfo('version') . "\n";
	$info_content .= "PHP Version: " . PHP_VERSION . "\n";
	$info_content .= "Active Theme: " . wp_get_theme()->get('Name') . "\n";
	$info_content .= "Active Plugins: " . count($active_plugins) . "\n";
	$info_content .= "Plugin List:\n";

	foreach ($active_plugins as $plugin) {
		$info_content .= " - " . $plugin . "\n";
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
	    'message' => 'Export folder and export-info.txt created: ' . $export_path,
    );
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