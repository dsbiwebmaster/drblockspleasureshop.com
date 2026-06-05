#!/bin/bash
# pleasureshop-config/export.sh
# Re-exports the DB-backed WordPress structures into diff-friendly files so the
# config/content is trackable in git. Run anytime, then `git add -A && git commit`.
#
# SECRET SAFETY: deliberately does NOT export payment-credential option rows
# (woocommerce_easyauthnet_authorizenet_settings, woocommerce-ppcp-data-*,
# woocommerce_paypal_settings). Only structure/content/non-secret settings.
REPO=/home/pleasureshop/pleasureshop-config
DOCROOT=/home/pleasureshop/domains/dev.drblockspleasureshop.com/public_html
cd "$DOCROOT" || exit 1
mkdir -p "$REPO"/{content/pages,structure,settings,css,mu-plugins}

echo "[*] content: pages (block markup)"
for id in $(wp post list --post_type=page --post_status=publish --field=ID 2>/dev/null); do
  slug=$(wp post get "$id" --field=post_name 2>/dev/null)
  wp post get "$id" --field=content 2>/dev/null > "$REPO/content/pages/${slug:-page-$id}.html"
done
wp post list --post_type=page --post_status=publish --fields=ID,post_title,post_name,post_status --format=json 2>/dev/null > "$REPO/content/pages-index.json"

echo "[*] content: products (no secrets)"
wp wc product list --user=1 --per_page=100 --fields=id,name,sku,type,status,price,regular_price,featured,categories --format=json 2>/dev/null > "$REPO/content/products.json"

echo "[*] structure: menus"
{ echo '['; first=1
  for mid in $(wp menu list --fields=term_id --format=ids 2>/dev/null); do
    [ $first -eq 0 ] && echo ','; first=0
    name=$(wp term get nav_menu "$mid" --field=name 2>/dev/null)
    echo "{\"menu_id\":$mid,\"name\":\"$name\",\"items\":"
    wp menu item list "$mid" --fields=db_id,title,type,object,object_id,url,menu_order,parent --format=json 2>/dev/null
    echo "}"
  done
  echo ']'; } > "$REPO/structure/menus.json"

echo "[*] structure: taxonomy (product_cat, product_tag)"
wp term list product_cat --fields=term_id,name,slug,parent,description,count --format=json 2>/dev/null > "$REPO/structure/taxonomy-product_cat.json"
wp term list product_tag --fields=term_id,name,slug,count --format=json 2>/dev/null > "$REPO/structure/taxonomy-product_tag.json"

echo "[*] structure: widgets + page-template assignments"
wp option get sidebars_widgets --format=json 2>/dev/null > "$REPO/structure/sidebars.json"
wp option list --search='widget_%' --format=json 2>/dev/null > "$REPO/structure/widget-instances.json"
{ echo '{'; first=1
  for id in $(wp post list --post_type=page --post_status=publish --field=ID 2>/dev/null); do
    tpl=$(wp post meta get "$id" _wp_page_template 2>/dev/null)
    [ $first -eq 0 ] && echo ','; first=0
    printf '"%s":"%s"' "$id" "${tpl:-default}"
  done
  echo '}'; } > "$REPO/structure/page-templates.json"

echo "[*] structure: shipping zones + tax (no secrets)"
{ echo '['; first=1
  for zid in 0 $(wp wc shipping_zone list --user=1 --field=id 2>/dev/null); do
    [ $first -eq 0 ] && echo ','; first=0
    echo "{\"zone_id\":$zid,\"methods\":"
    wp wc shipping_zone_method list "$zid" --user=1 --fields=instance_id,method_id,enabled,title --format=json 2>/dev/null
    echo "}"
  done; echo ']'; } > "$REPO/structure/shipping-zones.json"
wp wc tax list --user=1 --fields=id,country,state,rate,name,shipping --format=json 2>/dev/null > "$REPO/structure/tax-rates.json"

echo "[*] settings: theme-mods, permalinks, payment gateways (enabled flags ONLY)"
wp eval 'echo json_encode(get_theme_mods(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);' 2>/dev/null > "$REPO/settings/theme-mods.json"
wp option get woocommerce_permalinks --format=json 2>/dev/null > "$REPO/settings/permalinks.json"
wp wc payment_gateway list --user=1 --fields=id,enabled,title --format=json 2>/dev/null > "$REPO/settings/payment-gateways.json"
wp eval 'foreach(["woocommerce_currency","woocommerce_default_country","woocommerce_calc_taxes","woocommerce_coming_soon"] as $k){$o[$k]=get_option($k);} echo json_encode($o, JSON_PRETTY_PRINT);' 2>/dev/null > "$REPO/settings/store-basics.json"

echo "[*] css + mu-plugins"
wp eval 'echo wp_get_custom_css();' 2>/dev/null > "$REPO/css/customizer.css"
rm -f "$REPO"/mu-plugins/*.php
cp "$DOCROOT"/wp-content/mu-plugins/*.php "$REPO/mu-plugins/" 2>/dev/null

echo "[*] done"
