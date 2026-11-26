<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\DigitalAsset;
use App\Models\Media;
use App\Models\Product;
use App\Models\Setting;
use App\Services\MediaService;
use App\Services\ProductWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fills a non-production install with a clearly labelled sample catalog so the
 * storefront can be looked at before the owner adds real products.
 * Every demo product carries the "Demo" tag and a DEMO- SKU; `demo:clear` removes them.
 */
class SeedDemoCatalog extends Command
{
    protected $signature = 'demo:seed {--no-images : Skip downloading the stock photos}';

    protected $description = 'Add a labelled sample catalog (never on production)';

    private const IMAGE_BASE = 'https://img.magnific.com/free-photo/';

    public function handle(MediaService $media, ProductWriter $writer): int
    {
        if (app()->environment('production')) {
            $this->error('Demo data is not allowed on a production install.');

            return self::FAILURE;
        }
        if (Product::query()->where('sku', 'like', 'DEMO-%')->exists()) {
            $this->warn('The demo catalog is already installed. Run "php artisan demo:clear" first to rebuild it.');

            return self::SUCCESS;
        }

        $images = [];
        foreach ($this->catalog() as $categoryName => $entry) {
            $cover = $this->image($media, $images, $entry['image'], $categoryName);
            $category = Category::query()->firstOrCreate(
                ['slug' => str($categoryName)->slug()->toString()],
                ['name' => $categoryName, 'position' => Category::query()->count(), 'media_id' => $cover?->id],
            );

            foreach ($entry['products'] as $index => $row) {
                $imageIds = collect($row['images'])
                    ->map(fn (string $file) => $this->image($media, $images, $file, $row['name'])?->id)
                    ->filter()->values()->all();

                $variants = [];
                foreach ($row['variants'] ?? [] as $v => $variant) {
                    $variants[] = $variant + ['sku' => 'DEMO-'.strtoupper(str($row['name'])->slug()->limit(12, '')).'-'.($v + 1), 'media_id' => $imageIds[$v % max(1, count($imageIds))] ?? null];
                }

                $product = $writer->save(new Product, [
                    'name' => $row['name'],
                    'slug' => $writer->uniqueSlug($row['name']),
                    'status' => 'active',
                    'category_id' => $category->id,
                    'price' => $row['price'],
                    'compare_at' => $row['compare_at'] ?? null,
                    'sku' => 'DEMO-'.strtoupper(str($row['name'])->slug()->limit(16, '')),
                    'short_description' => $row['short'],
                    'description' => $row['description'],
                    'track_stock' => $row['track_stock'] ?? false,
                    'stock' => $row['stock'] ?? 0,
                    'tags' => ['Demo', ...($row['tags'] ?? [])],
                    'image_ids' => $imageIds,
                    'options' => $row['options'] ?? [],
                    'variants' => $variants,
                ]);
                $product->forceFill(['sales_count' => $row['sales'] ?? 0])->save();

                $path = "demo/{$product->slug}/sample.txt";
                Storage::disk('downloads')->put($path, "Sample download for the demo product \"{$product->name}\".\nReplace it with the real file in Admin > Products.\n");
                DigitalAsset::create([
                    'product_id' => $product->id,
                    'disk' => 'downloads',
                    'path' => $path,
                    'filename' => $product->slug.'-sample.txt',
                    'size_bytes' => Storage::disk('downloads')->size($path),
                    'version' => '1.0',
                ]);
                $this->line("  added {$product->name}");
            }
        }

        if (Setting::get('announcement') === null) {
            Setting::put('announcement', ['enabled' => true, 'text' => 'Demo store: these are sample products and cannot be bought.', 'demo' => true]);
        }

        $missing = count(array_filter($images, fn ($m) => $m === null));
        $this->info('Demo catalog added.'.($missing ? " {$missing} stock photos could not be downloaded; add images in Admin > Media library." : ''));

        return self::SUCCESS;
    }

