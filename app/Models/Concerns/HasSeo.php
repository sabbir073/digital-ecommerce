<?php

namespace App\Models\Concerns;

use App\Models\SeoMeta;
use App\Services\Seo\Redirects;
use App\Services\Seo\SeoSubjects;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * An item with its own SEO fields. When the address (slug) of a public item changes,
 * the old address is redirected to the new one, so links and search results keep working.
 */
trait HasSeo
{
    public static function bootHasSeo(): void
    {
        static::updated(function (self $model) {
            if (! $model->wasChanged('slug')) {
                return;
            }
            $type = SeoSubjects::typeOf($model);
            $old = (string) $model->getOriginal('slug');
            // A draft nobody could open has no visitors or links to keep.
            if ($type === '' || $old === '' || ! SeoSubjects::isPublic($type, $model)) {
                return;
            }
            app(Redirects::class)->moved(SeoSubjects::path($type, $old), SeoSubjects::path($type, $model->slug));
        });

        static::deleted(function (self $model) {
            if (! method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) {
                $model->seo()->delete();
            }
        });
    }

    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }
}
