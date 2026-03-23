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
// I'm are not running this function myself right here.
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

// Handle the export form submission and return a message.
// Right now this does not export anything yet.
// It just checks whether the button was clicked and whether the nonce is valid.
function wpzm_handle_export_action() {

	// If the export button was not clicked, return an empty message.
	if (!isset($_POST['wpzm_run_export'])) {
		return '';
	}

	// If the nonce is missing or invalid, stop and return an error message.
	if (
		!isset($_POST['wpzm_nonce']) ||
		!wp_verify_nonce($_POST['wpzm_nonce'], 'wpzm_run_export_action')
	) {
		return 'Security check failed.';
	}

	// If everything is valid, return a success message.
	return 'Export button clicked.';
}

function wpzm_render_admin_page() {
    // Ask my helper function whether there is any export result to show.
	$message = wpzm_handle_export_action();

	?>
	<div class="wrap">
		<h1>WP Zero Migrate</h1>
		<p>Your migration plugin is alive.</p>

		<?php if (!empty($message)) : ?>
			<div class="notice notice-success">
				<p><?php echo esc_html($message); ?></p>
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