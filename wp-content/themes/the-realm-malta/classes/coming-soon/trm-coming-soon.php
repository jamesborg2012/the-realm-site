<?php

/**
 * "Coming Soon" / pre-order handling.
 *
 * One self-contained feature spanning four concerns:
 *   1. An admin setting (WC → Settings → General) selecting which product_cat is the
 *      "Coming Soon" category — stored in option `trm_coming_soon_category` (term ID).
 *   2. A Meta Box "Release Date" field (`trm_release_date`) on products (any date; past dates allowed
 *      so a mis-entered release date can be corrected after launch — item 32).
 *   3. A single-product display of the release date (italic) under the price, only for
 *      products in the Coming Soon category ("To Be Announced" when the date is unset).
 *   4. Catalog logic: products carrying the Coming Soon category are hidden from every
 *      frontend listing EXCEPT the Coming Soon archive (other category archives, main shop,
 *      search). Single product pages remain reachable by direct URL.
 *   5. A daily WP-Cron job (~00:01 local) that strips the Coming Soon category from any product
 *      whose release date has arrived.
 */
class TRM_Coming_Soon extends TRM_Core
{
    /** Option holding the Coming Soon product_cat term ID. */
    const OPTION_CATEGORY = 'trm_coming_soon_category';

    /** Product meta key holding the release date (Y-m-d, written by the Meta Box date field). */
    const META_RELEASE_DATE = 'trm_release_date';

    /** Daily cron hook that removes the Coming Soon category from released products. */
    const CRON_HOOK = 'trm_remove_coming_soon_expired';

    /**
     * WP_Query var that opts a query out of the frontend Coming Soon exclusion below. Set it on a
     * secondary query that deliberately wants Coming Soon products — e.g. the Pre-Orders carousel
     * (TRM_Pre_Orders), which exists to surface exactly the products this gate normally hides.
     */
    const BYPASS_QUERY_VAR = 'trm_bypass_coming_soon';

    public function __construct()
    {
        $this->register_hook_callbacks();
    }

    public function register_hook_callbacks()
    {
        // Admin: category picker under WC → Settings → General.
        add_filter('woocommerce_general_settings', [$this, 'add_coming_soon_setting']);

        // Admin: release date Meta Box field on products.
        add_filter('rwmb_meta_boxes', [$this, 'register_meta_box']);

        // Frontend: release date line under the price on single product pages.
        add_action('woocommerce_single_product_summary', [$this, 'render_release_date'], 11);

        // Frontend: hide Coming Soon products from every listing except the Coming Soon archive.
        add_action('pre_get_posts', [$this, 'exclude_coming_soon_from_catalog'], 99);

        // Cron: ensure the daily event is scheduled, run it, and tidy up on theme switch.
        add_action('after_setup_theme', [$this, 'maybe_schedule_cron']);
        add_action(self::CRON_HOOK, [$this, 'remove_expired_coming_soon']);
        add_action('switch_theme', [$this, 'clear_cron']);
    }

    /**
     * The configured Coming Soon product_cat term ID (0 when unset).
     *
     * @return int
     */
    public static function get_category_id()
    {
        return (int) get_option(self::OPTION_CATEGORY, 0);
    }

