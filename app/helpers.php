<?php

use App\Services\UiText;

if (! function_exists('t')) {
    /**
     * A piece of storefront interface text. The owner can reword any of them under Settings, Interface text;
     * until they do, the text written here is shown. Words like :count are filled in from $replace.
     *
     * @param  array<string, string|int|float>  $replace
     */
    function t(string $text, array $replace = []): string
    {
        $text = UiText::get($text);
        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }
}
