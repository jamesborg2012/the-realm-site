<?php
/**
 * Capture layer.
 *
 * Two mutually-exclusive capture paths (this is the core architectural rule):
 *
 *   1. WooCommerce-driven changes — captured here via WooCommerce's own stock hooks.
 *      Order lines are recorded from the per-line order hooks; every other WC stock
 *      write (manual/quick/bulk edit, CSV import, barcode-scanner CRUD, programmatic
 *      wc_update_product_stock) is recorded from the product-level set-stock hook.
 *
 *   2. Direct writes by the theme/other plugins that bypass WooCommerce's stock APIs —
 *      captured only when that code fires the public `rsh_record_stock_change` action.
 *
 * De-duplication: order reductions/restorations call wc_update_product_stock(), which
 * ALSO fires woocommerce_product_set_stock. Without suppression an order line would be
 * recorded twice (once as an order row, once as a product row). We therefore suppress
 * the product-level recorder for the duration of an order stock operation via an
 * in-flight flag: set in the woocommerce_can_(reduce|restore)_order_stock filters
 * (which fire once, before the item loop) and cleared in woocommerce_(reduce|restore)_order_stock
 * (once, after the loop). The flag is a plain instance property, so it can never leak
 * across requests. Result: exactly one row per logical stock change.
 *
 * @package Realm_Stock_History
 */

defined( 'ABSPATH' ) || exit;

class RSH_Listeners {

	/** True while WooCommerce is inside an order stock reduce/restore. */
	private bool $in_order_stock = false;

	/** Cached pre-change stock levels for the product recorder, keyed by product ID. */
	private array $stock_before = [];

	public function __construct() {
		// Capture path 1a — order-driven (carry exact before/after + order).
		add_action( 'woocommerce_reduce_order_item_stock', [ $this, 'on_reduce_order_item' ], 10, 3 );
		add_action( 'woocommerce_restore_order_item_stock', [ $this, 'on_restore_order_item' ], 10, 4 );

		// In-flight guard around the order stock loop (see class docblock).
		add_filter( 'woocommerce_can_reduce_order_stock', [ $this, 'begin_order_stock' ], 10, 2 );
		add_filter( 'woocommerce_can_restore_order_stock', [ $this, 'begin_order_stock' ], 10, 2 );
		add_action( 'woocommerce_reduce_order_stock', [ $this, 'end_order_stock' ] );
		add_action( 'woocommerce_restore_order_stock', [ $this, 'end_order_stock' ] );

		// Capture path 1b — every other WC stock write.
		add_action( 'woocommerce_product_before_set_stock', [ $this, 'capture_before' ], 10, 1 );
		add_action( 'woocommerce_product_set_stock', [ $this, 'on_product_set_stock' ], 10, 1 );

		// Capture path 2 — the public, fail-soft action.
		add_action( 'rsh_record_stock_change', [ $this, 'on_public_record' ], 10, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Order-driven capture
	 * ------------------------------------------------------------------- */

	/**
	 * Filter callback (value passed through unchanged) that arms the in-flight
	 * guard just before WooCommerce's order stock loop runs. Only arms when the
	 * operation is actually going ahead, so a vetoed op can't strand the flag.
	 *
	 * @param bool     $can   Whether WC will reduce/restore stock.
	 * @param WC_Order $order The order.
	 * @return bool Unchanged.
	 */
	public function begin_order_stock( $can, $order ) {
		if ( $can ) {
			$this->in_order_stock = true;
		}
		return $can;
	}

	/** Disarm the in-flight guard once the order stock loop has finished. */
	public function end_order_stock( $order ): void {
		$this->in_order_stock = false;
	}

	/**
	 * Record one row per order line when WooCommerce reduces stock.
	 *
	 * @param WC_Order_Item_Product $item   Order line item.
	 * @param array                 $change { product, from, to } as built by wc_reduce_stock_levels().
	 * @param WC_Order              $order  The order.
	 */
	public function on_reduce_order_item( $item, $change, $order ): void {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
			return;
		}
		$product = $item->get_product();
		if ( ! $product ) {
			return; // Product deleted since the order — skip silently.
		}

		$from = isset( $change['from'] ) ? (int) $change['from'] : null;
		$to   = isset( $change['to'] ) ? (int) $change['to'] : null;
		if ( null === $from || null === $to ) {
			return;
		}

		$type = $this->order_is_marketing( $order ) ? 'marketing' : 'sale';

		RSH_DB::record( [
			'product_id'   => $product->get_id(),
			'stock_before' => $from,
			'stock_after'  => $to,
			'qty_change'   => $to - $from,
			'change_type'  => $type,
			'order_id'     => $order->get_id(),
			'user_id'      => null, // Automated reduction, not a manual user action.
		] );
	}

	/**
	 * Record one row per order line when WooCommerce restores stock
	 * (order cancelled / refunded / set back to pending).
	 *
	 * @param WC_Order_Item_Product $item      Order line item.
	 * @param int                   $new_stock Stock after restore.
	 * @param int                   $old_stock Stock before restore.
	 * @param WC_Order              $order     The order.
	 */
	public function on_restore_order_item( $item, $new_stock, $old_stock, $order ): void {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
			return;
		}
		$product = $item->get_product();
		if ( ! $product ) {
			return;
		}

		RSH_DB::record( [
			'product_id'   => $product->get_id(),
			'stock_before' => (int) $old_stock,
			'stock_after'  => (int) $new_stock,
			'qty_change'   => (int) $new_stock - (int) $old_stock,
			'change_type'  => 'restock',
			'order_id'     => $order->get_id(),
			'user_id'      => null,
		] );
	}

