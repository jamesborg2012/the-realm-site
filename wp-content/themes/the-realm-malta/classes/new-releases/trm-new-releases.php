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
 * Responsibilities held here:
 *   1. Register the `acf/new-releases-carousel` block (category `trm-blocks`) + its single Title field.
 *   2. A "The Realm — New Releases" section under WC → Settings → General with the display cap.
 *   3. The qualifying-products query, the PHP top-level-category grouping sort, and a transient cache.
 *   4. Block asset enqueue (Slick JS/CSS + the carousel init script).
 *
 * The block reuses WooCommerce's standard loop item (`content-product`) so member-discount / sale
 * price formatting and the "Order Now" backorder label flow through untouched — no bespoke card.
 */
class TRM_New_Releases extends TRM_Core
{
    /** Full ACF block name (category trm-blocks). */
    const BLOCK_NAME = 'new-releases-carousel';

    /** Option holding the display cap (max products rendered). */
    const OPTION_MAX = 'trm_new_releases_max_products';

    /** Transient caching the sorted, uncapped ID list for the current release week. */
    const TRANSIENT = 'trm_new_releases_ids';

    /** Default display cap when the option is empty / invalid. */
    const DEFAULT_MAX = 20;

    /** Hard ceiling on the display cap, enforced in code regardless of the stored value. */
    const MAX_CAP = 50;

    /**
     * `posts_per_page` ceiling for the underlying query. The cap tops out at 50, so 200 gives the
     * category grouping ample headroom while bounding a runaway (a bad bulk import). Hitting it is
     * logged as a signal.
     */
    const QUERY_CEILING = 200;

    public function __construct()
    {
        $this->register_hook_callbacks();
    }

    public function register_hook_callbacks()
    {
        // Block + its fields.
        add_action('acf/init', [$this, 'register_block']);
        add_action('acf/init', [$this, 'register_fields']);

        // Settings field under WC → Settings → General.
        add_filter('woocommerce_general_settings', [$this, 'add_settings']);

        // Cache invalidation. The stored window_start also self-invalidates the cache when the week
        // rolls over, but these explicit flushes keep it fresh mid-week.
        add_action('save_post_product', [$this, 'flush_cache']);
        foreach (['trashed_post', 'untrashed_post', 'deleted_post'] as $hook) {
            add_action($hook, [$this, 'flush_cache_for_post']);
        }
        // Category display order feeds the grouping sort.
        foreach (['created_product_cat', 'edited_product_cat', 'delete_product_cat'] as $hook) {
            add_action($hook, [$this, 'flush_cache']);
        }
        // After the daily cron strips Coming Soon from freshly-released products (priority 99 so it
        // runs after TRM_Coming_Soon::remove_expired_coming_soon at the default priority).
        if (class_exists('TRM_Coming_Soon')) {
            add_action(TRM_Coming_Soon::CRON_HOOK, [$this, 'flush_cache'], 99);
        }
    }

    /* ---------------------------------------------------------------------
     * Block registration
     * ------------------------------------------------------------------- */

    /**
     * Register the ACF block type.
     *
     * Hook: acf/init
     *
     * @return void
     */
    public function register_block()
    {
        if (!function_exists('acf_register_block_type')) {
            return;
        }

        acf_register_block_type([
            'name'            => self::BLOCK_NAME,
            'title'           => __('New Releases Carousel', 'the-realm-malta'),
            'description'     => __("Carousel of this release week's products, grouped by game system.", 'the-realm-malta'),
            'category'        => 'trm-blocks',
            'icon'            => 'star-filled',
            'keywords'        => ['new', 'releases', 'carousel', 'products', 'realm'],
            'mode'            => 'preview',
            'supports'        => ['anchor' => true, 'jsx' => true],
            'render_callback' => [$this, 'render_block'],
            'enqueue_assets'  => [$this, 'enqueue_assets'],
        ]);
    }

