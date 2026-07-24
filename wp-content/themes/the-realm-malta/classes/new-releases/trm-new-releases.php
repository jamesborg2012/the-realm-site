<?php

/**
 * "New Releases" carousel (item 32).
 *
 * Games Workshop launches new product on Saturdays: items are pre-listed in the "Coming Soon"
 * category carrying a `trm_release_date`, and a daily cron (TRM_Coming_Soon) strips the category on
 * release day. Once live, nothing surfaced those fresh arrivals — they just merged into the
 * catalogue. This feature adds a placeable ACF block that shows every product released in the
 * current release week (most recent Saturday → today), grouped by top-level product category (so
 * all 40K sits together, then all Age of Sigmar, …) in WooCommerce category display order.
 *
 * As of item 33 the block machinery (registration, cache, cap, category-grouping sort, asset
 * enqueue, render flow) lives in TRM_Carousel_Block; this subclass supplies only the New Releases
 * specifics: its window, its query, its "The Realm — New Releases" settings section, and the "New!"
 * corner badge. User-visible behaviour is unchanged from item 32.
 *
 * The block reuses WooCommerce's standard loop item (`content-product`) so member-discount / sale
 * price formatting and the "Order Now" backorder label flow through untouched — no bespoke card.
 */
class TRM_New_Releases extends TRM_Carousel_Block
{
    /** Full ACF block name (category trm-blocks). */
    const BLOCK_NAME = 'new-releases-carousel';

    /** Option holding the display cap (max products rendered). */
    const OPTION_MAX = 'trm_new_releases_max_products';

    /** Transient caching the sorted, uncapped ID list for the current release week. */
    const TRANSIENT = 'trm_new_releases_ids';

    /* ---------------------------------------------------------------------
     * Identity
     * ------------------------------------------------------------------- */

    protected function block_title()
    {
        return __('New Releases Carousel', 'the-realm-malta');
    }

    protected function block_description()
    {
        return __("Carousel of this release week's products, grouped by game system.", 'the-realm-malta');
    }

    protected function block_icon()
    {
        return 'star-filled';
    }

    protected function block_keywords()
    {
        return ['new', 'releases', 'carousel', 'products', 'realm'];
    }

    protected function field_group_key()
    {
        return 'group_trm_new_releases';
    }

    protected function field_group_title()
    {
        return __('New Releases Carousel Settings', 'the-realm-malta');
    }

    protected function field_key()
    {
        return 'field_trm_nr_title';
    }

    protected function title_placeholder()
    {
        return __('New Releases', 'the-realm-malta');
    }

    protected function variant_slug()
    {
        return 'new-releases';
    }

    protected function region_label()
    {
        return __('New Releases', 'the-realm-malta');
    }

    protected function empty_message()
    {
        return __('No products released this week.', 'the-realm-malta');
    }

    protected function log_label()
    {
        return 'TRM New Releases';
    }

    /* ---------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------- */

    /**
     * Add a "The Realm — New Releases" section to WC → Settings → General.
     *
     * Hook: woocommerce_general_settings
     *
     * @param array $settings
     * @return array
     */
    public function add_settings($settings)
    {
        $section = [
            [
                'title' => __('The Realm — New Releases', 'the-realm-malta'),
                'type'  => 'title',
                'desc'  => __('Controls the New Releases carousel block (this release week\'s products, grouped by game system).', 'the-realm-malta'),
                'id'    => 'trm_new_releases_options',
            ],
            [
                'title'             => __('New Releases — Maximum Products', 'the-realm-malta'),
                'desc'              => __('Maximum number of products shown in the New Releases carousel. Leave blank for the default of 20; the hard limit is 50.', 'the-realm-malta'),
                'id'                => self::OPTION_MAX,
                'type'              => 'number',
                'default'           => self::DEFAULT_MAX,
                'custom_attributes' => [
                    'min'  => 1,
                    'max'  => self::MAX_CAP,
                    'step' => 1,
                ],
                'desc_tip'          => true,
            ],
            [
                'type' => 'sectionend',
                'id'   => 'trm_new_releases_options',
            ],
        ];

        return array_merge($settings, $section);
    }

    /* ---------------------------------------------------------------------
     * Window + query
     * ------------------------------------------------------------------- */

    /**
     * The release-week window, in the site timezone, as `Y-m-d` date strings.
     *
     * Window = [most recent Saturday … today], inclusive. On a Saturday the start IS today (not the
     * previous Saturday) — DateTimeImmutable::modify('last saturday') would step back a week when
     * called on a Saturday, so we branch on the ISO weekday (6 = Saturday) instead. The window
     * deliberately ends at today, never in the future (a Thursday/Friday-dated product that was never
     * placed in Coming Soon must not surface before it actually launches).
     *
     * @return array{start:string,end:string}
     */
    protected function get_window()
    {
        $now = new DateTimeImmutable('now', wp_timezone());

        $start = ($now->format('N') === '6')
            ? $now
            : $now->modify('last saturday');

        return [
            'start' => $start->format('Y-m-d'),
            'end'   => $now->format('Y-m-d'),
        ];
    }

    /**
     * New Releases query args: `trm_release_date` in-window, `_price` present, in stock / on backorder
     * & catalogue-visible, and (veto) NOT in the Coming Soon category — a delayed launch deliberately
     * left in Coming Soon must stay hidden even with an in-window release date.
     *
     * @param array{start:string,end:string} $window
     * @return array
     */
    protected function build_query_args(array $window)
    {
        $meta_query = array_merge(
            [
                'relation' => 'AND',
                [
                    'key'     => 'trm_release_date',
                    // Lexicographic BETWEEN on the stored Y-m-d string (chronological for ISO dates).
                    // type=CHAR on purpose: CAST(... AS DATE) silently yields no matches on this data
                    // (see CHANGES item 10). Empty meta sorts before any 2xxx- window start, so it drops.
                    'value'   => [$window['start'], $window['end']],
                    'compare' => 'BETWEEN',
                    'type'    => 'CHAR',
                ],
            ],
            $this->price_gate_clauses()
        );

        $tax_query = [
            'relation' => 'AND',
            $this->visibility_tax_clause(),
        ];

        // Coming Soon veto: skip the clause entirely when no category is configured.
        if (class_exists('TRM_Coming_Soon')) {
            $coming_soon = TRM_Coming_Soon::get_category_id();
            if ($coming_soon) {
                $tax_query[] = [
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => [$coming_soon],
                    'operator'         => 'NOT IN',
                    'include_children' => true,
                ];
            }
        }

        return [
            'meta_query' => $meta_query,
            'tax_query'  => $tax_query,
        ];
    }

    /* ---------------------------------------------------------------------
     * "New!" corner badge
     * ------------------------------------------------------------------- */

    /**
     * The corner-badge label. The base handles the wrap/render/teardown, scoped to this block's loop.
     *
     * @return string
     */
    protected function badge_label()
    {
        return __('New!', 'the-realm-malta');
    }
}
