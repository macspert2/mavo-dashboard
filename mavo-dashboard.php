<?php
/**
 * Plugin Name: Mavo Dashboard
 * Description: Admin-only dashboards — (1) curated tags, per-tag post metrics and per-post detail; (2) an internal-link map between tags. Completely invisible on the frontend (no HTML/JS/CSS loaded there).
 * Version:     1.1.0
 * Author:      Mavo
 * Text Domain: mavo-dashboard
 *
 * All hooks are admin-only, so nothing is ever enqueued or output on the frontend.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ---------------------------------------------------------------------------
 * Postmeta keys — ADJUST THESE TO MATCH YOUR DATA.
 *   MAVO_META_VIEWS : numeric view counter; the post list is sorted DESC by it.
 *   MAVO_META_BPUL  : the "bpul" field, shown in the post list.
 *   MAVO_META_MAJ   : the "maj" field, shown in the post list.
 * You can also override these in wp-config.php.
 * ---------------------------------------------------------------------------
 */
if ( ! defined( 'MAVO_META_VIEWS' ) ) {
	define( 'MAVO_META_VIEWS', 'views' );
}
if ( ! defined( 'MAVO_META_BPUL' ) ) {
	define( 'MAVO_META_BPUL', '_mavo_bpul_key' );
}
if ( ! defined( 'MAVO_META_MAJ' ) ) {
	define( 'MAVO_META_MAJ', '_mavo_maj_key' );
}

/**
 * Monthly view-history table (the WordPress prefix, e.g. wp_, is prepended
 * automatically). Columns used: post_id, snapshot_month (DATE, YYYY-MM-01),
 * views. Each row is a 3-month rolling total ending in snapshot_month.
 */
if ( ! defined( 'MAVO_VIEWS_TABLE' ) ) {
	define( 'MAVO_VIEWS_TABLE', 'rpp_monthly_snapshots' );
}

/**
 * Precomputed internal-links table for the link map (prefix prepended).
 * Created/filled on demand by the "Recalculate" button on screen 2.
 */
if ( ! defined( 'MAVO_LINKS_TABLE' ) ) {
	define( 'MAVO_LINKS_TABLE', 'mavo_internal_links' );
}

require_once __DIR__ . '/includes/class-mavo-helpers.php';
require_once __DIR__ . '/includes/class-mavo-dashboard.php';
require_once __DIR__ . '/includes/class-mavo-link-map.php';

$GLOBALS['mavo_dashboard'] = new Mavo_Dashboard();
$GLOBALS['mavo_link_map']  = new Mavo_Link_Map();

/**
 * One top-level menu with two submenus: Overview + Internal Link Map.
 */
function mavo_register_menu() {
	$cap = 'edit_posts';

	add_menu_page(
		__( 'Mavo Dashboard', 'mavo-dashboard' ),
		__( 'Mavo Dashboard', 'mavo-dashboard' ),
		$cap,
		'mavo-dashboard',
		array( $GLOBALS['mavo_dashboard'], 'render_page' ),
		'dashicons-chart-area',
		3
	);
	add_submenu_page(
		'mavo-dashboard',
		__( 'Overview', 'mavo-dashboard' ),
		__( 'Overview', 'mavo-dashboard' ),
		$cap,
		'mavo-dashboard',
		array( $GLOBALS['mavo_dashboard'], 'render_page' )
	);
	add_submenu_page(
		'mavo-dashboard',
		__( 'Internal Link Map', 'mavo-dashboard' ),
		__( 'Internal Link Map', 'mavo-dashboard' ),
		$cap,
		'mavo-link-map',
		array( $GLOBALS['mavo_link_map'], 'render_page' )
	);
}
add_action( 'admin_menu', 'mavo_register_menu' );
