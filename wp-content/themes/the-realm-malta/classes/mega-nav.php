<?php

/**
 * TRM_Mega_Nav — custom category mega/off-canvas navigation.
 *
 * Replaces Storefront's primary navigation (and therefore the Max Mega Menu output, which only
 * renders into the `primary` theme location via wp_nav_menu) with an "All Categories" trigger
 * pill in the lower black header bar. Clicking it opens a left off-canvas drawer built live from
 * the WooCommerce `product_cat` hierarchy:
 *
 *   - Main categories (parent = 0) list vertically.
 *   - Clicking a main reveals its immediate sub-categories (warhammer.com-style fly-out): a
 *     second column on desktop, a slide-over panel on mobile.
 *   - Two levels only (main + sub). A "View all {category}" link in each sub-panel points at the
 *     parent's archive.
 *
 * The tree is sourced automatically from WooCommerce, so new/renamed categories appear with no
 * menu maintenance. It's cached in a transient and invalidated whenever a product category is
 * created/edited/deleted.
 */
class TRM_Mega_Nav extends TRM_Core
{
    /** Transient key for the cached category tree (suffix bumped when the tree shape changes). */
    const CACHE_KEY = 'trm_mega_nav_tree_v2';

    /** Number of category levels to render (main → child → grandchild). */
    const MAX_DEPTH = 3;

    /** Cached id of the default "Uncategorized" product category (skipped at the top level). */
    private $default_cat = 0;

    public function __construct()
    {
        // after_setup_theme @ 11: the parent (Storefront) registers storefront_primary_navigation
        // at priority 50 when its functions.php loads, which runs AFTER this child theme. Removing
        // it at construct time would miss that registration. By after_setup_theme both are loaded.
        add_action('after_setup_theme', [$this, 'register_hook_callbacks'], 11);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_drawer']);

        // Keep the cached tree fresh when categories change.
        foreach (['created_product_cat', 'edited_product_cat', 'delete_product_cat'] as $hook) {
            add_action($hook, [$this, 'flush_cache']);
        }
    }

    public function register_hook_callbacks()
    {
        // Swap Storefront's primary navigation (the Max Mega Menu host) for our trigger pill.
        remove_action('storefront_header', 'storefront_primary_navigation', 50);
        add_action('storefront_header', [$this, 'render_nav_trigger'], 50);

        // Dedicated menu location for the horizontal header menu (Home / Shop / Contact …).
        // A standalone location keeps Max Mega Menu out of it (MMM only transforms locations it's
        // configured for), so we get a plain menu regardless of MMM. Assign a menu to it under
        // Appearance → Menus → "Main Menu (header)".
        register_nav_menu('trm_main_menu', __('Main Menu (header)', 'the-realm-malta'));

        // Render that menu just after the All Categories button (50), before the search (55).
        add_action('storefront_header', [$this, 'render_main_menu'], 52);
    }

    /**
     * Render the WordPress menu assigned to the `trm_main_menu` location as a horizontal menu in
     * the lower header bar. Outputs nothing until a menu is assigned to the location.
     *
     * Hook: storefront_header (priority 52)
     *
     * @return void
     */
    public function render_main_menu()
    {
        if (!has_nav_menu('trm_main_menu')) {
            return;
        }

        wp_nav_menu([
            'theme_location'       => 'trm_main_menu',
            'container'            => 'nav',
            'container_class'      => 'trm-main-menu',
            'container_aria_label' => __('Main menu', 'the-realm-malta'),
            'menu_class'           => 'trm-main-menu__list',
            'depth'                => 2,
            'fallback_cb'          => false,
        ]);
    }

    /**
     * Enqueue the drawer behaviour script. Styles ship in the compiled layout.css (mega-nav.scss).
     *
     * @return void
     */
    public function enqueue_assets()
    {
        wp_enqueue_script(
            'trm-mega-nav',
            get_stylesheet_directory_uri() . '/assets/js/trm-mega-nav.js',
            [],
            time(),
            true
        );
    }

