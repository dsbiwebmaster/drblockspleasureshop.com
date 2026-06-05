<?php
/**
 * Plugin Name: DSB Tweaks v4
 * Description: Storefront overrides — footer credit, loop title h3, header account+search icons.
 */

add_action('init', function () {
    // Remove Storefront credit
    remove_action('storefront_footer', 'storefront_credit', 20);
    // Swap WC product-loop title from <h2> to <h3>
    remove_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10);
    add_action('woocommerce_shop_loop_item_title', function () {
        echo '<h3 class="woocommerce-loop-product__title">' . esc_html(get_the_title()) . '</h3>';
    }, 10);
}, 99);

add_action('storefront_footer', function () {
    echo '<div class="dsb-footer-copy" style="text-align:center;padding:1em;font-size:0.85em;color:#666;">Copyright &copy; 2026 Dr. Block\'s Pleasure Shop - All Rights Reserved. <a href="/shop/" style="margin-left:1em;">Store</a></div>';
}, 25);

/**
 * Header account + search icons, matching the live GoDaddy header
 * (which shows search, account, and cart icons top-right).
 * Cart is injected by Storefront at priority 60; we add ours at 55.
 */
add_action('storefront_header', function () {
    if (!function_exists('wc_get_page_permalink')) {
        return;
    }
    $account_url = wc_get_page_permalink('myaccount');
    ?>
    <div class="dsb-header-icons">
        <button type="button" class="dsb-icon dsb-search-toggle" aria-label="Search" aria-expanded="false"></button>
        <a class="dsb-icon dsb-account" href="<?php echo esc_url($account_url); ?>" aria-label="My Account"></a>
    </div>
    <div class="dsb-search-panel" hidden>
        <?php echo get_product_search_form(false); ?>
    </div>
    <script>
    (function () {
        var toggle = document.querySelector('.dsb-search-toggle');
        var panel  = document.querySelector('.dsb-search-panel');
        if (!toggle || !panel) { return; }
        toggle.addEventListener('click', function () {
            var willOpen = panel.hasAttribute('hidden');
            if (willOpen) { panel.removeAttribute('hidden'); } else { panel.setAttribute('hidden', ''); }
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) {
                var input = panel.querySelector('input[type="search"], input[name="s"]');
                if (input) { input.focus(); }
            }
        });
    })();
    </script>
    <?php
}, 55);
