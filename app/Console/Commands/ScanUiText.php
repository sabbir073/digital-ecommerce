<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** Collects every t('...') string of the storefront into resources/ui-strings.php, the list admin edits from. */
class ScanUiText extends Command
{
    protected $signature = 'ui:scan {--check : Fail when the saved list is out of date, without writing it}';

    protected $description = 'Rebuild the list of storefront interface text that can be reworded in admin';

    /** Where a string is shown, decided by the file it is in. First match wins. */
    private const AREAS = [
        'resources/views/storefront/home' => 'Home page',
        'resources/views/storefront/catalog' => 'Catalog and search',
        'resources/views/storefront/product' => 'Product page',
        'resources/views/storefront/partials/product-card' => 'Product page',
        'resources/views/storefront/cart' => 'Cart',
        'resources/views/storefront/checkout' => 'Checkout',
        'resources/views/storefront/partials/checkout-extras' => 'Checkout',
        'resources/views/storefront/order' => 'Order confirmation and downloads',
        'resources/views/storefront/download-blocked' => 'Order confirmation and downloads',
        'resources/views/storefront/account' => 'Customer account',
        'resources/views/storefront/blog' => 'Blog',
        'resources/views/storefront/review' => 'Reviews',
        'resources/views/storefront/partials/review' => 'Reviews',
        'resources/views/storefront/partials/rating' => 'Reviews',
        'resources/views/storefront/partials/stars' => 'Reviews',
        'resources/views/storefront/partials/chat' => 'Live chat',
        'resources/views/storefront/error' => 'Error pages',
        'resources/views/storefront' => 'Header, footer and shared parts',
        'resources/views/selling' => 'Selling pages and content blocks',
        'resources/js/storefront' => 'Messages shown by the page scripts',
        'app' => 'Form errors and confirmations',
    ];

    public function handle(): int
    {
        $found = self::scan();
        $path = resource_path('ui-strings.php');

        if ($this->option('check')) {
            if ((is_file($path) ? require $path : []) !== $found) {
                $this->error('resources/ui-strings.php is out of date. Run: php artisan ui:scan');

                return self::FAILURE;
            }
            $this->info('The list is up to date.');

            return self::SUCCESS;
        }

        File::put($path, "<?php\n\n// Built by `php artisan ui:scan`. Do not edit by hand: change the text where it is used, then scan again.\n\nreturn ".self::export($found).";\n");
        $this->info(collect($found)->flatten()->count().' strings in '.count($found).' areas.');

        return self::SUCCESS;
    }

    /** @return array<string, list<string>> */
    public static function scan(): array
    {
        $files = collect([resource_path('views/storefront'), resource_path('views/selling'), resource_path('js/storefront'), app_path('Http/Controllers/Storefront'), app_path('Services')])
            ->flatMap(fn (string $dir) => File::allFiles($dir))
            ->map(fn ($file) => Str::after(str_replace('\\', '/', $file->getPathname()), str_replace('\\', '/', base_path()).'/'))
            ->sort()->values();

        $areas = [];
        $seen = [];
        foreach (self::AREAS as $prefix => $area) {
            foreach ($files as $file) {
                if (! str_starts_with($file, $prefix) || isset($seen['file:'.$file])) {
                    continue;
                }
                $seen['file:'.$file] = true;
                preg_match_all("/(?<![\\w>$])t\\('((?:[^'\\\\]|\\\\.)*)'/", File::get(base_path($file)), $matches);
                foreach ($matches[1] as $raw) {
                    $text = str_replace(["\\'", '\\\\'], ["'", '\\'], $raw);
                    if ($text === '' || isset($seen[$text])) {
                        continue;
                    }
                    $seen[$text] = true;
                    $areas[$area][] = $text;
                }
            }
        }

        return $areas;
    }

    /** @param array<string, list<string>> $areas */
    private static function export(array $areas): string
    {
        $out = "[\n";
        foreach ($areas as $area => $strings) {
            $out .= '    '.var_export($area, true)." => [\n";
            foreach ($strings as $string) {
                $out .= '        '.var_export($string, true).",\n";
            }
            $out .= "    ],\n";
        }

        return $out.']';
    }
}
