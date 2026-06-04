# Dr. Block's Pleasure Shop — site config

WordPress + WooCommerce site at https://dev.drblockspleasureshop.com (dev) → eventually drblockspleasureshop.com (prod).

This repo tracks the **code + content config**, not the full WP install. The site lives at:
`/home/pleasureshop/domains/dev.drblockspleasureshop.com/public_html` on mjhst.

## Tracked
- `mu-plugins/` — site-specific WP code (dsb-tweaks.php etc)
- `css/customizer.css` — Storefront Customizer additional CSS
- `meta/` — JSON exports of homepage, menu, theme mods, products, WC permalinks

## Backups (NOT in git)
Daily mysqldumps + code snapshots at `/home/pleasureshop/backups/full-YYYYMMDD-HHMMSS/`.
Cron entry in `/var/spool/cron/pleasureshop`.

## Refresh tracked files
`/home/pleasureshop/pleasureshop-config/refresh.sh` re-exports from live WP.

## Restore
- DB: `gunzip < <snapshot>/db/*.sql.gz | mysql -u user -p db`
- Code: `cp -r <snapshot>/code/mu-plugins/* wp-content/mu-plugins/`
- Content: `wp post update 75 --post_content="$(cat meta/homepage-75.html)"`
