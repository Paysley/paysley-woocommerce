<?php
/**
 * Plugin Name:          Paysley
 * Plugin URI:           https://github.com/PaysleyLLC/paysley-woocommerce
 * Description:          Receive payments using Paysley.
 * Version:              1.0.1
 * Requires at least:    5.0
 * Tested up to:         5.6
 * WC requires at least: 3.9.0
 * WC tested up to:      4.8.0
 * Requires PHP:         7.0
 * Author:               Paysley
 * Author URI:           https://paysley.com
 * License:              GPL v3 or later
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          paysley
 * Domain Path:          /languages
 *
 * @package Paysley
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'PAYSLEY_PLUGIN_VERSION', '1.0.1' );

register_activation_hook( __FILE__, 'paysley_activate_plugin' );
register_uninstall_hook( __FILE__, 'paysley_uninstall_plugin' );

/**
 * Process when activate plugin.
 */
function paysley_activate_plugin() {
	// add or update plugin version to database.
	$paysley_plugin_version = get_option( 'paysley_plugin_version' );
	if ( ! $paysley_plugin_version ) {
		add_option( 'paysley_plugin_version', PAYSLEY_PLUGIN_VERSION );
	} else {
		update_option( 'paysley_plugin_version', PAYSLEY_PLUGIN_VERSION );
	}
}

/**
 * Process when delete plugin.
 */
function paysley_uninstall_plugin() {
	delete_option( 'paysley_plugin_version' );
	delete_option( 'woocommerce_paysley_settings' );
}

/**
 * Initial plugin.
 */
function paysley_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once plugin_basename( 'includes/class-paysley.php' );
	load_plugin_textdomain( 'paysley', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	add_filter( 'woocommerce_payment_gateways', 'paysley_add_gateway' );
}
add_action( 'plugins_loaded', 'paysley_init', 0 );

/**
 * Add paysley to woocommerce payment gateway.
 *
 * @param array $methods Payment methods.
 */
function paysley_add_gateway( $methods ) {
	$methods[] = 'Paysley';
	return $methods;
}

/**
 * Add plugin settings link.
 *
 * @param array $links Links.
 */
function paysley_plugin_links( $links ) {
	$settings_url = add_query_arg(
		array(
			'page'    => 'wc-settings',
			'tab'     => 'checkout',
			'section' => 'paysley',
		),
		admin_url( 'admin.php' )
	);

	$plugin_links = array(
		'<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'paysley' ) . '</a>',
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'paysley_plugin_links' );

/**
 * Add paysley query vars.
 *
 * @param array $vars Query vars.
 */
function paysley_add_query_vars_filter( $vars ) {
	$vars[] = 'response';
	$vars[] = 'py_token';
	return $vars;
}
add_filter( 'query_vars', 'paysley_add_query_vars_filter' );
