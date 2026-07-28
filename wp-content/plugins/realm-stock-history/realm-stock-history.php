<?php
/**
 * Plugin Name: Realm Stock History
 * Description: Records every product stock movement (in/out, before/after, type, order) to a per-product audit log, surfaced on the product editor and a central WooCommerce → Stock History page. Strictly additive, disposable, and observation-only — it never mutates stock.
 * Version:     1.0.0
 * Author:      James Borg
 * Text Domain: realm-stock-history
 * Requires PHP: 8.0
 *
 * This plugin is an optional observer. Nothing else in the codebase may depend on
 * it: the ONLY sanctioned way to feed it a stock change from outside is the
 * fail-soft public action `do_action( 'rsh_record_stock_change', $args )`, which
 * is a silent no-op when the plugin is inactive. See includes/class-rsh-listeners.php
 * for the argument contract.
 *
 * @package Realm_Stock_History
 */

defined( 'ABSPATH' ) || exit;

define( 'RSH_VERSION', '1.0.0' );
define( 'RSH_PLUGIN_FILE', __FILE__ );
define( 'RSH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RSH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once RSH_PLUGIN_DIR . 'includes/class-rsh-db.php';
require_once RSH_PLUGIN_DIR . 'includes/class-rsh-listeners.php';
require_once RSH_PLUGIN_DIR . 'includes/class-rsh-admin.php';
require_once RSH_PLUGIN_DIR . 'includes/class-rsh-core.php';

register_activation_hook( __FILE__, [ 'RSH_Core', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'RSH_Core', 'deactivate' ] );

// Bootstrap. Listeners must be wired on every request (including WP-Cron and the
// front-end order path), so instantiate at load rather than on a late hook.
RSH_Core::instance();