    /**
     * Render the "All Categories" trigger in the lower header bar.
     * Reuses the #site-navigation id so the existing header flex rules apply.
     *
     * Hook: storefront_header (priority 50)
     *
     * @return void
     */
    public function render_nav_trigger()
    {
        // Nothing to open if there are no categories.
        if (empty($this->get_category_tree())) {
            return;
        }

        // NB: deliberately NOT id="site-navigation" / class="main-navigation". Storefront's
        // navigation.js grabs #site-navigation, and if it finds a <button> but no <ul> inside it
        // sets the button to display:none — which would hide our trigger on every load.
        printf(
            '<nav class="trm-main-nav" role="navigation" aria-label="%1$s">'
                . '<button type="button" class="trm-nav-trigger" data-trm-nav-trigger aria-controls="trm-nav-drawer" aria-expanded="false">'
                . '<span class="trm-nav-trigger__icon" aria-hidden="true">%2$s</span>'
                . '<span class="trm-nav-trigger__label">%3$s</span>'
                . '</button>'
                . '</nav>',
            esc_attr__('Primary Navigation', 'the-realm-malta'),
            $this->hamburger_icon_svg(),
            esc_html__('All Categories', 'the-realm-malta')
        );
    }

    /**
     * Output the off-canvas drawer markup at the end of the page.
     *
     * Hook: wp_footer
     *
     * @return void
     */
    public function render_drawer()
    {
        $tree = $this->get_category_tree();
        if (empty($tree)) {
            return;
        }

        echo $this->render_template('nav/mega-nav', ['tree' => $tree]);
    }

    /**
     * Build a three-level product_cat tree (main → child → grandchild), cached in a transient.
     *
     * Visibility: the default "Uncategorized" term is skipped at the top level; a category is shown
     * only if it has its own products OR a descendant with products (so a parent that's only a
     * grouping container still appears as long as something beneath it is purchasable).
     * Ordering follows WooCommerce's category display order (termmeta `order`), then name.
     *
     * @return array<int,array{id:int,name:string,url:string,order:int,children:array}>
     */
    public function get_category_tree()
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        if (!taxonomy_exists('product_cat')) {
            return [];
        }

        $this->default_cat = (int) get_option('default_product_cat', 0);

        $tree = $this->build_branch(0, 1);

        set_transient(self::CACHE_KEY, $tree, 6 * HOUR_IN_SECONDS);

        return $tree;
    }

    /**
     * Recursively build one level of the category tree.
     *
     * @param int $parent_id Parent term id (0 for the top level).
     * @param int $depth     1 = main, 2 = child, 3 = grandchild.
     * @return array
     */
    private function build_branch($parent_id, $depth)
    {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'parent'     => $parent_id,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $items = [];
        foreach ($terms as $term) {
            if ($parent_id === 0 && (int) $term->term_id === $this->default_cat) {
                continue; // skip "Uncategorized" at the top level
            }

            $children = ($depth < self::MAX_DEPTH)
                ? $this->build_branch($term->term_id, $depth + 1)
                : [];

            // Hide a branch that has neither its own products nor any visible descendant.
            if ((int) $term->count < 1 && empty($children)) {
                continue;
            }

            $items[] = [
                'id'       => (int) $term->term_id,
                'name'     => $term->name,
                'url'      => $this->safe_term_link($term),
                'order'    => (int) get_term_meta($term->term_id, 'order', true),
                'children' => $children,
            ];
        }

        $this->sort_by_order_then_name($items);

        return $items;
    }

    /**
     * Delete the cached tree. Hooked to product category create/edit/delete.
     *
     * @return void
     */
    public function flush_cache()
    {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Stable-ish sort of an array of items by their `order` key, then `name` (case-insensitive).
     *
     * @param array $items passed by reference
     * @return void
     */
    private function sort_by_order_then_name(array &$items)
    {
        usort($items, function ($a, $b) {
            if ($a['order'] !== $b['order']) {
                return $a['order'] <=> $b['order'];
            }
            return strcasecmp($a['name'], $b['name']);
        });
    }

    /**
     * get_term_link that never returns a WP_Error (falls back to '#').
     *
     * @param WP_Term $term
     * @return string
     */
    private function safe_term_link($term)
    {
        $link = get_term_link($term);
        return is_wp_error($link) ? '#' : $link;
    }

    private function hamburger_icon_svg()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';
    }
}
