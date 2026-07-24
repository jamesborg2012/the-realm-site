<?php

/**
 * "Pre-Orders" carousel (item 33).
 *
 * Games Workshop products are listed for pre-order before release: they go into the "Coming Soon"
 * category with a release date, and TRM_Coming_Soon's daily cron strips that category on release day
 * (at which point the New Releases carousel picks them up). Between those two moments nothing on the
 * site surfaces what is currently open for pre-order — shoppers have to know to visit the Coming Soon
 * archive. This block puts live pre-orders on the homepage: products created in the last 14 days that
 * are *currently* in the Coming Soon category.
 *
 * On this store a product is created in WordPress at the moment its pre-order opens, so the core
 * `post_date` column is the correct "recently opened for pre-order" signal — no new date/meta field.
 *
 * This is the mirror image of TRM_New_Releases: New Releases *vetoes* Coming Soon products, this one
 * shows *only* them. Because Coming Soon products are hidden from every frontend listing by
 * TRM_Coming_Soon's pre_get_posts gate, the query opts out of that gate with the
 * {@see TRM_Coming_Soon::BYPASS_QUERY_VAR} query var (its one deliberate cross-feature touchpoint).
 *
 * All the shared machinery — block registration, the optional Title field, the category-grouping
 * sort, the display cap, the self-invalidating transient, and asset enqueue — comes from
 * TRM_Carousel_Block. Cards are plain WooCommerce `content-product` items (no badge).
 */
class TRM_Pre_Orders extends TRM_Carousel_Block
{
    /** Full ACF block name (category trm-blocks). */
    const BLOCK_NAME = 'pre-orders-carousel';

    /** Option holding the display cap (max products rendered). */
    const OPTION_MAX = 'trm_pre_orders_max_products';

    /** Transient caching the sorted, uncapped ID list for the current window. */
    const TRANSIENT = 'trm_pre_orders_ids';

    /** Length of the "recently opened for pre-order" window, in days, measured on `post_date`. */
    const WINDOW_DAYS = 14;

    /* ---------------------------------------------------------------------
     * Identity
     * ------------------------------------------------------------------- */

    protected function block_title()
    {
        return __('Pre-Orders Carousel', 'the-realm-malta');
    }

    protected function block_description()
    {
        return __('Carousel of products opened for pre-order in the last 14 days, grouped by game system.', 'the-realm-malta');
    }

    protected function block_icon()
    {
        return 'clock';
    }

    protected function block_keywords()
    {
        return ['pre-order', 'preorder', 'coming soon', 'carousel', 'products', 'realm'];
    }

    protected function field_group_key()
    {
        return 'group_trm_pre_orders';
    }

    protected function field_group_title()
    {
        return __('Pre-Orders Carousel Settings', 'the-realm-malta');
    }

    protected function field_key()
    {
        return 'field_trm_po_title';
    }

    protected function title_placeholder()
    {
        return __('Pre-Orders', 'the-realm-malta');
    }

    protected function variant_slug()
    {
        return 'pre-orders';
    }

    protected function region_label()
    {
        return __('Pre-Orders', 'the-realm-malta');
    }

    protected function empty_message()
    {
        return __('No products are currently open for pre-order.', 'the-realm-malta');
    }

    protected function log_label()
    {
        return 'TRM Pre-Orders';
    }

    /**
     * The corner-badge label. The base draws the triangular bottom-right badge over each card's
     * thumbnail, scoped to this block's loop.
     *
     * @return string
     */
    protected function badge_label()
    {
        return __('Pre-Order', 'the-realm-malta');
    }

    /**
     * Run after TRM_Preorder (default priority 10) so the "The Realm — Pre-Orders" section already
     * exists in the settings array when {@see add_settings()} splices its field into it.
     *
     * @return int
     */
    protected function settings_priority()
    {
        return 20;
    }

    /* ---------------------------------------------------------------------
     * Settings — spliced into the existing "The Realm — Pre-Orders" section
     * ------------------------------------------------------------------- */

