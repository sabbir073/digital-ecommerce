# Digital Ecommerce

A CMS-driven store for digital products, with a normal catalog checkout and one-page selling pages.
Built with Laravel 13, React, Tailwind CSS, Material UI (admin) and MySQL.

The product requirements and the approved build plan live in the project documents. The build runs in 11 phases; this repository currently contains **Phase 1: Foundation**.

## What works today (Phases 1 and 2)

- Design tokens from PRD section 8, shared by the storefront (Tailwind) and the admin panel (Material UI theme).
- Shared storefront header and footer, with the announcement bar and category navigation read from the database.
- The full database schema (about 60 tables) for every later phase.
- Admin sign-in at `/admin/login`: rate limited, optional two-factor (authenticator app plus recovery codes), forced sign-out.
- Staff: invite by email, change role, suspend, reactivate, sign out everywhere, remove.
- Roles: four built-in roles (Super Admin, Admin, Moderator, Staff) and custom roles with a module-by-action matrix. Every request is checked on the server.
- Password policy set in admin.
- Activity log: append-only, searchable, filterable by person and area.
- Catalog admin: products with up to two options and per-variant price, SKU, stock and image; digital files on a private disk; two-level categories with drag reorder; media library with folders and automatic WebP resizing; CSV import and export.
- Storefront: home page built from reorderable sections, all-products, category and search pages with filters, sort and pagination, and the product page with gallery, variant pickers and quantity stepper.

## Local setup

Requirements: PHP 8.3+, Composer, Node 22+, MySQL 8 (or MariaDB 10.6+).

```sh
composer install
npm install
cp .env.example .env        # then set the DB_* values
php artisan key:generate
php artisan migrate --seed  # creates the tables and the built-in roles
php artisan admin:create-super
php artisan storage:link    # makes uploaded images reachable
php artisan demo:seed       # optional: a labelled sample catalog (refused on production; undo with demo:clear)
npm run dev                 # or: npm run build
php artisan serve
```

Open `http://localhost:8000` for the storefront and `http://localhost:8000/admin` for the admin panel.

## Tests

```sh
php artisan test     # PHP tests (roles, sign-in, activity log, tokens, storefront shell)
npm run typecheck    # TypeScript
```

## Layout

| Path | What it holds |
| --- | --- |
| `config/permissions.php` | Modules, actions and the built-in roles |
| `app/Http/Controllers/Admin` | Admin panel controllers (Inertia) |
| `app/Http/Controllers/Storefront` | Public pages (Blade) |
| `app/Services` | Business rules shared across controllers |
| `resources/css/app.css` | Design tokens and storefront components |
| `resources/js/admin` | Admin React app (Material UI) |
| `resources/js/storefront` | Small scripts for public pages |
| `resources/views/storefront` | Storefront Blade templates |
| `docs/DEPLOY-CPANEL.md` | How to deploy on cPanel hosting |
