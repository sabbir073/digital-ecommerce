# Deploying on cPanel

The host needs PHP 8.3 or newer (with `pdo_mysql`, `gd` or `imagick`, `intl`, `zip`, `mbstring`), a MySQL database,
SSH access and cron jobs. Node.js is **not** needed on the host: the front-end is built before upload.

## 1. Build a release on your computer (or in CI)

```sh
./scripts/build-release.sh
```

This runs the production Composer and npm builds and writes `release.zip`, which contains the application,
`vendor/` and the compiled assets in `public/build/`.

## 2. Upload

1. Upload and extract `release.zip` into a folder **outside** `public_html`, for example `~/digital-ecommerce`.
2. Point the domain's document root at `~/digital-ecommerce/public` (cPanel > Domains > Document Root).
   If the document root cannot be changed, replace `public_html` with a symlink:
   `ln -s ~/digital-ecommerce/public ~/public_html`.

## 3. Configure

```sh
cd ~/digital-ecommerce
cp .env.example .env
php artisan key:generate
```

Edit `.env`: set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, the `DB_*` values from cPanel > MySQL Databases,
and the `MAIL_*` values of your mailbox or email service.

```sh
php artisan migrate --force --seed
php artisan admin:create-super
php artisan storage:link
php artisan optimize
```

## 4. Cron job

Add one cron job (cPanel > Cron Jobs), every minute. It runs scheduled tasks and the background queue,
so no always-on worker process is needed:

```
* * * * * cd ~/digital-ecommerce && php artisan schedule:run >> /dev/null 2>&1
```

## 5. Updating

Upload the new `release.zip`, extract it over the old files (keep `.env` and `storage/`), then run:

```sh
php artisan migrate --force
php artisan optimize
```