	/** True when the order is an internal marketing order (theme or Sales System). */
	private function order_is_marketing( $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}
		return 'yes' === $order->get_meta( 'trm_is_marketing_order' )
			|| 'yes' === $order->get_meta( '_rss_marketing_order' );
	}

	/* ---------------------------------------------------------------------
	 * Product-level capture (everything else through WooCommerce)
	 * ------------------------------------------------------------------- */

	/**
	 * Snapshot the persisted stock level BEFORE the write lands. Fires ahead of
	 * both the wc_update_product_stock SQL update and the data-store meta write,
	 * so get_post_meta() still returns the old value here. An empty raw value
	 * (stock management just switched on) is recorded as NULL.
	 *
	 * @param WC_Product $product Product about to change.
	 */
	public function capture_before( $product ): void {
		if ( $this->in_order_stock || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return; // Order path is handled by the order hooks.
		}
		$pid = $product->get_id();
		$raw = get_post_meta( $pid, '_stock', true );
		$this->stock_before[ $pid ] = ( '' === $raw || null === $raw ) ? null : (int) $raw;
	}

	/**
	 * Record a non-order WooCommerce stock change. This action only fires when
	 * `_stock` actually changed, so unchanged saves never produce a row.
	 *
	 * @param WC_Product $product Product whose stock changed.
	 */
	public function on_product_set_stock( $product ): void {
		if ( $this->in_order_stock || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		if ( ! $product->managing_stock() ) {
			return; // No meaningful before/after to record.
		}

		$after = $product->get_stock_quantity();
		if ( null === $after || '' === $after ) {
			return;
		}
		$after = (int) $after;

		$pid = $product->get_id();

		// A paired before_set_stock always precedes a genuine set_stock in every
		// non-order path. If it's missing this is an anomalous second fire — skip
		// rather than invent a null-before row.
		if ( ! array_key_exists( $pid, $this->stock_before ) ) {
			return;
		}
		$before = $this->stock_before[ $pid ];
		unset( $this->stock_before[ $pid ] ); // Consume.

		// No actual delta -> nothing to log.
		if ( null !== $before && $before === $after ) {
			return;
		}

		$qty  = ( null === $before ) ? $after : ( $after - $before );
		$type = $this->is_import_context() ? 'import' : 'manual';

		RSH_DB::record( [
			'product_id'   => $pid,
			'stock_before' => $before, // null preserved for the switched-on case.
			'stock_after'  => $after,
			'qty_change'   => $qty,
			'change_type'  => $type,
			// user_id omitted -> defaults to the acting user (editor/quick/bulk edit).
		] );
	}

	/**
	 * Best-effort detection of the WooCommerce CSV product importer. Where it
	 * can't be detected the row still records (as 'manual') — mis-classification
	 * must never block the write.
	 */
	private function is_import_context(): bool {
		if ( wp_doing_ajax() && isset( $_REQUEST['action'] )
			&& 'woocommerce_do_ajax_product_import' === $_REQUEST['action'] ) {
			return true;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && did_action( 'woocommerce_product_import_before_import' ) ) {
			return true;
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Public action (capture path 2)
	 * ------------------------------------------------------------------- */

	/**
	 * Handler for `do_action( 'rsh_record_stock_change', $args )`.
	 *
	 * The single decoupled entry point for stock writes that bypass WooCommerce's
	 * stock APIs. When this plugin is inactive the action simply has no handler,
	 * so callers can fire it unconditionally as a no-op.
	 *
	 * $args (associative array):
	 *   - product_id   (int, REQUIRED)
	 *   - qty_change   (int, signed; negative = out) — REQUIRED unless both
	 *                  stock_before and stock_after are supplied (then derived).
	 *   - stock_before (int|null, optional)
	 *   - stock_after  (int|null, optional)
	 *   - change_type  (string, optional; defaults to 'other'; unknown values
	 *                  coerce to 'other'). One of:
	 *                  sale|restock|manual|import|scanner|marketing|other.
	 *   - order_id     (int|null, optional)
	 *   - user_id      (int|null, optional; omitted defaults to the current user
	 *                  when non-zero, else NULL; pass null explicitly to force NULL).
	 *   - note         (string, optional; truncated to 255 chars after sanitising).
	 *
	 * Derivation: given two of qty_change/stock_before/stock_after, the third is
	 * derived. Given all three inconsistently, before/after win and qty_change is
	 * recomputed. Given only one of before/after and no qty_change, the row is
	 * rejected (a row without a usable qty_change would corrupt the in/out totals).
	 *
	 * Fail-soft: never throws or warns; invalid input is rejected and logged under
	 * WP_DEBUG. Returns nothing (the return value of an action is discarded);
	 * RSH_DB::record() returns the insert ID / false for direct callers.
	 *
	 * @param mixed $args Expected to be an associative array as above.
	 */
	public function on_public_record( $args ): void {
		if ( ! is_array( $args ) ) {
			RSH_Core::log( 'rsh_record_stock_change fired with non-array payload', $args );
			return;
		}
		RSH_DB::record( $args );
	}
}
