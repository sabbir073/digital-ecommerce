<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'is_encrypted'];

    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('settings.all'));
        static::deleted(fn () => Cache::forget('settings.all'));
    }

    /** @return array<string, mixed> */
    public static function allCached(): array
    {
        return Cache::rememberForever('settings.all', function () {
            return static::query()->get()->mapWithKeys(function (Setting $s) {
                $raw = $s->is_encrypted && $s->value !== null ? Crypt::decryptString($s->value) : $s->value;

                return [$s->key => json_decode((string) $raw, true)];
            })->all();
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::allCached()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, bool $encrypted = false): void
    {
        $json = json_encode($value);
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $encrypted ? Crypt::encryptString($json) : $json, 'is_encrypted' => $encrypted],
        );
    }
}
