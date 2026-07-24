<?php

/**
 * Shared base for the theme's product-carousel ACF blocks (item 33).
 *
 * Both the "New Releases" carousel (TRM_New_Releases) and the "Pre-Orders" carousel
 * (TRM_Pre_Orders) are the same machine wearing different clothes: an ACF block with an optional
 * Title field, a self-sufficient product query, a top-level-category grouping sort, a display cap
 * backed by a WC settings field, a self-invalidating transient, and a Slick carousel of standard
 * WooCommerce `content-product` cards. This abstract class owns all of that; each subclass supplies
 * only what actually differs:
 *
 *   - identity: {@see block_title()} / {@see block_description()} / {@see block_icon()} /
 *     {@see block_keywords()}, the ACF {@see field_group_key()} / {@see field_key()}, the
 *     {@see variant_slug()} (CSS modifier + wrapper id), {@see region_label()} + {@see empty_message()},
 *     and the {@see log_label()};
 *   - the constants BLOCK_NAME / OPTION_MAX / TRANSIENT (DEFAULT_MAX / MAX_CAP / QUERY_CEILING are
 *     inherited here and only redeclared by a subclass that genuinely needs different numbers);
 *   - the window ({@see get_window()}) and the query ({@see build_query_args()});
 *   - the WC settings field ({@see add_settings()}) — one subclass adds its own section, the other
 *     splices into an existing one, so this stays abstract;
 *   - optionally, per-card loop decorations ({@see add_loop_hooks()} / {@see remove_loop_hooks()}) —
 *     e.g. the New Releases "New!" corner badge. No-ops here.
 *
 * Constants referenced through `static::` resolve to the called subclass (late static binding), so
 * the shared query/cache/cap code reads each subclass's own BLOCK_NAME / OPTION_MAX / TRANSIENT.
 */
abstract class TRM_Carousel_Block extends TRM_Core
{
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

    /** Shared front-end wrapper class; each variant adds a `--{slug}` modifier. */
    const BASE_CLASS = 'trm-carousel';

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
        add_filter('woocommerce_general_settings', [$this, 'add_settings'], $this->settings_priority());

