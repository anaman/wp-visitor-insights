<?php
/**
 * Plugin Name: Visitor Insights (self-hosted analytics)
 * Description: Lightweight, cookie-less visitor analytics collector. Replaces Jetpack Stats. Records front-end pageviews via a JS beacon (works with full-page caches) and geolocates via local GeoLite2 data. No external calls.
 * Version: 1.0.0
 * Author: self-hosted
 *
 * Data is stored in the site database table `{prefix}vi_hits`. A companion
 * dashboard (served by nginx) reads it directly. Nothing is sent off-site.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'VI_LIB_DIR' ) ) {
	// Shared library + offline GeoIP database (installed by the deploy script).
	define( 'VI_LIB_DIR', '/opt/wp-visitor-insights/lib' );
}
if ( ! defined( 'VI_GEOIP_MMDB' ) ) {
	define( 'VI_GEOIP_MMDB', '/opt/wp-visitor-insights/geoip/GeoLite2-Country.mmdb' );
}
if ( ! defined( 'VI_SCHEMA_VERSION' ) ) {
	define( 'VI_SCHEMA_VERSION', '1' );
}

/** Table name (e.g. wp_vi_hits). */
function vi_table() {
	global $wpdb;
	return $wpdb->prefix . 'vi_hits';
}

/** Create the storage table (lazy; mu-plugins have no activation hook). */
function vi_install_schema() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = vi_table();
	$charset = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		ts DATETIME NOT NULL,
		ts_epoch INT UNSIGNED NOT NULL,
		visitor_id CHAR(32) NOT NULL DEFAULT '',
		session_id CHAR(32) NOT NULL DEFAULT '',
		ip VARCHAR(45) NOT NULL DEFAULT '',
		country_code CHAR(2) NOT NULL DEFAULT '',
		country_name VARCHAR(80) NOT NULL DEFAULT '',
		referrer VARCHAR(512) NOT NULL DEFAULT '',
		ref_host VARCHAR(191) NOT NULL DEFAULT '',
		is_bot TINYINT(1) NOT NULL DEFAULT 0,
		ua VARCHAR(512) NOT NULL DEFAULT '',
		browser VARCHAR(40) NOT NULL DEFAULT '',
		os VARCHAR(40) NOT NULL DEFAULT '',
		device VARCHAR(16) NOT NULL DEFAULT '',
		url_path VARCHAR(512) NOT NULL DEFAULT '',
		page_title VARCHAR(255) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY ts_epoch (ts_epoch),
		KEY country_code (country_code),
		KEY ref_host (ref_host),
		KEY visitor_id (visitor_id),
		KEY session_id (session_id),
		KEY url_path (url_path(191))
	) {$charset};";
	dbDelta( $sql );
	update_option( 'vi_schema_version', VI_SCHEMA_VERSION, false );
}

/** Random salt for visitor hashing (never leaves the site). */
function vi_salt() {
	$salt = get_option( 'vi_salt' );
	if ( ! $salt ) {
		$salt = wp_generate_password( 32, false, false );
		update_option( 'vi_salt', $salt, false );
	}
	return $salt;
}

/** Client IP, honouring the reverse-proxy chain in front of the origin. */
function vi_client_ip() {
	$candidates = array();
	foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP' ) as $k ) {
		if ( ! empty( $_SERVER[ $k ] ) ) {
			foreach ( explode( ',', (string) $_SERVER[ $k ] ) as $part ) {
				$candidates[] = trim( $part );
			}
		}
	}
	$candidates[] = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
	foreach ( $candidates as $ip ) {
		// strip IPv6 zone / port
		$ip = preg_replace( '/%\S+$/', '', $ip );
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return $ip;
		}
	}
	// fall back to first valid (even private) so local testing still records
	foreach ( $candidates as $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) { return $ip; }
	}
	return '';
}

/** Coarse bot detector. */
function vi_is_bot( $ua ) {
	if ( $ua === '' ) { return 1; }
	$re = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|quora|pinterest|slackbot|telegrambot|whatsapp|discordbot|google-inspectiontool|headless|lighthouse|pingdom|uptime|monitor|semrush|ahrefs|mj12|dotbot|petalbot|yandex|baidu|sogou|exabot|ia_archiver|archive\.org|python-requests|curl\/|wget|go-http-client|okhttp|java\/|apache-httpclient|libwww|scrapy|node-fetch|axios|guzzle|feedfetcher|feedburner|preview|previewer|validator|widget|snapchat|twitterbot|applebot|duckduckbot|bytespider|gptbot|chatgpt|claudebot|anthropic|ccbot|perplexity|amazonbot|googleother|imagesift|speakable/i';
	return preg_match( $re, $ua ) ? 1 : 0;
}

