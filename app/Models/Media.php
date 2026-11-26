<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    protected $table = 'media';

    protected $fillable = ['folder_id', 'disk', 'path', 'filename', 'type', 'mime', 'size_bytes', 'alt', 'width', 'height', 'sizes'];

    protected function casts(): array
    {
        return ['sizes' => 'array'];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /** URL of the resized WebP nearest to (and at least) the wanted width. */
    public function sizeUrl(int $width): string
    {
        $sizes = $this->sizes ?? [];
        if ($sizes === []) {
            return $this->url();
        }
        ksort($sizes, SORT_NUMERIC);
        foreach ($sizes as $w => $path) {
            if ((int) $w >= $width) {
                return Storage::disk($this->disk)->url($path);
            }
        }

        return Storage::disk($this->disk)->url(end($sizes));
    }

    /** `srcset` value listing every generated width. */
    public function srcset(): string
    {
        $sizes = $this->sizes ?? [];
        ksort($sizes, SORT_NUMERIC);

        return collect($sizes)
            ->map(fn (string $path, int|string $w) => Storage::disk($this->disk)->url($path).' '.$w.'w')
            ->implode(', ');
    }

    /** @return array<string, mixed> */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'thumb' => $this->type === 'image' ? $this->sizeUrl(320) : null,
            'filename' => $this->filename,
            'type' => $this->type,
            'alt' => $this->alt,
            'width' => $this->width,
            'height' => $this->height,
            'sizeBytes' => $this->size_bytes,
            'folderId' => $this->folder_id,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
