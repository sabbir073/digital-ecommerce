#!/usr/bin/env bash
# Builds release.zip for upload to cPanel. Run from the repository root.
set -euo pipefail

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

rm -f release.zip
zip -rq release.zip . \
    -x '.git/*' 'node_modules/*' 'tests/*' '.env' 'release.zip' \
       'storage/logs/*' 'storage/framework/cache/data/*' 'storage/framework/sessions/*' 'storage/framework/views/*' \
       'database/*.sqlite'

echo "Created release.zip"
