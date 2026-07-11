<?php
/**
 * Dynamic Host - Makes WordPress respond to any hostname.
 *
 * Overrides siteurl/home based on the incoming HTTP_HOST header
 * so the site works on both localhost and Tailscale hostnames.
 *
 * @package WordPress-AI-Dev
 */

add_filter( 'option_siteurl', 'wpai_dev_dynamic_url' );
add_filter( 'option_home', 'wpai_dev_dynamic_url' );

function wpai_dev_dynamic_url( $url ) {
	if ( isset( $_SERVER['HTTP_HOST'] ) && ! empty( $_SERVER['HTTP_HOST'] ) ) {
		$host   = $_SERVER['HTTP_HOST'];
		$parsed = wp_parse_url( $url );
		$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'http';
		$path   = isset( $parsed['path'] ) ? $parsed['path'] : '';
		$url    = $scheme . '://' . $host . $path;
	}
	return $url;
}
