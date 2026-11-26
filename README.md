# Digital Ecommerce

A CMS-driven store for digital products, with a normal catalog checkout and one-page selling pages.
Built with Laravel 13, React, Tailwind CSS, Material UI (admin) and MySQL.

The product requirements and the approved build plan live in the project documents. The build runs in 11 phases; this repository currently contains Phases 1 to 7.

## What works today (Phases 1 to 7)

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

- Cart and checkout: cart drawer and cart page, coupons, guest checkout with optional account, Stripe's embedded Payment Element (cards, Apple Pay, Google Pay, 3D Secure), tax from Stripe Tax, stock held while a payment is in progress.
- Orders: an order becomes Paid only when Stripe's signed webhook arrives. Then stock is reduced, download links are created (limited by count and days) and the confirmation email is sent. Refund and dispute events from Stripe update the order.
- Customer accounts: sign up, sign in, password reset, order history with downloads.
- Admin: orders list and detail, coupons, and Settings > Payments (Stripe keys for Super Admins only, stored encrypted; Stripe Tax and overselling switches).

- Selling pages at `/p/<slug>`: one product on one address with no store navigation, built from twelve block types (hero, benefits, image and text, gallery, video, reviews, FAQ, offer bar with countdown, table, rich text, trust row, order block). The customer picks a pack and pays on the page; the order block is replaced by the confirmation and download links.
- Packs: one to four per page. A pack is a bundle of products and variants at one fixed price and shows "Sold out" when any item in it is out of stock.
- Admin > Selling pages: create from the starter template or blank, edit packs and block content, preview the draft, publish (blocked until there is exactly one order block and no `[PLACEHOLDER]` text), unpublish, delete. Views, orders and conversion rate per page.
- Page builder (Admin > Selling pages > Open builder): block library on the left, a live canvas drawn with the real storefront templates in the middle, block settings on the right. Drag blocks in from the library, drag them by the handle to reorder, or use the Move, Duplicate, Hide and Delete buttons. Click text to edit it in place and click an image to replace it. Desktop, tablet and mobile widths, undo and redo (100 steps), autosave one second after the last edit, publish, restore any of the last ten published versions, and duplicate a page. The builder's code is a separate bundle that public pages never load.
- Reviews: product reviews on every product page and store reviews at `/reviews`, with a rating summary, star bars that filter, sort, filters by photos and country, and 10 per page. Country flags are bundled SVG images in `public/flags` (from the MIT-licensed flag-icons set), not emoji.
- Writing a review: rating, title, text (20 characters or more), up to 5 photos, name, country and email. A review linked to a paid order shows "Verified buyer" and takes its country from the order. Visitors can mark a review helpful once and report it.
- Review request email: sent a set number of days after payment by `reviews:send-requests` (scheduled hourly), with a link per item that needs no sign-in.
- Admin > Reviews: queue with status tabs, search and filters, bulk approve, reject and delete, public reply, pin, country correction, photo removal and reports. Genuine reviews collected elsewhere can be added by hand or imported by CSV and always show a source label. There is no feature that writes review text.
- Admin > Reviews > Settings: who may review, auto-approval of verified buyers, holding reviews with links or blocked words, the request email and its delay, flags on or off, the home page section, and an optional Cloudflare Turnstile captcha.
- Live chat: a chat button on the store (and on selling pages if switched on) where visitors write to the team. It needs no outside service: the widget and the inbox ask the server for new messages every 3 seconds, so it works on ordinary cPanel hosting. The chat belongs to the visitor's browser through a secret cookie, of which only a hash is stored.
- Admin > Live chat: inbox with open and closed conversations, unread counts, replying, take over, close, reopen and delete. Visitors see the team as online while someone has the inbox open. When nobody is there, the visitor is asked for an email and the store's notification address gets an email; a reply written after the visitor has left is also sent to their email.

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
php artisan demo:seed       # optional: a labelled sample catalog and one sample selling page at /p/demo-pitch-deck (refused on production; undo with demo:clear)
npm run dev                 # or: npm run build
php artisan serve
```

Open `http://localhost:8000` for the storefront and `http://localhost:8000/admin` for the admin panel.

## Payments (Stripe)

1. In the Stripe dashboard (test mode) copy the publishable and secret keys into Admin > Settings > Payments. They can also be set in `.env` (`STRIPE_TEST_KEY`, `STRIPE_TEST_SECRET`, `STRIPE_TEST_WEBHOOK_SECRET`).
2. Orders are marked Paid by the webhook, so Stripe must be able to reach `/stripe/webhook`. On a local machine use the Stripe CLI and paste the `whsec_...` value it prints into the webhook secret field:
   ```sh
   stripe listen --forward-to http://localhost:8000/stripe/webhook
   ```
   On a live site add an endpoint in Stripe for `https://your-domain/stripe/webhook` with the events `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`, `refund.created`, `refund.updated`, `charge.dispute.created`, `charge.dispute.updated` and `charge.dispute.closed`.
3. Tax uses Stripe Tax. Switch it on in your Stripe account and add your tax registrations there, or switch it off in Admin > Settings > Payments.
4. Emails and expiry of unpaid orders run from the scheduler and queue. Locally run `php artisan schedule:work` (it also drains the queue), or set `QUEUE_CONNECTION=sync` to send emails straight away.

Test card: `4242 4242 4242 4242` succeeds, `4000 0000 0000 0002` is declined, `4000 0027 6000 3184` asks for 3D Secure.

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
