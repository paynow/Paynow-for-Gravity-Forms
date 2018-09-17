<?php
/*
Plugin Name: Paynow for Gravity Forms
Plugin URI: http://www.paynow.co.zw/
Description: Paynow for Gravity Forms
Version: 1.0.1
Author: Webdev
Author URI: http://www.paynow.co.zw/
*/


define( 'GF_PAYNOW_VERSION', '1.0.1' );

add_action( 'gform_loaded', array( 'GF_PayNow_Bootstrap', 'load' ), 5 );

class GF_PayNow_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			return;
		}

		require_once( 'class-gf-paynow.php' );

		GFAddOn::register( 'GFPayNow' );
	}
}

function gf_paynow() {
	return GFPayNow::get_instance();
}
