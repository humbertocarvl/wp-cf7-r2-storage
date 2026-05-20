<?php
/**
 * Plugin Name: CF7 R2 Storage
 * Plugin URI:  https://github.com/humbertocarvl/wp-cf7-r2-storage
 * Description: Envia anexos do Contact Form 7 diretamente para o Cloudflare R2, incluindo os links no e-mail em vez de armazenar os arquivos no servidor.
 * Version:     1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      humbertocarvl
 * Author URI:  https://github.com/humbertocarvl
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cf7-r2-storage
 */

defined( 'ABSPATH' ) || exit;

define( 'CF7R2_VERSION', '1.0.1' );
define( 'CF7R2_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CF7R2_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CF7R2_OPTION_KEY', 'cf7r2_settings' );

require_once CF7R2_PLUGIN_DIR . 'includes/class-r2-client.php';
require_once CF7R2_PLUGIN_DIR . 'includes/class-cf7-integration.php';
require_once CF7R2_PLUGIN_DIR . 'includes/class-admin-settings.php';

/**
 * Bootstrap.
 * Guard against redeclaration in case a duplicate copy of the plugin is active.
 */
if ( ! function_exists( 'cf7r2_init' ) ) {
	function cf7r2_init(): void {
		CF7R2_Admin_Settings::init();
		CF7R2_CF7_Integration::init();
	}
	add_action( 'plugins_loaded', 'cf7r2_init' );
}
