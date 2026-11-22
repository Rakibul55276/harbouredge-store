# HarbourEdge Store

Online store for **HarbourEdge Company Limited**: PPE and safety equipment, B2B and B2C, Arabic + English, SAR with 15% KSA VAT.
Built on [Bagisto](https://bagisto.com) 2.4 (Laravel 12) with the Bagisto **B2B Suite** (company accounts, request for quote, company credit, purchase orders, quick order, requisition lists).

## What is set up

- **Languages:** English and Arabic (full right-to-left layout)
- **Currency and tax:** SAR only, prices shown **including 15% VAT**, tax rate `SA-VAT-15`
- **Catalogue:** 15 PPE categories, 52 products (28 with HarbourEdge photos, 24 sample products with icon images)
- **Homepage:** banner slider (EN/AR), offer strip, category carousel, Best Sellers / Footwear / FR Workwear / New Arrivals, service highlights
- **Discount badges:** products with a special price show `-12%` style badges
- **Request Quote:** button on every product page (adds to cart, opens cart). Logged-in company customers request the quote from the cart (B2B Suite).
- **Branding:** HarbourEdge logo, navy `#0F1B2D` + amber `#FFAE0B`

> Prices are **placeholders**. Update them in Admin → Catalog → Products, or in `database/data/harbouredge/build_catalog.py`.

## Database

The store uses its **own database** (`harbouredge_store`). Do **not** point it at the `manpower` ERP database.

- Never run `php artisan bagisto:install` on a server with real data: it runs `db:wipe` and deletes every table in the database.
- Use `php artisan harbouredge:install` instead. It never wipes anything, and it refuses to run if the database already contains another system's tables.

## Install (local or server)

Requirements: PHP 8.3 (with `bcmath`, `intl`, `gd`, `zip`, `pdo_mysql`), Composer 2, MySQL 8 / MariaDB 10.6+.

```bash
git clone https://github.com/Rakibul55276/harbouredge-store.git
cd harbouredge-store
composer install
cp .env.example .env              # then edit APP_URL, DB_USERNAME, DB_PASSWORD
# create an empty database called harbouredge_store first
php artisan harbouredge:install --admin-email=you@harboureg.com
php artisan serve                 # http://localhost:8000  (admin: /admin)
```

The installer prints the admin password once, so save it.

## Changing the catalogue

```bash
python3 database/data/harbouredge/build_catalog.py   # edit the product lists in this file first
python3 database/data/harbouredge/build_banners.py   # regenerate homepage banners (needs Pillow + fonts-noto-core)
php artisan db:seed --class="Database\Seeders\HarbourEdgeSeeder"
php artisan indexer:index
```

The seeder is safe to re-run: it adds missing products and rebuilds the homepage, and it does not touch products that already exist.

## Where the customisations live

| What | File |
|---|---|
| Store setup (locales, VAT, categories, products, homepage) | `database/seeders/HarbourEdgeSeeder.php` |
| Safe installer | `app/Console/Commands/HarbourEdgeInstall.php` |
| Catalogue data, photos, icons, banners, logos | `database/data/harbouredge/` |
| Request Quote button | `resources/views/harbouredge/request-quote.blade.php` (registered in `app/Providers/AppServiceProvider.php`) |
| Discount % badge | `resources/views/vendor/shop/components/products/card.blade.php`, `resources/views/vendor/shop/products/prices/index.blade.php` |
| Header text | `resources/views/vendor/shop/components/layouts/header/desktop/top.blade.php` |
| EN/AR text overrides | `lang/vendor/shop/{en,ar}/app.php` |
| B2B Suite loading (only after install) | `bootstrap/providers.php` |

Bagisto's original README is kept in `README.bagisto.md`.

## Before going live

- Set `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL`, and a strong admin password
- Replace placeholder prices and the 24 sample products
- Connect a Saudi payment gateway (Moyasar / HyperPay / Tap) and ZATCA e-invoicing
- Add real About, Shipping, Returns, Privacy and Terms pages (Admin → CMS)
