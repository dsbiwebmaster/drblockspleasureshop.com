<?php
/**
 * Plugin Name: DSB Tweaks v3
 * Description: Storefront overrides — defers all WC remove_action to init:99.
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
