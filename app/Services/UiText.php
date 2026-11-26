<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Storefront interface text and the owner's rewordings of it.
 *
 * The text in the code is both the default and the key. resources/ui-strings.php lists every
 * string (built by `php artisan ui:scan`) so admin can show them all in one place.
 */
class UiText
{
    private const SETTING = 'ui.text';

    public static function get(string $text): string
    {
        $override = self::overrides()[$text] ?? null;

        return is_string($override) && $override !== '' ? $override : $text;
    }

    /** @return array<string, string> */
    public static function overrides(): array
    {
        return (array) Setting::get(self::SETTING, []);
    }

    /**
     * Every string, grouped by where it appears.
     *
     * @return array<string, list<string>>
     */
    public static function registry(): array
    {
        $file = resource_path('ui-strings.php');

        return is_file($file) ? require $file : [];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::registry()) ?: [[]])));
    }

    /**
     * Save rewordings. Text equal to the default, or empty, removes the rewording.
     *
     * @param  array<string, ?string>  $changes  default text => new text
     */
    public static function save(array $changes): void
    {
        $known = array_flip(self::all());
        $overrides = array_intersect_key(self::overrides(), $known);
        foreach ($changes as $default => $text) {
            if (! isset($known[$default])) {
                continue;
            }
            $text = trim((string) $text);
            if ($text === '' || $text === $default) {
                unset($overrides[$default]);
            } else {
                $overrides[$default] = $text;
            }
        }
        Setting::put(self::SETTING, $overrides);
    }

    /**
     * The rewordings sent to the browser for the page scripts (cart, checkout, chat messages).
     * Only changed text is sent, so an untouched store sends nothing.
     *
     * @return array<string, string>
     */
    public static function forScripts(): array
    {
        return array_filter(self::overrides(), fn ($text) => is_string($text) && $text !== '');
    }
}