    /**
     * Download one stock photo into the media library (once per file).
     *
     * @param  array<string, ?Media>  $cache
     */
    private function image(MediaService $media, array &$cache, string $file, string $alt): ?Media
    {
        if ($this->option('no-images')) {
            return null;
        }
        if (array_key_exists($file, $cache)) {
            return $cache[$file];
        }

        $temp = tempnam(sys_get_temp_dir(), 'demo');
        try {
            $response = Http::timeout(30)->retry(2, 500)->get(self::IMAGE_BASE.$file, ['w' => 1480]);
            if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                throw new \RuntimeException('HTTP '.$response->status());
            }
            file_put_contents($temp, $response->body());
            $mime = (string) (getimagesize($temp)['mime'] ?? 'image/jpeg');

            return $cache[$file] = $media->storeFromPath($temp, $file, $mime, null, $alt);
        } catch (Throwable $e) {
            $this->warn("  could not download {$file}: {$e->getMessage()}");

            return $cache[$file] = null;
        } finally {
            @unlink($temp);
        }
    }

    /** @return array<string, array{image:string, products:list<array<string, mixed>>}> */
    private function catalog(): array
    {
        $licence = ['name' => 'Licence', 'display' => 'pill', 'values' => [['value' => 'Personal'], ['value' => 'Commercial']]];

        return [
            'Templates' => [
                'image' => 'workspace-with-computer-screen-lamp_23-2148821899.jpg',
                'products' => [
                    [
                        'name' => 'Studio Website Template', 'price' => '49', 'sales' => 310, 'tags' => ['Website'],
                        'short' => 'A clean, fast one-page website template for studios and freelancers.',
                        'description' => "Responsive layout with a hero, services, work and contact sections.\nPlain HTML and CSS, no build step.\nIncludes a short setup guide.",
                        'images' => ['workspace-with-computer-screen-lamp_23-2148821899.jpg', 'front-view-office-desk-with-computer-screen-lamp_23-2148821871.jpg', 'arrangement-desk-elements-with-empty-laptop-screen_23-2148513257.jpg'],
                        'options' => [$licence],
                        'variants' => [
                            ['values' => ['Personal'], 'price' => '49', 'compare_at' => '69'],
                            ['values' => ['Commercial'], 'price' => '129'],
                        ],
                    ],
                    [
                        'name' => 'Pitch Deck Template', 'price' => '29', 'compare_at' => '39', 'sales' => 205, 'tags' => ['Presentation'],
                        'short' => 'Thirty editable slides for a clear investor or client pitch.',
                        'description' => "Thirty slide layouts in 16:9.\nWorks in Keynote, PowerPoint and Google Slides.\nFree fonts only.",
                        'images' => ['arrangement-desk-elements-with-empty-laptop-screen_23-2148513257.jpg', 'front-view-laptop-wooden-table_23-2148459681.jpg'],
                    ],
                    [
                        'name' => 'Invoice and Proposal Kit', 'price' => '19', 'sales' => 122, 'tags' => ['Business'],
                        'short' => 'Invoice, quote and proposal documents that match.',
                        'description' => "Three matching documents in A4 and US Letter.\nEditable in Word, Pages and Google Docs.",
                        'images' => ['front-view-laptop-wooden-table_23-2148459681.jpg', 'mockup-copy-space-blank-screen-concept_53876-120458.jpg'],
                    ],
                ],
            ],
            'E-books' => [
                'image' => 'workplace-with-tablet-stationary_23-2147833219.jpg',
                'products' => [
                    [
                        'name' => 'The Freelance Starter Guide', 'price' => '24', 'sales' => 268, 'tags' => ['Guide'],
                        'short' => 'A practical guide to finding clients, pricing and getting paid.',
                        'description' => "140 pages in PDF and EPUB.\nIncludes contract and email examples.",
                        'images' => ['workplace-with-tablet-stationary_23-2147833219.jpg', 'table-with-tablet-near-photo-frame-pen-eyeglasses_23-2148040489.jpg'],
                        'options' => [['name' => 'Format', 'display' => 'pill', 'values' => [['value' => 'PDF'], ['value' => 'PDF + EPUB']]]],
                        'variants' => [
                            ['values' => ['PDF'], 'price' => '24'],
                            ['values' => ['PDF + EPUB'], 'price' => '29'],
                        ],
                    ],
                    [
                        'name' => 'Notion for Small Teams', 'price' => '18', 'sales' => 97, 'tags' => ['Guide', 'Productivity'],
                        'short' => 'Set up a simple team workspace in an afternoon.',
                        'description' => "80 pages in PDF.\nStep-by-step setup with screenshots.",
                        'images' => ['table-with-tablet-near-photo-frame-pen-eyeglasses_23-2148040489.jpg', 'close-up-glasses-near-tablet_23-2147777805.jpg'],
                    ],
                    [
                        'name' => 'Everyday Photo Editing', 'price' => '15', 'compare_at' => '22', 'sales' => 64, 'tags' => ['Photography'],
                        'short' => 'Learn a repeatable edit for portraits, travel and food photos.',
                        'description' => "60 pages in PDF.\nTwelve worked examples with before and after images.",
                        'images' => ['open-book-smartphone_23-2148186933.jpg', 'close-up-glasses-near-tablet_23-2147777805.jpg'],
                    ],
                ],
            ],
            'Presets' => [
                'image' => 'composition-with-camera-plant_23-2147982597.jpg',
                'products' => [
                    [
                        'name' => 'Golden Hour Preset Pack', 'price' => '22', 'sales' => 341, 'tags' => ['Photography', 'Lightroom'], 'track_stock' => true,
                        'short' => 'Twelve one-click looks for warm, soft evening light.',
                        'description' => "Twelve presets for desktop and mobile.\nWorks with RAW and JPEG files.\nInstall guide included.",
                        'images' => ['composition-with-camera-plant_23-2147982597.jpg', 'top-view-camera-with-negatives-clock-plants_23-2148019264.jpg', 'elevated-view-dslr-camera-with-lens-extension-rings-marble-background_23-2148038876.jpg'],
                        'options' => [
                            ['name' => 'Tone', 'display' => 'swatch', 'values' => [['value' => 'Warm', 'swatch_hex' => '#C98B5B'], ['value' => 'Cool', 'swatch_hex' => '#5B8BC9'], ['value' => 'Mono', 'swatch_hex' => '#2B2B2B']]],
                            ['name' => 'Size', 'display' => 'pill', 'values' => [['value' => '12 presets'], ['value' => '30 presets']]],
                        ],
                        'variants' => [
                            ['values' => ['Warm', '12 presets'], 'price' => '22', 'compare_at' => '29', 'stock' => 40],
                            ['values' => ['Warm', '30 presets'], 'price' => '39', 'stock' => 25],
                            ['values' => ['Cool', '12 presets'], 'price' => '22', 'stock' => 4],
                            ['values' => ['Cool', '30 presets'], 'price' => '39', 'stock' => 0],
                            ['values' => ['Mono', '12 presets'], 'price' => '19', 'stock' => 18],
                            ['values' => ['Mono', '30 presets'], 'price' => '35', 'stock' => 12],
                        ],
                    ],
                    [
                        'name' => 'Film Grain Overlays', 'price' => '12', 'sales' => 150, 'tags' => ['Photography'],
                        'short' => 'Twenty scanned grain textures for photos and video.',
                        'description' => "Twenty 6K textures in JPEG.\nUse in any editor that supports blend modes.",
                        'images' => ['close-up-camera-near-photographic-film_23-2147852472.jpg', 'top-view-camera-with-negatives-clock-plants_23-2148019264.jpg'],
                    ],
                    [
                        'name' => 'Travel Colour LUTs', 'price' => '27', 'sales' => 88, 'tags' => ['Video'], 'track_stock' => true, 'stock' => 0,
                        'short' => 'Ten colour looks for travel footage.',
                        'description' => "Ten LUTs in .cube format.\nWorks in Premiere Pro, Final Cut and DaVinci Resolve.",
                        'images' => ['elevated-view-dslr-camera-with-lens-extension-rings-marble-background_23-2148038876.jpg', 'composition-with-camera-plant_23-2147982597.jpg'],
                    ],
                ],
            ],
            'Audio' => [
                'image' => 'headphones-with-music-cds_23-2148133615.jpg',
                'products' => [
                    [
                        'name' => 'Lo-fi Loop Pack', 'price' => '16', 'sales' => 231, 'tags' => ['Music'],
                        'short' => 'Sixty royalty-free loops for videos, streams and podcasts.',
                        'description' => "Sixty WAV loops at 24-bit.\nTempo and key in every file name.",
                        'images' => ['headphones-with-music-cds_23-2148133615.jpg', 'close-up-headphones-near-vinyl-record-with-fingerprint_23-2147781812.jpg'],
                    ],
                    [
                        'name' => 'Podcast Intro Kit', 'price' => '21', 'compare_at' => '28', 'sales' => 76, 'tags' => ['Podcast'],
                        'short' => 'Intro, outro and transition sounds that fit together.',
                        'description' => "Fifteen WAV and MP3 files.\nThree styles: calm, upbeat and news.",
                        'images' => ['headphone-blue-cassette-tape-black-background_23-2147889764.jpg', 'headphones-with-music-cds_23-2148133615.jpg'],
                    ],
                    [
                        'name' => 'Interface Sound Effects', 'price' => '14', 'sales' => 45, 'tags' => ['Sound effects'],
                        'short' => 'Eighty short clicks, taps and alerts for apps and games.',
                        'description' => "Eighty WAV files.\nSorted into clicks, toggles, alerts and success sounds.",
                        'images' => ['close-up-headphones-near-vinyl-record-with-fingerprint_23-2147781812.jpg', 'headphone-blue-cassette-tape-black-background_23-2147889764.jpg'],
                    ],
                ],
            ],
        ];
    }
}
