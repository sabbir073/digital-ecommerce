<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tag;
use App\Services\MediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClearDemoCatalog extends Command
{
    protected $signature = 'demo:clear';

    protected $description = 'Remove the sample catalog added by demo:seed';

    public function handle(MediaService $media): int
    {
        $products = Product::query()->where('sku', 'like', 'DEMO-%')->with('images', 'variants.image')->get();

        foreach ($products as $product) {
            $images = $product->images->concat($product->variants->pluck('image')->filter())->unique('id');
            $product->delete();
            foreach ($images as $image) {
                $stillUsed = DB::table('product_media')->where('media_id', $image->id)->exists();
                if (! $stillUsed) {
                    Category::query()->where('media_id', $image->id)->update(['media_id' => null]);
                    $media->delete($image);
                }
            }
        }

        Storage::disk('downloads')->deleteDirectory('demo');
        // Ids first, then delete: MySQL cannot delete from a table it is also sub-selecting.
        $emptyIds = Category::query()->whereDoesntHave('products')->whereDoesntHave('children')
            ->whereIn('slug', ['templates', 'e-books', 'presets', 'audio'])->pluck('id');
        Category::query()->whereIn('id', $emptyIds)->delete();
        Tag::query()->where('slug', 'demo')->delete();

        $bar = Setting::get('announcement');
        if (is_array($bar) && ! empty($bar['demo'])) {
            Setting::query()->where('key', 'announcement')->first()?->delete(); // through the model, so the settings cache is cleared
        }

        $this->info("Removed {$products->count()} demo products.");

        return self::SUCCESS;
    }
}