        // Cache invalidation. The stored window_start also self-invalidates the cache when the window
        // rolls over, but these explicit flushes keep it fresh mid-window.
        add_action('save_post_product', [$this, 'flush_cache']);
        foreach (['trashed_post', 'untrashed_post', 'deleted_post'] as $hook) {
            add_action($hook, [$this, 'flush_cache_for_post']);
        }
        // Category display order feeds the grouping sort.
        foreach (['created_product_cat', 'edited_product_cat', 'delete_product_cat'] as $hook) {
            add_action($hook, [$this, 'flush_cache']);
        }
        // After the daily cron strips Coming Soon from freshly-released products (priority 99 so it
        // runs after TRM_Coming_Soon::remove_expired_coming_soon at the default priority). Relevant to
        // both carousels: New Releases gains the released products, Pre-Orders loses them.
        if (class_exists('TRM_Coming_Soon')) {
            add_action(TRM_Coming_Soon::CRON_HOOK, [$this, 'flush_cache'], 99);
        }
    }

    /* ---------------------------------------------------------------------
     * Subclass hooks — identity
     * ------------------------------------------------------------------- */

    /** ACF block title (editor). */
    abstract protected function block_title();

    /** ACF block description (editor). */
    abstract protected function block_description();

    /** Dashicon slug for the block. */
    abstract protected function block_icon();

    /** ACF block keywords. @return string[] */
    abstract protected function block_keywords();

    /** Unique ACF local field-group key. */
    abstract protected function field_group_key();

    /** Field-group title (editor). */
    abstract protected function field_group_title();

    /** Unique ACF field key for the Title field. */
    abstract protected function field_key();

    /** Placeholder shown in the Title field. */
    abstract protected function title_placeholder();

    /** Short variant slug — CSS modifier + wrapper-id prefix + loop name (e.g. `new-releases`). */
    abstract protected function variant_slug();

    /** Accessible region label used as the aria fallback when no Title is set. */
    abstract protected function region_label();

    /** Empty-state message (front end + editor). */
    abstract protected function empty_message();

    /** Prefix for write_log() lines (e.g. "TRM New Releases"). */
    abstract protected function log_label();

    /**
     * Add this block's display-cap field to WC → Settings → General. Abstract because one subclass
     * adds its own section while another splices into an existing one.
     *
     * Hook: woocommerce_general_settings
     *
     * @param array $settings
     * @return array
     */
    abstract public function add_settings($settings);

    /** Priority for the woocommerce_general_settings filter (lets a splicer run after its host). */
    protected function settings_priority()
    {
        return 10;
    }

    /* ---------------------------------------------------------------------
     * Subclass hooks — query + availability
     * ------------------------------------------------------------------- */

    /**
     * The block's date window, in the site timezone, as `Y-m-d` strings: `['start' => …, 'end' => …]`.
     * `start` is the cache key (a changed start is a cache miss, so the cache self-invalidates when
     * the window rolls over).
     *
     * @return array{start:string,end:string}
     */
    abstract protected function get_window();

    /**
     * The variable part of the WP_Query args (meta_query / tax_query / date_query / custom vars).
     * Merged onto the shared scaffold in {@see build_sorted_ids()}.
     *
     * @param array{start:string,end:string} $window
     * @return array
     */
    abstract protected function build_query_args(array $window);

    /**
     * Whether the block can produce results at all (e.g. a Pre-Orders block is inert with no Coming
     * Soon category configured). When false, {@see get_sorted_ids()} short-circuits to an empty list.
     *
     * @return bool
     */
    protected function is_available()
    {
        return true;
    }

    /* ---------------------------------------------------------------------
     * Shared query helpers (for subclass build_query_args())
     * ------------------------------------------------------------------- */

    /**
     * The `_price` presence clauses, as a pair of meta_query rows (no `relation` wrapper). Mirrors
     * TRM_WC_Hooks — self-sufficient so the query stands even if the global pre_get_posts gate does
     * not fire on this secondary query.
     *
     * @return array
     */
    protected function price_gate_clauses()
    {
        return [
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
    }

    /**
     * The catalogue-visibility clause: exclude the `outofstock` and `exclude-from-catalog`
     * product_visibility terms (matched by name, as the live-search endpoint does). WooCommerce only
     * carries `outofstock` when is_in_stock() is false, and a backorder-allowed product with stock
     * <= 0 still reports in stock — so this yields exactly "in stock or on backorder", catalogue-visible.
     *
     * @return array
     */
    protected function visibility_tax_clause()
    {
        return [
            'taxonomy' => 'product_visibility',
            'field'    => 'name',
            'terms'    => ['outofstock', 'exclude-from-catalog'],
            'operator' => 'NOT IN',
        ];
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
            'name'            => static::BLOCK_NAME,
            'title'           => $this->block_title(),
            'description'     => $this->block_description(),
            'category'        => 'trm-blocks',
            'icon'            => $this->block_icon(),
            'keywords'        => $this->block_keywords(),
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
            'key'      => $this->field_group_key(),
            'title'    => $this->field_group_title(),
            'fields'   => [
                [
                    'key'          => $this->field_key(),
                    'label'        => __('Title', 'the-realm-malta'),
                    'name'         => 'title',
                    'type'         => 'text',
                    'instructions' => __('Optional heading shown above the carousel. Leave blank for none.', 'the-realm-malta'),
                    'placeholder'  => $this->title_placeholder(),
                ],
            ],
            'location' => [
                [
                    [
                        'param'    => 'block',
                        'operator' => '==',
                        'value'    => 'acf/' . static::BLOCK_NAME,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Block render callback — prepares data and includes the shared render template.
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
        $max   = static::get_max_products();
        $total = count($ids);

        if ($total > $max) {
            $ids = array_slice($ids, 0, $max);
            $this->write_log(sprintf(
                '%s: display capped from %d to %d products (option %s).',
                $this->log_label(),
                $total,
                $max,
                static::OPTION_MAX
            ));
        }

        // Locals consumed by parts/blocks/carousel.php.
        $base_class    = static::BASE_CLASS;
        $variant       = $this->variant_slug();
        $region_label  = $this->region_label();
        $empty_message = $this->empty_message();
        $loop_name     = 'trm-' . $variant;

        // Per-instance wrapper id so multiple blocks on one page each init independently.
        $block_id = isset($block['id']) && $block['id'] !== ''
            ? $base_class . '-' . $variant . '-' . sanitize_html_class($block['id'])
            : $base_class . '-' . $variant . '-' . wp_generate_password(8, false, false);

        // Optional per-card loop decorations (e.g. the New Releases badge), scoped to this render only.
        $this->add_loop_hooks();

        include get_stylesheet_directory() . '/parts/blocks/carousel.php';

        $this->remove_loop_hooks();
    }

    /**
     * The label for the triangular bottom-right corner badge drawn over each card's thumbnail, or ''
     * for no badge. Default: no badge. A subclass returns e.g. "New!" / "Pre-Order".
     *
     * @return string
     */
    protected function badge_label()
    {
        return '';
    }

    /**
     * Register per-card loop decorations before this block's product loop. When {@see badge_label()}
     * is non-empty, wrap each loop thumbnail (prio 9 open, prio 11 badge + close) around
     * WooCommerce's own thumbnail hook so the badge anchors to the thumbnail. Scoped to this render
     * only — torn down by {@see remove_loop_hooks()} straight after the loop, so no other WooCommerce
     * product loop on the page is affected.
     *
     * @return void
     */
    protected function add_loop_hooks()
    {
        if ($this->badge_label() === '') {
            return;
        }
        add_action('woocommerce_before_shop_loop_item_title', [$this, 'open_thumb_wrap'], 9);
        add_action('woocommerce_before_shop_loop_item_title', [$this, 'render_thumb_badge'], 11);
    }

    /**
     * Remove whatever {@see add_loop_hooks()} registered.
     *
     * @return void
     */
    protected function remove_loop_hooks()
    {
        if ($this->badge_label() === '') {
            return;
        }
        remove_action('woocommerce_before_shop_loop_item_title', [$this, 'open_thumb_wrap'], 9);
        remove_action('woocommerce_before_shop_loop_item_title', [$this, 'render_thumb_badge'], 11);
    }

    /**
     * Open a positioned wrapper around the loop item thumbnail (priority 9, before WooCommerce's
     * thumbnail + sale flash at priority 10). Gives the corner badge a bottom-right anchor scoped to
     * the thumbnail. Only registered during this block's loop render.
     *
     * Hook: woocommerce_before_shop_loop_item_title (priority 9)
     *
     * @return void
     */
    public function open_thumb_wrap()
    {
        echo '<div class="trm-carousel__thumb">';
    }

    /**
     * Output the triangular corner badge and close the thumbnail wrapper (priority 11, after the
     * thumbnail + sale flash). Decorative (`aria-hidden` — the section heading already names it).
     * Only registered during this block's loop render.
     *
     * Hook: woocommerce_before_shop_loop_item_title (priority 11)
     *
     * @return void
     */
    public function render_thumb_badge()
    {
        echo '<span class="trm-carousel__badge" aria-hidden="true"><span class="trm-carousel__badge-label">'
            . esc_html($this->badge_label())
            . '</span></span></div>';
    }

    /* ---------------------------------------------------------------------
     * Settings — display cap accessor
     * ------------------------------------------------------------------- */

    /**
     * The sanitised display cap. Empty / zero / non-numeric / negative → default; > MAX_CAP → clamped.
     * Never trusts the stored value. Reads the called subclass's OPTION_MAX / DEFAULT_MAX / MAX_CAP.
     *
     * @return int
     */
    public static function get_max_products()
    {
        $raw = get_option(static::OPTION_MAX, static::DEFAULT_MAX);

        if (!is_numeric($raw)) {
            return static::DEFAULT_MAX;
        }

        $val = (int) $raw;
        if ($val < 1) {
            return static::DEFAULT_MAX;
        }

        return min($val, static::MAX_CAP);
    }

    /* ---------------------------------------------------------------------
     * Query + sort + cache
     * ------------------------------------------------------------------- */

    /**
     * The sorted, uncapped list of qualifying product IDs for the current window. Transient-cached; a
     * stored window_start that differs from the current one is treated as a miss, so the cache
     * self-invalidates when the window rolls over.
     *
     * @return int[]
     */
    public function get_sorted_ids()
    {
        if (!$this->is_available()) {
            return [];
        }

        $window = $this->get_window();

        $cached = get_transient(static::TRANSIENT);
        if (
            is_array($cached)
            && isset($cached['window_start'], $cached['ids'])
            && $cached['window_start'] === $window['start']
            && is_array($cached['ids'])
        ) {
            return $cached['ids'];
        }

        $ids = $this->build_sorted_ids($window);

        set_transient(static::TRANSIENT, [
            'window_start' => $window['start'],
            'ids'          => $ids,
        ], HOUR_IN_SECONDS);

        return $ids;
    }

    /**
     * Run the query (shared scaffold + subclass args) and apply the top-level-category grouping sort.
     *
     * @param array{start:string,end:string} $window
     * @return int[]
     */
    private function build_sorted_ids($window)
    {
        $args = array_merge(
            [
                'post_type'           => 'product',
                'post_status'         => 'publish',
                'posts_per_page'      => static::QUERY_CEILING,
                'fields'              => 'ids',
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
                'orderby'             => ['date' => 'DESC', 'ID' => 'DESC'],
            ],
            $this->build_query_args($window)
        );

        $query = new WP_Query($args);

        $ids = array_map('intval', $query->posts);

        if (count($ids) >= static::QUERY_CEILING) {
            $this->write_log(sprintf(
                '%s: query ceiling of %d reached — some in-window products may be omitted (likely a bad bulk import).',
                $this->log_label(),
                static::QUERY_CEILING
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
     * the carousels and the nav can never disagree. Uncategorized is intentionally absent, so products
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
        delete_transient(static::TRANSIENT);
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
     * Enqueue Slick + the shared carousel init script wherever a carousel block renders (frontend +
     * editor preview). One script initialises every `.trm-carousel__track`, so both carousels — and
     * multiple instances — share it (enqueuing the same handle twice is a no-op).
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
            'trm-carousel',
            get_stylesheet_directory_uri() . '/assets/js/trm-carousel.js',
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
