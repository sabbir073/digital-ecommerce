<?php

namespace App\Services\Integrations;

use App\Models\Setting;
use Illuminate\Http\Request;

/** Code snippets the owner adds for services the store has no card for, site-wide or on chosen pages (PRD section 14). */
class CustomScripts
{
    public const PLACEMENTS = ['head' => 'Inside <head>', 'body_start' => 'Start of <body>', 'body_end' => 'End of <body>'];

    public const MAX = 30;

    /** @return list<array{id:string, name:string, placement:string, scope:string, paths:list<string>, code:string, enabled:bool}> */
    public function all(): array
    {
        return array_values(array_map(fn ($script) => [
            'id' => (string) ($script['id'] ?? ''),
            'name' => (string) ($script['name'] ?? ''),
            'placement' => isset(self::PLACEMENTS[$script['placement'] ?? '']) ? $script['placement'] : 'head',
            'scope' => ($script['scope'] ?? 'all') === 'paths' ? 'paths' : 'all',
            'paths' => array_values(array_map('strval', (array) ($script['paths'] ?? []))),
            'code' => (string) ($script['code'] ?? ''),
            'enabled' => (bool) ($script['enabled'] ?? false),
        ], (array) Setting::get('integrations.scripts', [])));
    }

    /** @param list<array<string, mixed>> $scripts */
    public function save(array $scripts): void
    {
        Setting::put('integrations.scripts', array_values($scripts));
    }

    /**
     * The code for this address, joined per placement.
     *
     * @return array{head:string, body_start:string, body_end:string}
     */
    public function for(Request $request): array
    {
        $out = ['head' => '', 'body_start' => '', 'body_end' => ''];
        $path = '/'.trim($request->path(), '/');
        foreach ($this->all() as $script) {
            if (! $script['enabled'] || trim($script['code']) === '') {
                continue;
            }
            if ($script['scope'] === 'paths' && ! collect($script['paths'])->contains(fn (string $rule) => self::matches($rule, $path))) {
                continue;
            }
            $out[$script['placement']] .= ($out[$script['placement']] === '' ? '' : "\n").$script['code'];
        }

        return $out;
    }

    /** "/p/summer" is one page; "/blog/*" is every address under /blog. */
    public static function matches(string $rule, string $path): bool
    {
        $rule = '/'.trim(trim($rule), '/');
        if (str_ends_with($rule, '/*')) {
            $base = substr($rule, 0, -2);

            return $path === $base || str_starts_with($path, $base.'/') || $base === '';
        }

        return mb_strtolower($rule) === mb_strtolower($path);
    }
}
