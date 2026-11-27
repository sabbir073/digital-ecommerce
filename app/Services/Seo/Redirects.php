<?php

namespace App\Services\Seo;

use App\Models\Redirect;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** The redirect table: lookups for every request, and the rules that keep it free of loops and chains. */
class Redirects
{
    private const CACHE = 'seo.redirects';

    /** "/Old-Page/?x=1" and "https://this-site/old-page" both become "/old-page". */
    public static function normalize(string $path): string
    {
        $path = trim($path);
        $host = parse_url(url('/'), PHP_URL_HOST);
        // Host names are not case sensitive: SHOP.example.com is this site too.
        if (preg_match('~^https?://~i', $path) && strcasecmp((string) parse_url($path, PHP_URL_HOST), (string) $host) === 0) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }
        $path = (string) strtok($path, '?#');
        $path = '/'.trim($path, '/');

        return mb_strtolower(rawurldecode($path));
    }

    public static function hash(string $path): string
    {
        return hash('sha256', self::normalize($path));
    }

    /**
     * Every redirect, keyed by the hash of the old path. Small enough to keep in the cache whole,
     * so a normal page view costs no database query.
     *
     * @return array<string, array{id:int, to:string, type:int}>
     */
    public function map(): array
    {
        return Cache::rememberForever(self::CACHE, fn () => Redirect::query()->get(['id', 'from_hash', 'to_path', 'type'])
            ->mapWithKeys(fn (Redirect $r) => [$r->from_hash => ['id' => $r->id, 'to' => $r->to_path, 'type' => (int) $r->type]])->all());
    }

    /** @return array{id:int, to:string, type:int}|null */
    public function match(string $path): ?array
    {
        return $this->map()[self::hash($path)] ?? null;
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE);
    }

    /**
     * Where a target ends up after following the redirects already saved; null when it comes back to $from.
     */
    public function loops(string $from, string $to, ?int $ignoreId = null): bool
    {
        $from = self::normalize($from);
        if (preg_match('~^https?://~i', $to) && parse_url($to, PHP_URL_HOST) !== parse_url(url('/'), PHP_URL_HOST)) {
            return false; // another site cannot lead back into this table
        }
        $current = self::normalize($to);
        $map = $this->map();
        for ($i = 0; $i < 50; $i++) {
            if ($current === $from) {
                return true;
            }
            $next = $map[hash('sha256', $current)] ?? null;
            if ($next === null || $next['id'] === $ignoreId) {
                return false;
            }
            if (preg_match('~^https?://~i', $next['to'])) {
                return false;
            }
            $current = self::normalize($next['to']);
        }

        return true;
    }

    /**
     * Create or change a redirect made by hand.
     *
     * @throws ValidationException
     */
    public function save(?Redirect $redirect, string $from, string $to, int $type): Redirect
    {
        $from = self::normalize($from);
        $to = trim($to);
        if (! preg_match('~^https?://~i', $to)) {
            $to = '/'.ltrim($to, '/');
        }
        if ($from === '/' || str_starts_with($from, '/admin')) {
            throw ValidationException::withMessages(['from_path' => 'The home page and the admin area cannot be redirected.']);
        }
        if (Redirect::query()->where('from_hash', self::hash($from))->when($redirect, fn ($q) => $q->whereKeyNot($redirect->id))->exists()) {
            throw ValidationException::withMessages(['from_path' => 'This address already has a redirect.']);
        }
        if ($this->loops($from, $to, $redirect?->id)) {
            throw ValidationException::withMessages(['to_path' => 'This would send visitors in a circle: the new address leads back to the old one.']);
        }

        $redirect ??= new Redirect;
        $redirect->fill(['from_path' => $from, 'from_hash' => self::hash($from), 'to_path' => $to, 'type' => $type === 302 ? 302 : 301])->save();
        self::flush();

        return $redirect;
    }

    /**
     * A published address changed: send the old one to the new one, and tidy the table so that
     * nothing points through the old address or shadows the new one.
     */
    public function moved(string $oldPath, string $newPath): void
    {
        $old = self::normalize($oldPath);
        $new = self::normalize($newPath);
        if ($old === $new) {
            return;
        }
        // The new address is a real page now, so a redirect away from it would hide the page.
        Redirect::query()->where('from_hash', self::hash($new))->delete();
        // Anything that pointed at the old address goes straight to the new one.
        Redirect::query()->where('to_path', $old)->update(['to_path' => $new]);
        Redirect::query()->updateOrCreate(['from_hash' => self::hash($old)], ['from_path' => $old, 'to_path' => $new, 'type' => 301]);
        self::flush();
    }
}
