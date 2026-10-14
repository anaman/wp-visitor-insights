<?php
/**
 * Plugin Name: Visitor Insights — site dashboard
 * Description: Exposes the self-hosted visitor analytics dashboard at /visitor-insights/ on this site (admins only). Reads the same data as the standalone app in /opt/wp-visitor-insights.
 * Version: 1.0.0
 *
 * Requires the app deployed at /opt/wp-visitor-insights (bin/deploy.sh).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'VI_DASH_DIR' ) ) {
	define( 'VI_DASH_DIR', '/opt/wp-visitor-insights' );
}
define( 'VI_DASH_RW_VERSION', '1' );

/** Don't let full-page caches (LiteSpeed etc.) cache the dashboard routes. */
if ( isset( $_SERVER['REQUEST_URI'] ) && stripos( (string) $_SERVER['REQUEST_URI'], 'visitor-insights' ) !== false ) {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
}

/** Register the rewrite rules + query var. */
function vi_dash_routes() {
	add_rewrite_rule( '^visitor-insights/api/?$', 'index.php?vi_dash=api', 'top' );
	add_rewrite_rule( '^visitor-insights/?$',     'index.php?vi_dash=page', 'top' );
}
add_action( 'init', 'vi_dash_routes' );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'vi_dash';
	return $vars;
} );

/** Flush rewrite rules once after install/update. */
add_action( 'init', function () {
	if ( get_option( 'vi_dash_rw' ) !== VI_DASH_RW_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'vi_dash_rw', VI_DASH_RW_VERSION, false );
	}
}, 20 );

/** Serve the dashboard / API, admins only. */
function vi_dash_serve() {
	$v = get_query_var( 'vi_dash' );
	if ( ! $v ) { return; }

	if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }

	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		auth_redirect(); // -> wp-login with redirect back
		exit;
	}

	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );

	if ( 'api' === $v ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		echo vi_dash_render_api();
		exit;
	}

	header( 'Content-Type: text/html; charset=utf-8' );
	echo vi_dash_render_page();
	exit;
}
add_action( 'template_redirect', 'vi_dash_serve', 0 );

/** JSON payload (same contract as the standalone app). */
function vi_dash_render_api() {
	$cfg = require VI_DASH_DIR . '/www/config.php';
	require_once VI_DASH_DIR . '/lib/Aggregate.php';
	$site  = isset( $_GET['site'] )  ? (string) $_GET['site']  : 'all';
	$range = isset( $_GET['range'] ) ? (string) $_GET['range'] : '7d';
	return json_encode( vi_aggregate( $cfg, $site, $range ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/** Full dashboard page (reuses the app's UI, pointed at this site's API). */
function vi_dash_render_page() {
	$file = VI_DASH_DIR . '/www/index.html';
	if ( ! is_readable( $file ) ) {
		status_header( 500 );
		return '<h1>Visitor Insights</h1><p>Dashboard UI not found at ' . esc_html( $file ) . '.</p>';
	}
	$html = (string) file_get_contents( $file );
	$inject = '<script>window.VI_API=' . wp_json_encode( home_url( '/visitor-insights/api' ) ) . ';</script>';
	$html = preg_replace( '/<body([^>]*)>/i', '<body$1>' . $inject, $html, 1 );
	return $html;
}

/** Convenience link in the admin bar. */
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) { return; }
	$bar->add_node( array(
		'id'    => 'vi-dash',
		'title' => 'Visitor Insights',
		'href'  => home_url( '/visitor-insights/' ),
		'meta'  => array( 'title' => 'Site visitor analytics' ),
	) );
}, 100 );