    /**
     * Register the block's single ACF field (Title) in PHP — no acf-json (project convention).
     *
     * Hook: acf/init
     *
     * @return void
     */
    public function register_fields()
    {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'      => 'group_trm_new_releases',
            'title'    => __('New Releases Carousel Settings', 'the-realm-malta'),
            'fields'   => [
                [
                    'key'          => 'field_trm_nr_title',
                    'label'        => __('Title', 'the-realm-malta'),
                    'name'         => 'title',
                    'type'         => 'text',
                    'instructions' => __('Optional heading shown above the carousel. Leave blank for none.', 'the-realm-malta'),
                    'placeholder'  => __('New Releases', 'the-realm-malta'),
                ],
            ],
            'location' => [
                [
                    [
                        'param'    => 'block',
                        'operator' => '==',
                        'value'    => 'acf/' . self::BLOCK_NAME,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Block render callback — prepares data and includes the render template.
     *
     * @param array  $block      The block settings/attributes (carries a unique `id`).
     * @param string $content    The block inner HTML (unused).
     * @param bool   $is_preview Whether rendering in the editor preview.
     * @param int    $post_id    The post being edited/viewed.
     * @return void
     */
    public function render_block($block, $content = '', $is_preview = false, $post_id = 0)
    {
        $title = trim((string) get_field('title'));

        $ids   = $this->get_sorted_ids();
        $max   = self::get_max_products();
        $total = count($ids);

        if ($total > $max) {
            $ids = array_slice($ids, 0, $max);
            $this->write_log(sprintf(
                'TRM New Releases: display capped from %d to %d products (option %s).',
                $total,
                $max,
                self::OPTION_MAX
            ));
        }

        // Per-instance wrapper id so multiple blocks on one page each init independently.
        $block_id = isset($block['id']) && $block['id'] !== ''
            ? 'trm-new-releases-' . sanitize_html_class($block['id'])
            : 'trm-new-releases-' . wp_generate_password(8, false, false);

        // Scope the "New!" thumbnail badge to THIS carousel's loop only — added here and removed
        // straight after the include, so no other WooCommerce product loop is affected.
        add_action('woocommerce_before_shop_loop_item_title', [$this, 'open_thumb_wrap'], 9);
        add_action('woocommerce_before_shop_loop_item_title', [$this, 'render_thumb_badge'], 11);

        // Both variables land in the template scope via include.
        include get_stylesheet_directory() . '/parts/blocks/block-new-releases-carousel.php';

        remove_action('woocommerce_before_shop_loop_item_title', [$this, 'open_thumb_wrap'], 9);
        remove_action('woocommerce_before_shop_loop_item_title', [$this, 'render_thumb_badge'], 11);
    }

    /**
     * Open a positioned wrapper around the loop item thumbnail (priority 9, before WooCommerce's
     * thumbnail + sale flash at priority 10). Gives the "New!" corner badge a bottom-right anchor
     * scoped to the thumbnail. Only registered during this block's loop render.
     *
     * Hook: woocommerce_before_shop_loop_item_title (priority 9)
     *
     * @return void
     */
    public function open_thumb_wrap()
    {
        echo '<div class="trm-nr-thumb">';
    }

    /**
     * Output the triangular "New!" badge and close the thumbnail wrapper (priority 11, after the
     * thumbnail + sale flash). Only registered during this block's loop render.
     *
     * Hook: woocommerce_before_shop_loop_item_title (priority 11)
     *
     * @return void
     */
    public function render_thumb_badge()
    {
        echo '<span class="trm-nr-badge" aria-hidden="true"><span class="trm-nr-badge__label">'
            . esc_html__('New!', 'the-realm-malta')
            . '</span></span></div>';
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

    /**
     * The sanitised display cap. Empty / zero / non-numeric / negative → default; > MAX_CAP → clamped.
     * Never trusts the stored value.
     *
     * @return int
     */
    public static function get_max_products()
    {
        $raw = get_option(self::OPTION_MAX, self::DEFAULT_MAX);

        if (!is_numeric($raw)) {
            return self::DEFAULT_MAX;
        }

        $val = (int) $raw;
        if ($val < 1) {
            return self::DEFAULT_MAX;
        }

        return min($val, self::MAX_CAP);
    }

    /* ---------------------------------------------------------------------
     * Query + sort + cache
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
    public function get_window()
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
     * The sorted, uncapped list of qualifying product IDs for the current release week.
     * Transient-cached; a stored window_start that differs from the current one is treated as a miss,
     * so the cache self-invalidates when the week rolls over.
     *
     * @return int[]
     */
    public function get_sorted_ids()
    {
        $window = $this->get_window();

        $cached = get_transient(self::TRANSIENT);
        if (
            is_array($cached)
            && isset($cached['window_start'], $cached['ids'])
            && $cached['window_start'] === $window['start']
            && is_array($cached['ids'])
        ) {
            return $cached['ids'];
        }

        $ids = $this->build_sorted_ids($window);

        set_transient(self::TRANSIENT, [
            'window_start' => $window['start'],
            'ids'          => $ids,
        ], HOUR_IN_SECONDS);

        return $ids;
    }

    /**
     * Run the query and apply the top-level-category grouping sort.
     *
     * @param array{start:string,end:string} $window
     * @return int[]
     */
    private function build_sorted_ids($window)
    {
        $meta_query = [
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
            // Self-sufficient _price presence gate (mirrors TRM_WC_Hooks; redundant-but-harmless if
            // the global pre_get_posts gate also fires on this secondary query).
            [
                'key'     => '_price',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => '_price',
                'value'   => '',
                'compare' => '!=',
            ],
        ];

        $tax_query = [
            'relation' => 'AND',
            // In stock OR on backorder, and catalogue-visible: exclude the `outofstock` and
            // `exclude-from-catalog` product_visibility terms (matched by name, as the live-search
            // endpoint does). WooCommerce only carries `outofstock` when is_in_stock() is false, and a
            // backorder-allowed product with stock <= 0 still reports in stock — so this yields exactly
            // "in stock or on backorder".
            [
                'taxonomy' => 'product_visibility',
                'field'    => 'name',
                'terms'    => ['outofstock', 'exclude-from-catalog'],
                'operator' => 'NOT IN',
            ],
        ];

        // Coming Soon veto: a delayed launch deliberately left in Coming Soon must stay hidden even
        // with an in-window release date. Skip the clause entirely when no category is configured.
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

        $query = new WP_Query([
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => self::QUERY_CEILING,
            'fields'              => 'ids',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'orderby'             => ['date' => 'DESC', 'ID' => 'DESC'],
            'meta_query'          => $meta_query,
            'tax_query'           => $tax_query,
        ]);

        $ids = array_map('intval', $query->posts);

        if (count($ids) >= self::QUERY_CEILING) {
            $this->write_log(sprintf(
                'TRM New Releases: query ceiling of %d reached — some in-window products may be omitted (likely a bad bulk import).',
                self::QUERY_CEILING
            ));
        }

        return $this->sort_by_top_level_category($ids);
    }

    /**
     * Stable-sort product IDs by their earliest top-level product_cat ancestor's display ordinal.
     *
     * The query already returns newest-first (post_date DESC, ID DESC); PHP 8's usort is stable, so
     * that order is preserved within each category group. Products with no resolvable top-level
     * category (uncategorised / only in Uncategorized) get a sentinel ordinal and land after all
     * categorised products. Each product appears once — under whichever top-level ancestor sorts
     * earliest.
     *
     * @param int[] $ids
     * @return int[]
     */
    private function sort_by_top_level_category(array $ids)
    {
        if (empty($ids)) {
            return $ids;
        }

        $ordinals = $this->get_top_level_ordinals();

        // Precompute each product's group ordinal once (not inside the comparator).
        $product_ordinal = [];
        foreach ($ids as $pid) {
            $terms = wp_get_post_terms($pid, 'product_cat', ['fields' => 'ids']);
            if (is_wp_error($terms)) {
                $terms = [];
            }

            $best = PHP_INT_MAX;
            foreach ($terms as $term_id) {
                $term_id   = (int) $term_id;
                $ancestors = get_ancestors($term_id, 'product_cat', 'taxonomy');
                // get_ancestors runs immediate-parent → root; last element is the top level. When
                // empty, the term is itself top level.
                $top = empty($ancestors) ? $term_id : (int) end($ancestors);

                if (isset($ordinals[$top]) && $ordinals[$top] < $best) {
                    $best = $ordinals[$top];
                }
            }

            $product_ordinal[$pid] = $best;
        }

        usort($ids, static function ($a, $b) use ($product_ordinal) {
            return $product_ordinal[$a] <=> $product_ordinal[$b];
        });

        return $ids;
    }

    /**
     * Map of top-level product_cat term_id => display ordinal (0-based), using the SAME ordering
     * TRM_Mega_Nav uses for its drawer tree: parent = 0 terms, ordered by the `order` term meta
     * (WooCommerce's category display order) then name, with the default "Uncategorized" skipped — so
     * the carousel and the nav can never disagree. Uncategorized is intentionally absent, so products
     * only in it fall to the sentinel bucket (rendered last).
     *
     * @return array<int,int>
     */
    private function get_top_level_ordinals()
    {
        if (!taxonomy_exists('product_cat')) {
            return [];
        }

        $default = (int) get_option('default_product_cat', 0);

        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'parent'     => 0,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $list = [];
        foreach ($terms as $term) {
            if ((int) $term->term_id === $default) {
                continue;
            }
            $list[] = [
                'id'    => (int) $term->term_id,
                'order' => (int) get_term_meta($term->term_id, 'order', true),
                'name'  => $term->name,
            ];
        }

        usort($list, static function ($a, $b) {
            if ($a['order'] !== $b['order']) {
                return $a['order'] <=> $b['order'];
            }
            return strcasecmp($a['name'], $b['name']);
        });

        $map = [];
        foreach ($list as $i => $row) {
            $map[$row['id']] = $i;
        }

        return $map;
    }

    /* ---------------------------------------------------------------------
     * Cache invalidation
     * ------------------------------------------------------------------- */

    /**
     * Delete the cached ID list.
     *
     * @return void
     */
    public function flush_cache()
    {
        delete_transient(self::TRANSIENT);
    }

    /**
     * Flush only when the affected post is a product. Hooked to trashed/untrashed/deleted_post.
     *
     * @param int $post_id
     * @return void
     */
    public function flush_cache_for_post($post_id)
    {
        if (get_post_type($post_id) === 'product') {
            $this->flush_cache();
        }
    }

    /* ---------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------- */

    /**
     * Enqueue Slick + the carousel init script wherever the block renders (frontend + editor preview).
     *
     * Slick is enqueued globally on the frontend (functions.php), so those enqueues are no-ops there;
     * ensure_slick_registered() covers the editor, where the frontend enqueue does not run. Same
     * CDN/version as the rest of the theme — no second copy.
     *
     * @return void
     */
    public function enqueue_assets()
    {
        $this->ensure_slick_registered();

        wp_enqueue_style('slick-css');
        wp_enqueue_style('slick-theme-css');
        wp_enqueue_script('slick-js');

        wp_enqueue_script(
            'trm-new-releases',
            get_stylesheet_directory_uri() . '/assets/js/trm-new-releases.js',
            ['jquery', 'slick-js'],
            time(),
            true
        );
    }

    /**
     * Register Slick's CSS/JS under the theme's existing handles if they are not already registered
     * (i.e. in the block editor preview). Reuses the exact CDN URLs + version from functions.php.
     *
     * @return void
     */
    private function ensure_slick_registered()
    {
        if (!wp_style_is('slick-css', 'registered')) {
            wp_register_style('slick-css', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.9.0/slick.min.css', [], '1.9.0');
        }
        if (!wp_style_is('slick-theme-css', 'registered')) {
            wp_register_style('slick-theme-css', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.9.0/slick-theme.min.css', [], '1.9.0');
        }
        if (!wp_script_is('slick-js', 'registered')) {
            wp_register_script('slick-js', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.9.0/slick.min.js', ['jquery'], '1.9.0', true);
        }
    }
}