    /**
     * Add a "The Realm — Coming Soon" section to WC → Settings → General with a category dropdown.
     *
     * Hook: woocommerce_general_settings
     *
     * @param array $settings
     * @return array
     */
    public function add_coming_soon_setting($settings)
    {
        $options = ['' => __('— Select a category —', 'the-realm-malta')];

        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $options[$term->term_id] = $term->name;
            }
        }

        $section = [
            [
                'title' => __('The Realm — Coming Soon', 'the-realm-malta'),
                'type'  => 'title',
                'desc'  => __('Choose the product category used for pre-orders / "Coming Soon" products. Products in this category are hidden from all listings except the Coming Soon archive and show their release date on the single product page. A daily job removes this category from a product once its release date arrives.', 'the-realm-malta'),
                'id'    => 'trm_coming_soon_options',
            ],
            [
                'title'    => __('Coming Soon Category', 'the-realm-malta'),
                'desc'     => __('Leave unselected to disable Coming Soon handling.', 'the-realm-malta'),
                'id'       => self::OPTION_CATEGORY,
                'type'     => 'select',
                'options'  => $options,
                'class'    => 'wc-enhanced-select',
                'default'  => '',
                'desc_tip' => true,
            ],
            [
                'type' => 'sectionend',
                'id'   => 'trm_coming_soon_options',
            ],
        ];

        return array_merge($settings, $section);
    }

    /**
     * Register the "Release Information" Meta Box (a single date field, today onward).
     *
     * Mirrors the realm-events-manager `event_date` field convention: a jQuery UI datepicker
     * that saves a `Y-m-d` string. Past dates are selectable (no minDate) — see item 32.
     *
     * Hook: rwmb_meta_boxes
     *
     * @param array $meta_boxes
     * @return array
     */
    public function register_meta_box($meta_boxes)
    {
        $meta_boxes[] = [
            'id'         => 'trm_release_information',
            'title'      => __('Release Information', 'the-realm-malta'),
            'post_types' => ['product'],
            'context'    => 'side',
            'priority'   => 'default',
            'fields'     => [
                [
                    'name'       => __('Release Date', 'the-realm-malta'),
                    'id'         => self::META_RELEASE_DATE,
                    'type'       => 'date',
                    'desc'       => __('For "Coming Soon" pre-order products. On this date the Coming Soon category is removed automatically.', 'the-realm-malta'),
                    'js_options' => [
                        'dateFormat' => 'yy-mm-dd',
                        // No minDate: past dates are selectable so a wrongly-entered release date can be
                        // corrected after launch (item 32). Backdating a still-Coming-Soon product means
                        // the next cron run strips the category (remove_expired_coming_soon uses <= today).
                    ],
                ],
            ],
        ];

        return $meta_boxes;
    }

    /**
     * Render the release date (italic) under the price, only for Coming Soon products.
     * Shows "To Be Announced" when no date is set; renders nothing for non-Coming-Soon products.
     *
     * Hook: woocommerce_single_product_summary (priority 11 — right after the price at 10)
     *
     * @return void
     */
    public function render_release_date()
    {
        global $product;

        if (!$product instanceof WC_Product) {
            return;
        }

        $cat_id = self::get_category_id();
        if (!$cat_id) {
            return;
        }

        if (!has_term($cat_id, 'product_cat', $product->get_id())) {
            return;
        }

        $release = get_post_meta($product->get_id(), self::META_RELEASE_DATE, true);

        if (!empty($release)) {
            $ts      = strtotime($release);
            $display = $ts ? date_i18n(get_option('date_format'), $ts) : $release;
            /* translators: %s: formatted product release date */
            $text = sprintf(__('Release date: %s', 'the-realm-malta'), $display);
        } else {
            $text = __('Release date: To Be Announced', 'the-realm-malta');
        }

        echo '<p class="trm-release-date"><em>' . esc_html($text) . '</em></p>';
    }

    /**
     * Exclude Coming Soon products from every frontend product listing except the Coming Soon
     * archive (and its descendants). Single product pages, admin, AJAX and REST are untouched.
     *
     * Hook: pre_get_posts (priority 99)
     *
     * @param WP_Query $q
     * @return void
     */
    public function exclude_coming_soon_from_catalog($q)
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // Explicit opt-out for queries that deliberately want Coming Soon products (e.g. the
        // Pre-Orders carousel). Checked first so such a query is never filtered.
        if ($q->get(self::BYPASS_QUERY_VAR)) {
            return;
        }

        // Direct single product URLs must keep working.
        if (method_exists($q, 'is_singular') && $q->is_singular('product')) {
            return;
        }

        $cat_id = self::get_category_id();
        if (!$cat_id) {
            return;
        }

        $post_type = $q->get('post_type');
        $is_product_query = false;

        if ($post_type === 'product') {
            $is_product_query = true;
        } elseif (is_array($post_type) && in_array('product', $post_type, true)) {
            $is_product_query = true;
        } elseif ($q->get('wc_query') || $q->get('product_cat') || $q->get('product_tag')) {
            $is_product_query = true;
        }

        if (!$is_product_query) {
            return;
        }

        // On the Coming Soon archive itself (or a descendant), the products should show.
        if ($this->is_coming_soon_archive($q, $cat_id)) {
            return;
        }

        $tax_query = (array) $q->get('tax_query');
        $tax_query[] = [
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => [$cat_id],
            'operator'         => 'NOT IN',
            'include_children' => true,
        ];

        $q->set('tax_query', $tax_query);
    }

    /**
     * Whether the query targets the Coming Soon category archive (or a descendant of it).
     *
     * @param WP_Query $q
     * @param int $cat_id Coming Soon term ID
     * @return bool
     */
    private function is_coming_soon_archive($q, $cat_id)
    {
        // For a product_cat archive the queried term's slug lands in the product_cat query var.
        $term_slug = $q->get('product_cat');
        if (empty($term_slug) || !is_string($term_slug)) {
            return false;
        }

        $cs_term = get_term($cat_id, 'product_cat');
        if (!$cs_term || is_wp_error($cs_term)) {
            return false;
        }

        if ($term_slug === $cs_term->slug) {
            return true;
        }

        // Treat descendants of the Coming Soon category as part of it.
        $current = get_term_by('slug', $term_slug, 'product_cat');
        if ($current && !is_wp_error($current)) {
            $ancestors = array_map('intval', get_ancestors($current->term_id, 'product_cat', 'taxonomy'));
            if (in_array($cat_id, $ancestors, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ensure the daily cron event is scheduled, pinned to ~00:01 local time.
     *
     * Hook: after_setup_theme
     *
     * @return void
     */
    public function maybe_schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event($this->next_run_timestamp(), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * UTC timestamp for the next 00:01 in the site's timezone.
     *
     * DateTime::getTimestamp() returns an absolute Unix epoch regardless of the timezone used to
     * build it, which is exactly what wp_schedule_event expects.
     *
     * @return int
     */
    private function next_run_timestamp()
    {
        $tz  = wp_timezone();
        $now = new DateTime('now', $tz);
        $run = new DateTime('today 00:01', $tz);

        if ($run <= $now) {
            $run->modify('+1 day');
        }

        return $run->getTimestamp();
    }

    /**
     * Remove the Coming Soon category from every product whose release date has arrived.
     *
     * Products are found with a single direct DB query (posts + term_relationships + postmeta) for
     * minimal overhead. The release date is stored as a `Y-m-d` string, so a lexicographic string
     * comparison (`<=`) is also chronological — this avoids MySQL's `CAST(... AS DATE)`, which
     * silently yields NULL (and therefore no matches) if the stored value isn't a clean date.
     *
     * Uses `<= today` (not strictly `== today`) so a missed run — WP-Cron only fires on the first
     * request after the scheduled time, so a quiet site can skip a day — still self-heals on the
     * next run. Products with an empty/unset release date are left alone.
     *
     * The category itself is removed via `wp_remove_object_terms()` (not raw SQL) so WordPress keeps
     * term counts and object-term caches consistent.
     *
     * Hook: trm_remove_coming_soon_expired (daily cron)
     *
     * @return void
     */
    public function remove_expired_coming_soon()
    {
        global $wpdb;

        $cat_id = self::get_category_id();
        if (!$cat_id) {
            return;
        }

        $today = (new DateTime('now', wp_timezone()))->format('Y-m-d');

        // Published products that (a) carry the Coming Soon category and (b) have a non-empty release
        // date that is today or earlier. Joined on term_taxonomy_id (not term_id) for the category.
        $sql = "SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                INNER JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
                INNER JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = p.ID AND pm.meta_key = %s
                WHERE p.post_type = 'product'
                  AND p.post_status = 'publish'
                  AND tt.term_id = %d
                  AND pm.meta_value <> ''
                  AND pm.meta_value <= %s";

        $product_ids = $wpdb->get_col(
            $wpdb->prepare($sql, self::META_RELEASE_DATE, $cat_id, $today)
        );

        if (empty($product_ids)) {
            return;
        }

        foreach ($product_ids as $product_id) {
            $product_id = (int) $product_id;
            wp_remove_object_terms($product_id, [$cat_id], 'product_cat');
            $this->write_log("TRM Coming Soon: removed category {$cat_id} from product {$product_id} (release date reached).");
        }
    }

    /**
     * Unschedule the daily cron event (e.g. when switching away from this theme).
     *
     * Hook: switch_theme
     *
     * @return void
     */
    public function clear_cron()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }
}
