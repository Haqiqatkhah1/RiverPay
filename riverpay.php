<?php
/**
 * Plugin Name: RiverPay Payment Gateway for WooCommerce
 * Plugin URI: https://riverpay.io/
 * Description: Accept CryptoCurrencies via RiverPay in your WooCommerce store
 * Version: 1.1
 * Author: RiverPay Hi-Tech Team
 * Author URI: https://riverpay.io/
 */


//River Init
define( 'RIVER_PLUGIN_PATH', dirname( __FILE__ ) );
define( 'RIVER_PLUGIN_DIR_NAME', basename( RIVER_PLUGIN_PATH ) );
define( 'RIVER_PLUGIN_URL', plugins_url( RIVER_PLUGIN_DIR_NAME . '/' ) );
define( 'RIVER_WOOCOMMERCE_VERSION', '1.0.0' );

require_once dirname( __FILE__ ) . '/includes/WC-River-Pay.php';