    /**
     * Insert the Pre-Orders carousel display-cap field into the existing "The Realm — Pre-Orders"
     * section (owned by TRM_Preorder, id `trm_preorder_options`) — placed just before that section's
     * `sectionend`. Falls back to its own section only if that host section isn't present (e.g.
     * TRM_Preorder disabled), so the setting is never lost.
     *
     * Hook: woocommerce_general_settings (priority 20)
     *
     * @param array $settings
     * @return array
     */
    public function add_settings($settings)
    {
        $field = [
            'title'             => __('Pre-Orders Carousel — Maximum Products', 'the-realm-malta'),
            'desc'              => __('Maximum number of products shown in the Pre-Orders carousel block (products opened for pre-order in the last 14 days). Leave blank for the default of 20; the hard limit is 50.', 'the-realm-malta'),
            'id'                => self::OPTION_MAX,
            'type'              => 'number',
            'default'           => self::DEFAULT_MAX,
            'custom_attributes' => [
                'min'  => 1,
                'max'  => self::MAX_CAP,
                'step' => 1,
            ],
            'desc_tip'          => true,
        ];

        $out      = [];
        $inserted = false;

        foreach ($settings as $row) {
            if (
                !$inserted
                && isset($row['type'], $row['id'])
                && $row['type'] === 'sectionend'
                && $row['id'] === 'trm_preorder_options'
            ) {
                $out[]    = $field;
                $inserted = true;
            }
            $out[] = $row;
        }

        if (!$inserted) {
            $out[] = [
                'title' => __('The Realm — Pre-Orders', 'the-realm-malta'),
                'type'  => 'title',
                'id'    => 'trm_pre_orders_carousel_options',
            ];
            $out[] = $field;
            $out[] = [
                'type' => 'sectionend',
                'id'   => 'trm_pre_orders_carousel_options',
            ];
        }

        return $out;
    }

    /* ---------------------------------------------------------------------
     * Availability + window + query
     * ------------------------------------------------------------------- */

    /**
     * With no Coming Soon category configured there can be no pre-orders, so the block shows its empty
     * state without running a query.
     *
     * @return bool
     */
    protected function is_available()
    {
        return class_exists('TRM_Coming_Soon') && TRM_Coming_Soon::get_category_id() > 0;
    }

    /**
     * The window, in the site timezone, as `Y-m-d` strings. `start` = midnight WINDOW_DAYS days ago
     * (the cache key — it rolls forward daily, so the cache self-invalidates each day); `end` = today.
     *
     * @return array{start:string,end:string}
     */
    protected function get_window()
    {
        $now = new DateTimeImmutable('now', wp_timezone());

        return [
            'start' => $now->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d'),
            'end'   => $now->format('Y-m-d'),
        ];
    }

    /**
     * Pre-Orders query args: created in the last WINDOW_DAYS days (`post_date`), `_price` present, in
     * stock / on backorder & catalogue-visible, and *currently in* the Coming Soon category (+children).
     *
     * The BYPASS_QUERY_VAR flag makes TRM_Coming_Soon's pre_get_posts gate skip this secondary query —
     * without it, the gate would strip exactly the Coming Soon products this block exists to show.
     *
     * @param array{start:string,end:string} $window
     * @return array
     */
    protected function build_query_args(array $window)
    {
        $coming_soon = TRM_Coming_Soon::get_category_id();

        $tax_query = [
            'relation' => 'AND',
            $this->visibility_tax_clause(),
            [
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => [$coming_soon],
                'operator'         => 'IN',
                'include_children' => true,
            ],
        ];

        return [
            // Opt out of the Coming Soon frontend-listing exclusion (this block wants those products).
            TRM_Coming_Soon::BYPASS_QUERY_VAR => true,
            'date_query' => [
                [
                    'column'    => 'post_date',
                    'after'     => $window['start'] . ' 00:00:00',
                    'inclusive' => true,
                ],
            ],
            'meta_query' => array_merge(['relation' => 'AND'], $this->price_gate_clauses()),
            'tax_query'  => $tax_query,
        ];
    }
}
