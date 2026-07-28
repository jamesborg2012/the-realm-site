<?php
/**
 * Admin layer: product-editor metabox, the central WooCommerce → Stock History
 * page, the shared summary+table renderer, the AJAX endpoint, and asset enqueue.
 *
 * The summary + table markup is single-sourced in render_history_html(); both the
 * metabox panel and the central page load it through the one AJAX endpoint.
 *
 * @package Realm_Stock_History
 */

defined( 'ABSPATH' ) || exit;

class RSH_Admin {

	const MENU_SLUG   = 'rsh-stock-history';
	const NONCE       = 'rsh_stock_history';
	const AJAX_ACTION = 'rsh_fetch_stock_history';
	const PER_PAGE    = 25;
	const SEARCH_CAP  = 25;

	/** Human labels for each change type. */
	private const TYPE_LABELS = [
		'sale'      => 'Sale',
		'restock'   => 'Restock',
		'manual'    => 'Manual',
		'import'    => 'Import',
		'scanner'   => 'Scanner',
		'marketing' => 'Marketing',
		'other'     => 'Other',
	];

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
		add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_fetch' ] );
	}

	/* ---------------------------------------------------------------------
	 * Product-editor metabox
	 * ------------------------------------------------------------------- */

	public function add_metabox(): void {
		add_meta_box(
			'rsh_stock_history',
			__( 'Stock History', 'realm-stock-history' ),
			[ $this, 'render_metabox' ],
			'product',
			'normal',
			'high'
		);
	}

	public function render_metabox( $post ): void {
		$product_id = (int) $post->ID;
		?>
		<div class="rsh-metabox">
			<button type="button" class="button rsh-toggle" id="rsh-toggle-<?php echo esc_attr( $product_id ); ?>"
				aria-expanded="false" aria-controls="rsh-panel-<?php echo esc_attr( $product_id ); ?>"
				data-product="<?php echo esc_attr( $product_id ); ?>"
				data-label-show="<?php esc_attr_e( 'Show Stock History', 'realm-stock-history' ); ?>"
				data-label-hide="<?php esc_attr_e( 'Hide Stock History', 'realm-stock-history' ); ?>">
				<?php esc_html_e( 'Show Stock History', 'realm-stock-history' ); ?>
			</button>
			<div id="rsh-panel-<?php echo esc_attr( $product_id ); ?>" class="rsh-panel" hidden></div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Central page: WooCommerce -> Stock History
	 * ------------------------------------------------------------------- */

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Stock History', 'realm-stock-history' ),
			__( 'Stock History', 'realm-stock-history' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$term       = isset( $_GET['rsh_s'] ) ? sanitize_text_field( wp_unslash( $_GET['rsh_s'] ) ) : '';
		$product_id = isset( $_GET['rsh_product'] ) ? absint( $_GET['rsh_product'] ) : 0;

		echo '<div class="wrap rsh-wrap">';
		echo '<h1>' . esc_html__( 'Stock History', 'realm-stock-history' ) . '</h1>';

		// Search form (GET, no AJAX needed for search itself).
		echo '<form method="get" class="rsh-search-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		echo '<p class="search-box">';
		echo '<label class="screen-reader-text" for="rsh-search-input">'
			. esc_html__( 'Search products by SKU or name', 'realm-stock-history' ) . '</label>';
		echo '<input type="search" id="rsh-search-input" name="rsh_s" value="' . esc_attr( $term )
			. '" placeholder="' . esc_attr__( 'SKU or product name', 'realm-stock-history' ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Search', 'realm-stock-history' ) . '</button>';
		echo '</p>';
		echo '</form>';

		if ( $product_id && current_user_can( 'edit_post', $product_id ) ) {
			$this->render_selected_product( $product_id );
		} elseif ( '' !== $term ) {
			$this->render_search_results( $term );
		} else {
			// No search yet — explain the page + retention notice.
			echo '<p class="rsh-intro">' . esc_html__(
				'Search for a product by SKU or name to see every recorded stock movement — how much came in, how much went out, and when.',
				'realm-stock-history'
			) . '</p>';
			echo $this->retention_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* internally.
		}

		echo '</div>';
	}

	/** Render the heading + auto-loading panel for a chosen product. */
	private function render_selected_product( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			echo '<p>' . esc_html__( 'Product not found.', 'realm-stock-history' ) . '</p>';
			return;
		}

		echo '<h2 class="rsh-product-heading">' . esc_html( $product->get_name() );
		if ( $product->get_sku() ) {
			echo ' <span class="rsh-sku">(' . esc_html( $product->get_sku() ) . ')</span>';
		}
		echo '</h2>';

		printf(
			'<div class="rsh-panel" data-product="%d" data-rsh-auto="1"></div>',
			esc_attr( $product_id )
		);
	}

	/** Run the product search and render the outcome. */
	private function render_search_results( string $term ): void {
		// Exact SKU goes straight to the product.
		$sku_id = wc_get_product_id_by_sku( $term );
		if ( $sku_id ) {
			$this->render_selected_product( (int) $sku_id );
			return;
		}

		$query = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => self::SEARCH_CAP,
			's'              => $term,
			'no_found_rows'  => true,
			'fields'         => 'ids',
		] );

		$ids = $query->posts;

		if ( empty( $ids ) ) {
			printf(
				'<p class="rsh-no-results">%s</p>',
				esc_html( sprintf(
					/* translators: %s: search term. */
					__( "No products found for '%s'.", 'realm-stock-history' ),
					$term
				) )
			);
			return;
		}

		// Exactly one match — render it directly.
		if ( 1 === count( $ids ) ) {
			$this->render_selected_product( (int) $ids[0] );
			return;
		}

		echo '<table class="widefat striped rsh-results">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Product', 'realm-stock-history' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'SKU', 'realm-stock-history' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Current stock', 'realm-stock-history' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$link = add_query_arg(
				[
					'page'        => self::MENU_SLUG,
					'rsh_product' => $id,
					'rsh_s'       => $term,
				],
				admin_url( 'admin.php' )
			);
			$stock = $product->get_stock_quantity();
			echo '<tr>';
			echo '<td><a href="' . esc_url( $link ) . '">' . esc_html( $product->get_name() ) . '</a></td>';
			echo '<td>' . esc_html( $product->get_sku() ?: '—' ) . '</td>';
			echo '<td>' . esc_html( null === $stock ? '—' : (string) (int) $stock ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * AJAX — the single endpoint serving both views
	 * ------------------------------------------------------------------- */

	public function ajax_fetch(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'realm-stock-history' ) ], 403 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $product_id || ! current_user_can( 'edit_post', $product_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied for this product.', 'realm-stock-history' ) ], 403 );
		}

		$page = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;

		wp_send_json_success( [ 'html' => $this->render_history_html( $product_id, $page ) ] );
	}

	/* ---------------------------------------------------------------------
	 * Shared renderer (single-sourced)
	 * ------------------------------------------------------------------- */

	/**
	 * Build the summary block + paginated table for a product. Three queries:
	 * totals, count, and one page of rows.
	 */
	public function render_history_html( int $product_id, int $page ): string {
		$per_page = self::PER_PAGE;
		$offset   = ( $page - 1 ) * $per_page;

		$totals    = RSH_DB::get_totals( $product_id );
		$total_rows = RSH_DB::count_rows( $product_id );
		$rows      = RSH_DB::get_rows( $product_id, $per_page, $offset );

		$product      = wc_get_product( $product_id );
		$current      = $product ? $product->get_stock_quantity() : null;
		$started      = get_option( 'rsh_tracking_started' );
		$started_disp = $started ? esc_html( $started ) : esc_html__( 'tracking start unknown', 'realm-stock-history' );

		ob_start();

		// --- Summary block ---
		echo '<div class="rsh-summary">';
		echo '<span class="rsh-stat rsh-stat--in"><span class="rsh-stat__label">'
			. esc_html__( 'Stock In', 'realm-stock-history' ) . ':</span> +'
			. esc_html( (string) $totals['total_in'] ) . '</span>';
		echo '<span class="rsh-stat rsh-stat--out"><span class="rsh-stat__label">'
			. esc_html__( 'Stock Out', 'realm-stock-history' ) . ':</span> −'
			. esc_html( (string) abs( $totals['total_out'] ) ) . '</span>';
		echo '<span class="rsh-stat rsh-stat--net"><span class="rsh-stat__label">'
			. esc_html__( 'Net', 'realm-stock-history' ) . ':</span> '
			. esc_html( $this->signed( $totals['net'] ) ) . '</span>';
		echo '<span class="rsh-stat rsh-stat--current"><span class="rsh-stat__label">'
			. esc_html__( 'Current stock', 'realm-stock-history' ) . ':</span> '
			. esc_html( null === $current ? '—' : (string) (int) $current ) . '</span>';
		if ( 0 !== $totals['marketing_out'] ) {
			echo '<span class="rsh-stat rsh-stat--marketing"><span class="rsh-stat__label">'
				. esc_html__( 'of which marketing', 'realm-stock-history' ) . ':</span> −'
				. esc_html( (string) abs( $totals['marketing_out'] ) ) . '</span>';
		}
		echo '</div>';

		// Tracking-start line.
		printf(
			'<p class="rsh-tracking-start">%s</p>',
			esc_html( sprintf(
				/* translators: %s: date tracking began. */
				__( 'Tracking started %s', 'realm-stock-history' ),
				$started ?: __( '(unknown)', 'realm-stock-history' )
			) )
		);

		// Retention notice.
		echo $this->retention_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* internally.

		if ( 0 === $total_rows ) {
			echo '<p class="rsh-empty">' . esc_html__( 'No stock movements recorded for this product.', 'realm-stock-history' ) . '</p>';
			return (string) ob_get_clean();
		}

		// --- Table ---
		echo '<table class="widefat striped rsh-table">';
		echo '<thead><tr>';
		foreach ( [
			__( 'Date/Time', 'realm-stock-history' ),
			__( 'Type', 'realm-stock-history' ),
			__( 'Change', 'realm-stock-history' ),
			__( 'Stock Before', 'realm-stock-history' ),
			__( 'Stock After', 'realm-stock-history' ),
			__( 'Order', 'realm-stock-history' ),
			__( 'User', 'realm-stock-history' ),
			__( 'Note', 'realm-stock-history' ),
		] as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$this->render_row( $row );
		}

		echo '</tbody></table>';

		// --- Pagination ---
		$total_pages = (int) ceil( $total_rows / $per_page );
		if ( $total_pages > 1 ) {
			echo '<div class="rsh-pagination">';
			$this->page_button( $page - 1, $page > 1, __( '‹ Prev', 'realm-stock-history' ) );
			printf(
				'<span class="rsh-page-info">%s</span>',
				esc_html( sprintf(
					/* translators: 1: current page, 2: total pages. */
					__( 'Page %1$d of %2$d', 'realm-stock-history' ),
					$page,
					$total_pages
				) )
			);
			$this->page_button( $page + 1, $page < $total_pages, __( 'Next ›', 'realm-stock-history' ) );
			echo '</div>';
		}

		return (string) ob_get_clean();
	}

	/** Render one history table row. */
	private function render_row( $row ): void {
		$type       = (string) $row->change_type;
		$type_label = self::TYPE_LABELS[ $type ] ?? ucfirst( $type );
		$qty        = (int) $row->qty_change;
		$change_cls = $qty > 0 ? 'rsh-change--in' : ( $qty < 0 ? 'rsh-change--out' : 'rsh-change--zero' );

		echo '<tr>';

		// Date/Time.
		echo '<td>' . esc_html( $this->format_datetime( $row->created_at ) ) . '</td>';

		// Type — label + colour class; marketing carries an extra flag class.
		printf(
			'<td><span class="rsh-type rsh-type--%1$s">%2$s</span></td>',
			esc_attr( $type ),
			esc_html( $type_label )
		);

		// Change — signed (sign is text, never colour alone).
		echo '<td><span class="rsh-change ' . esc_attr( $change_cls ) . '">'
			. esc_html( $this->signed( $qty ) ) . '</span></td>';

		// Before / after.
		echo '<td>' . esc_html( null === $row->stock_before ? '—' : (string) (int) $row->stock_before ) . '</td>';
		echo '<td>' . esc_html( null === $row->stock_after ? '—' : (string) (int) $row->stock_after ) . '</td>';

		// Order — HPOS-safe link where the order still exists.
		echo '<td>' . $this->order_cell( $row->order_id ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* internally.

		// User.
		echo '<td>' . esc_html( $this->user_label( $row->user_id ) ) . '</td>';

		// Note.
		echo '<td>' . esc_html( $row->note ?? '' ) . '</td>';

		echo '</tr>';
	}

	private function order_cell( $order_id ): string {
		$order_id = (int) $order_id;
		if ( ! $order_id ) {
			return '—';
		}
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#'
					. esc_html( (string) $order_id ) . '</a>';
			}
		}
		// Order gone — plain text fallback.
		return '#' . esc_html( (string) $order_id );
	}

	private function user_label( $user_id ): string {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return '—';
		}
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : ( '#' . $user_id );
	}

	/** Emit a pagination button (real button; JS handles the fetch). */
	private function page_button( int $target, bool $enabled, string $label ): void {
		$disabled = $enabled ? '' : ' disabled';
		printf(
			'<button type="button" class="button rsh-page-btn" data-page="%1$d"%2$s>%3$s</button>',
			esc_attr( (string) max( 1, $target ) ),
			$disabled, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
			esc_html( $label )
		);
	}

	private function retention_notice(): string {
		return '<p class="rsh-retention-notice">'
			. esc_html__( 'Stock history older than 12 months is not retained.', 'realm-stock-history' )
			. '</p>';
	}

	private function signed( int $n ): string {
		if ( $n > 0 ) {
			return '+' . $n;
		}
		if ( $n < 0 ) {
			return '−' . abs( $n ); // Unicode minus for display.
		}
		return '0';
	}

	private function format_datetime( $mysql_datetime ): string {
		$ts = strtotime( (string) $mysql_datetime );
		if ( ! $ts ) {
			return (string) $mysql_datetime;
		}
		return date_i18n(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$ts
		);
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	public function enqueue( $hook ): void {
		$screen = get_current_screen();
		$is_product_editor = $screen && 'product' === $screen->id;
		$is_history_page   = $screen && false !== strpos( (string) $screen->id, self::MENU_SLUG );

		if ( ! $is_product_editor && ! $is_history_page ) {
			return;
		}

		wp_enqueue_style(
			'rsh-admin',
			RSH_PLUGIN_URL . 'assets/rsh-admin.css',
			[],
			RSH_VERSION
		);

		wp_enqueue_script(
			'rsh-admin',
			RSH_PLUGIN_URL . 'assets/rsh-admin.js',
			[],
			RSH_VERSION,
			true
		);

		wp_localize_script(
			'rsh-admin',
			'rshAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => [
					'error'   => __( 'Could not load stock history. Please try again.', 'realm-stock-history' ),
					'loading' => __( 'Loading…', 'realm-stock-history' ),
				],
			]
		);
	}
}