/** Very small UA sniffing (browser / OS / device class). */
function vi_parse_ua( $ua ) {
	$browser = 'Other';
	$oss     = 'Other';
	$device  = 'desktop';
	if ( preg_match( '/EdgA?\//i', $ua ) )            { $browser = 'Edge'; }
	elseif ( preg_match( '/OPR\/|Opera/i', $ua ) )     { $browser = 'Opera'; }
	elseif ( preg_match( '/SamsungBrowser/i', $ua ) )  { $browser = 'Samsung Internet'; }
	elseif ( preg_match( '/Firefox\//i', $ua ) )       { $browser = 'Firefox'; }
	elseif ( preg_match( '/Chrome\//i', $ua ) )        { $browser = 'Chrome'; }
	elseif ( preg_match( '/Safari\//i', $ua ) )        { $browser = 'Safari'; }
	elseif ( preg_match( '/MSIE|Trident/i', $ua ) )    { $browser = 'IE'; }

	if ( preg_match( '/Windows NT/i', $ua ) )          { $oss = 'Windows'; }
	elseif ( preg_match( '/Android/i', $ua ) )         { $oss = 'Android'; }
	elseif ( preg_match( '/iPhone|iPad|iPod/i', $ua ) ){ $oss = 'iOS'; }
	elseif ( preg_match( '/Mac OS X/i', $ua ) )        { $oss = 'macOS'; }
	elseif ( preg_match( '/CrOS/i', $ua ) )            { $oss = 'ChromeOS'; }
	elseif ( preg_match( '/Linux/i', $ua ) )           { $oss = 'Linux'; }

	if ( preg_match( '/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua ) ) { $device = 'tablet'; }
	elseif ( preg_match( '/Mobi|Android.+Mobile|iPhone|iPod|Windows Phone/i', $ua ) ) { $device = 'mobile'; }
	return array( $browser, $oss, $device );
}

/** Offline country lookup. */
function vi_country( $ip ) {
	if ( $ip === '' ) { return array( '', '' ); }
	try {
		require_once VI_LIB_DIR . '/Geo.php';
		if ( ! class_exists( 'VI_Geo' ) ) { return array( '', '' ); }
		return VI_Geo::country( $ip );
	} catch ( \Throwable $e ) {
		return array( '', '' );
	}
}

/** Host component of a referrer, '' if none/self. */
function vi_ref_host( $referrer ) {
	if ( $referrer === '' ) { return ''; }
	$h = wp_parse_url( $referrer, PHP_URL_HOST );
	if ( ! $h ) { return ''; }
	$h = preg_replace( '/^www\./i', '', strtolower( $h ) );
	$self = preg_replace( '/^www\./i', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	if ( $h === $self ) { return ''; } // internal navigation
	return $h;
}

/**
 * AJAX beacon handler: wp-admin/admin-ajax.php?action=vi_track
 * Never cached, so it works even when the page itself is served from a
 * full-page cache (LiteSpeed etc.).
 */
function vi_handle_track() {
	global $wpdb;

	$path = isset( $_POST['p'] ) ? (string) $_POST['p'] : '';
	$refer = isset( $_POST['r'] ) ? (string) $_POST['r'] : '';
	$title = isset( $_POST['t'] ) ? (string) $_POST['t'] : '';
	$uid   = isset( $_POST['uid'] ) ? (string) $_POST['uid'] : '';
	$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

	// Basic sanity limits.
	$path  = substr( $path, 0, 500 );
	$refer = substr( $refer, 0, 500 );
	$title = substr( $title, 0, 250 );
	$uid   = substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', $uid ), 0, 64 );

	// Skip obvious internal/admin paths.
	if ( $path === '' || stripos( $path, 'wp-admin' ) !== false || stripos( $path, 'wp-login' ) !== false || stripos( $path, 'xmlrpc' ) !== false ) {
		status_header( 204 );
		exit;
	}

	$ip    = vi_client_ip();
	$salt  = vi_salt();
	$is_bot = vi_is_bot( $ua );
	$vp     = $uid !== '' ? $uid : ( $ip . '|' . $ua );
	$bucket = (int) floor( time() / 1800 ); // 30-minute session buckets

	$visitor_id = substr( hash( 'sha256', $salt . '|v|' . $vp ), 0, 32 );
	$session_id = substr( hash( 'sha256', $salt . '|s|' . $vp . '|' . $bucket ), 0, 32 );

	list( $cc, $cn ) = vi_country( $ip );
	list( $browser, $oss, $device ) = vi_parse_ua( $ua );

	$table = vi_table();
	// Create on demand if missing (e.g. table dropped / fresh deploy).
	if ( get_option( 'vi_schema_version' ) !== VI_SCHEMA_VERSION ) {
		vi_install_schema();
	}

	$wpdb->insert(
		$table,
		array(
			'ts'           => current_time( 'mysql' ),
			'ts_epoch'     => time(),
			'visitor_id'   => $visitor_id,
			'session_id'   => $session_id,
			'ip'           => substr( $ip, 0, 45 ),
			'country_code' => substr( (string) $cc, 0, 2 ),
			'country_name' => substr( (string) $cn, 0, 80 ),
			'referrer'     => $refer,
			'ref_host'     => substr( vi_ref_host( $refer ), 0, 191 ),
			'is_bot'       => $is_bot,
			'ua'           => substr( $ua, 0, 512 ),
			'browser'      => substr( $browser, 0, 40 ),
			'os'           => substr( $oss, 0, 40 ),
			'device'       => substr( $device, 0, 16 ),
			'url_path'     => $path,
			'page_title'   => $title,
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	status_header( 204 );
	nocache_headers();
	exit;
}
add_action( 'wp_ajax_nopriv_vi_track', 'vi_handle_track' );
add_action( 'wp_ajax_vi_track', 'vi_handle_track' );

/** Enqueue the beacon on the front end (also survives page caching). */
function vi_enqueue_beacon() {
	if ( is_admin() || is_feed() || is_robots() || is_trackback() ) { return; }
	$src = content_url( 'mu-plugins/vi-beacon.js' );
	wp_enqueue_script( 'vi-beacon', $src, array(), '1.0.0', true );
	wp_add_inline_script(
		'vi-beacon',
		'window.VI_TRACK=' . wp_json_encode( array( 'ajax' => admin_url( 'admin-ajax.php' ) ) ) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'vi_enqueue_beacon' );

/** Lazy schema install on init (cheap after first run). */
function vi_maybe_install() {
	if ( get_option( 'vi_schema_version' ) !== VI_SCHEMA_VERSION ) {
		vi_install_schema();
		vi_salt();
	}
}
add_action( 'init', 'vi_maybe_install', 1 );
