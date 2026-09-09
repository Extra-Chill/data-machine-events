<?php
/**
 * Boot the real WP-CLI API used by DME command tests.
 *
 * @package DataMachineEvents\Tests
 */

$vendor_dir  = dirname( __DIR__ ) . '/vendor';
$wp_cli_root = $vendor_dir . '/wp-cli/wp-cli';

require_once $vendor_dir . '/autoload.php';

if ( ! defined( 'WP_CLI_ROOT' ) ) {
	define( 'WP_CLI_ROOT', $wp_cli_root );
}

if ( ! defined( 'WP_CLI_VENDOR_DIR' ) ) {
	define( 'WP_CLI_VENDOR_DIR', $vendor_dir );
}

foreach ( array( 'utils.php', 'dispatcher.php', 'utils-wp.php' ) as $file ) {
	require_once $wp_cli_root . '/php/' . $file;
}
