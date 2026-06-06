<?php
/**
 * Plugin Name: DSB Tweaks v6
 * Description: Storefront overrides — footer credit + policy links, loop title h3, header account+search icons, product social share, cookie consent banner.
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
    echo '<div class="dsb-footer-copy" style="text-align:center;padding:1em;font-size:0.85em;color:#666;">'
       . 'Copyright &copy; 2026 Dr. Block\'s Pleasure Shop - All Rights Reserved. '
       . '<a href="/shop/">Store</a> &nbsp;|&nbsp; '
       . '<a href="/privacy-policy/">Privacy</a> &nbsp;|&nbsp; '
       . '<a href="/terms/">Terms</a> &nbsp;|&nbsp; '
       . '<a href="/refund_returns/">Refunds</a>'
       . '</div>';
}, 25);

/**
 * Header account + search icons, matching the live GoDaddy header.
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

/**
 * Social share (Facebook + X) on product detail pages.
 */
add_action('woocommerce_single_product_summary', function () {
    if (!is_product()) {
        return;
    }
    $url   = rawurlencode(get_permalink());
    $title = rawurlencode(get_the_title());
    $fb = 'https://www.facebook.com/sharer/sharer.php?u=' . $url;
    $x  = 'https://twitter.com/intent/tweet?url=' . $url . '&text=' . $title;
    echo '<div class="dsb-share">'
       . '<span class="dsb-share-label">Share:</span>'
       . '<a class="dsb-share-btn dsb-share-fb" href="' . esc_url($fb) . '" target="_blank" rel="noopener noreferrer" aria-label="Share on Facebook">'
       . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5 3.66 9.15 8.44 9.94v-7.03H7.9v-2.9h2.54V9.85c0-2.51 1.49-3.9 3.78-3.9 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.88h2.78l-.44 2.9h-2.34V22c4.78-.79 8.44-4.94 8.44-9.94z"/></svg></a>'
       . '<a class="dsb-share-btn dsb-share-x" href="' . esc_url($x) . '" target="_blank" rel="noopener noreferrer" aria-label="Share on X">'
       . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.9 1.8h3.3l-7.2 8.22L23.64 22h-6.6l-5.17-6.76L5.92 22H2.62l7.7-8.79L2.36 1.8h6.77l4.67 6.18zM17.74 20.03h1.83L8.34 3.67H6.38z"/></svg></a>'
       . '</div>';
}, 45);

/**
 * Cookie consent banner (matches the live GoDaddy site).
 */
add_action('wp_footer', function () {
    ?>
    <div class="dsb-cookie" id="dsbCookie" hidden>
        <div class="dsb-cookie-inner">
            <div class="dsb-cookie-text">
                <strong>This website uses cookies.</strong>
                We use cookies to analyze website traffic and optimize your website experience. By accepting our use of cookies, your data will be aggregated with all other user data.
                <a href="/privacy-policy/">Learn more</a>
            </div>
            <button type="button" class="dsb-cookie-accept" id="dsbCookieAccept">Accept</button>
        </div>
    </div>
    <script>
    (function () {
        var el = document.getElementById('dsbCookie');
        if (!el) { return; }
        var consented = document.cookie.split('; ').some(function (c) { return c.indexOf('dsb_cookie_consent=') === 0; });
        if (!consented) { el.removeAttribute('hidden'); }
        var btn = document.getElementById('dsbCookieAccept');
        if (btn) {
            btn.addEventListener('click', function () {
                document.cookie = 'dsb_cookie_consent=1; path=/; max-age=' + (60 * 60 * 24 * 365) + '; samesite=lax';
                el.setAttribute('hidden', '');
            });
        }
    })();
    </script>
    <?php
});
