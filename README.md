# pleasureshop-config

Trackable export of the **DB-backed WordPress structure/content** for
dev.drblockspleasureshop.com (WooCommerce + Storefront). WordPress keeps this
data in MySQL, not files — so we export it to diff-friendly files here and
commit. This is the config/content layer, NOT a full site backup (full DB +
uploads snapshots live in /home/pleasureshop/backups/, see backup.sh).

## Workflow
```bash
sudo -u pleasureshop bash /home/pleasureshop/pleasureshop-config/export.sh
cd /home/pleasureshop/pleasureshop-config
git add -A && git diff --cached            # review what changed
git commit -m "..."                        # commit the change
```
Run `export.sh` after any admin change (menus, pages, categories, widgets,
shipping/tax, CSS, theme mods) to capture it as a reviewable commit.

## Layout
- `content/pages/*.html`     — each published Page's block markup (post_content)
- `content/pages-index.json` — page id/title/slug/status
- `content/products.json`    — catalog: id,name,sku,type,status,price,featured,categories
- `structure/menus.json`     — nav menus + items
- `structure/taxonomy-product_cat.json`, `...product_tag.json` — categories/tags
- `structure/page-templates.json` — page → _wp_page_template assignment
- `structure/shipping-zones.json`, `tax-rates.json`
- `structure/sidebars.json`, `widget-instances.json` — widgets
- `settings/theme-mods.json`, `permalinks.json`, `store-basics.json`
- `settings/payment-gateways.json` — gateway id/enabled/title ONLY
- `css/customizer.css`       — Customizer "Additional CSS" (DSB_V* blocks)
- `mu-plugins/*.php`         — must-use plugin code

## SECRETS — never committed
`export.sh` deliberately does NOT export payment-credential option rows
(`woocommerce_easyauthnet_authorizenet_settings`, `woocommerce-ppcp-data-*`,
`woocommerce_paypal_settings`). `.gitignore` excludes `backup.sh` (has the DB
password), `*.env`, and `*.sql*`. Payment API keys live outside git:
brawl `~/.paypal/` and `~/.CREDENTIALS.md`. A commit-time scan checks staged
files for known secret fragments before committing.

## Not tracked here
wp-core, plugins (rebuild from wp.org), wp-content/uploads (images), the DB
itself. Restore those from /home/pleasureshop/backups/latest.
