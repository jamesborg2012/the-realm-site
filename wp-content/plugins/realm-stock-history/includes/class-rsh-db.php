<?php
/**
 * Data layer: table creation/upgrade, the single insert path, read queries, and the purge.
 *
 * @package Realm_Stock_History
 */

defined( 'ABSPATH' ) || exit;

class RSH_DB {

	/** Schema version — bump when the CREATE TABLE below changes. */
	const DB_VERSION = '1.0';

	/** Closed set of change types. Anything else coerces to 'other'. */
	const CHANGE_TYPES = [ 'sale', 'restock', 'manual', 'import', 'scanner', 'marketing', 'other' ];

	/** Retention window, in months. Hardcoded by design. */
	const RETENTION_MONTHS = 12;

	private static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'rsh_stock_history';
	}

	/**
	 * Create or migrate the table via dbDelta, and stamp the schema version.
	 */
	public static function install(): void {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id   BIGINT UNSIGNED NOT NULL,
			qty_change   INT             NOT NULL,
			stock_before INT             NULL,
			stock_after  INT             NULL,
			change_type  VARCHAR(20)     NOT NULL,
			order_id     BIGINT UNSIGNED NULL,
			user_id      BIGINT UNSIGNED NULL,
			note         VARCHAR(255)    NULL,
			created_at   DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			KEY product_created (product_id, created_at),
			KEY order_id (order_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'rsh_db_version', self::DB_VERSION );
	}

	/**
	 * Self-heal / migrate the schema if the stored version is stale or missing.
	 * Cheap enough to run on admin_init.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'rsh_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * The single insert path. Validates, derives the missing member of the
	 * qty/before/after trio, coerces the change type, and writes one row.
	 *
	 * Fail-soft: never throws, never emits a warning on malformed input. Rejected
	 * input is logged under WP_DEBUG. See RSH_Listeners for the full contract.
	 *
	 * @param array $args See the `rsh_record_stock_change` docblock in RSH_Listeners.
	 * @return int|false Insert ID on success, false on rejection.
	 */
	public static function record( array $args ) {
		global $wpdb;

		$product_id = isset( $args['product_id'] ) ? absint( $args['product_id'] ) : 0;
		if ( ! $product_id ) {
			RSH_Core::log( 'record() rejected: missing/invalid product_id', $args );
			return false;
		}

		// Distinguish "key omitted" (derive) from "key present but null" (respect
		// the intentional null — e.g. a null->number stock-management-switched-on row).
		$before_null_intent = array_key_exists( 'stock_before', $args ) && null === $args['stock_before'];

		$stock_before = self::nullable_int( $args['stock_before'] ?? null );
		$stock_after  = self::nullable_int( $args['stock_after'] ?? null );
		$qty_change   = self::nullable_int( $args['qty_change'] ?? null );

		$has_before = null !== $stock_before;
		$has_after  = null !== $stock_after;
		$has_qty    = null !== $qty_change;

		if ( $has_before && $has_after ) {
			// All-consistent or inconsistent-with-qty: trust before/after, recompute qty.
			$qty_change = $stock_after - $stock_before;
		} elseif ( $has_qty && $has_before ) {
			$stock_after = $stock_before + $qty_change;
		} elseif ( $has_qty && $has_after && ! $before_null_intent ) {
			$stock_before = $stock_after - $qty_change;
		} elseif ( $has_qty ) {
			// qty only (or before intentionally null): before/after stay as given.
		} else {
			// Only one of before/after and no qty — no usable delta. Reject.
			RSH_Core::log( 'record() rejected: no usable qty_change', $args );
			return false;
		}

		$type = isset( $args['change_type'] ) ? sanitize_key( (string) $args['change_type'] ) : 'other';
		if ( ! in_array( $type, self::CHANGE_TYPES, true ) ) {
			$type = 'other';
		}

		$order_id = ! empty( $args['order_id'] ) ? absint( $args['order_id'] ) : null;

		// user_id: omitted -> default to the current user when non-zero; present
		// (even as null) -> respect it verbatim (order rows pass an explicit null).
		if ( array_key_exists( 'user_id', $args ) ) {
			$user_id = ( null === $args['user_id'] ) ? null : ( absint( $args['user_id'] ) ?: null );
		} else {
			$current = get_current_user_id();
			$user_id = $current ? $current : null;
		}

		$note = null;
		if ( isset( $args['note'] ) && '' !== $args['note'] ) {
			$note = mb_substr( sanitize_text_field( (string) $args['note'] ), 0, 255 );
		}

		$data = [
			'product_id'   => $product_id,
			'qty_change'   => (int) $qty_change,
			'stock_before' => $stock_before,
			'stock_after'  => $stock_after,
			'change_type'  => $type,
			'order_id'     => $order_id,
			'user_id'      => $user_id,
			'note'         => $note,
			'created_at'   => current_time( 'mysql' ),
		];

		// $wpdb->insert writes SQL NULL for null values regardless of the format hint.
		$inserted = $wpdb->insert(
			self::table_name(),
			$data,
			[ '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			RSH_Core::log( 'record() insert failed', [ 'error' => $wpdb->last_error, 'data' => $data ] );
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Aggregate totals for a product across all retained history.
	 *
	 * @return array{total_in:int,total_out:int,net:int,marketing_out:int}
	 */
	public static function get_totals( int $product_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					COALESCE( SUM( CASE WHEN qty_change > 0 THEN qty_change ELSE 0 END ), 0 ) AS total_in,
					COALESCE( SUM( CASE WHEN qty_change < 0 THEN qty_change ELSE 0 END ), 0 ) AS total_out,
					COALESCE( SUM( qty_change ), 0 ) AS net,
					COALESCE( SUM( CASE WHEN change_type = %s THEN qty_change ELSE 0 END ), 0 ) AS marketing_out
				FROM ' . self::table_name() . '
				WHERE product_id = %d',
				'marketing',
				$product_id
			),
			ARRAY_A
		);

		return [
			'total_in'      => (int) ( $row['total_in'] ?? 0 ),
			'total_out'     => (int) ( $row['total_out'] ?? 0 ),
			'net'           => (int) ( $row['net'] ?? 0 ),
			'marketing_out' => (int) ( $row['marketing_out'] ?? 0 ),
		];
	}

	/** Total number of history rows for a product (for pagination). */
	public static function count_rows( int $product_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE product_id = %d',
				$product_id
			)
		);
	}

	/**
	 * One page of history rows for a product, newest first.
	 *
	 * @return array<int,object>
	 */
	public static function get_rows( int $product_id, int $per_page, int $offset ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . '
				WHERE product_id = %d
				ORDER BY created_at DESC, id DESC
				LIMIT %d OFFSET %d',
				$product_id,
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Batched retention purge: delete rows older than the retention window.
	 * Never an unbounded DELETE — 5,000 rows per statement, capped iterations
	 * per run, leaving any remainder to the next daily run.
	 *
	 * @return int Total rows deleted this run.
	 */
	public static function purge(): int {
		global $wpdb;

		$cutoff = gmdate(
			'Y-m-d H:i:s',
			strtotime( '-' . self::RETENTION_MONTHS . ' months', (int) current_time( 'timestamp' ) )
		);

		$batch      = 5000;
		$max_loops  = 10;
		$total      = 0;

		for ( $i = 0; $i < $max_loops; $i++ ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . self::table_name() . ' WHERE created_at < %s LIMIT %d',
					$cutoff,
					$batch
				)
			);

			if ( ! $deleted ) {
				break;
			}

			$total += (int) $deleted;

			if ( (int) $deleted < $batch ) {
				break;
			}
		}

		return $total;
	}

	/** Coerce a value to int, treating null/'' as SQL NULL. */
	private static function nullable_int( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (int) $value;
	}
}
