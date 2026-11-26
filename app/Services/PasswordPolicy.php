<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Validation\Rules\Password;

/** Password rules for admin accounts, set in admin under Staff > Sign-in security. */
class PasswordPolicy
{
    public const DEFAULTS = ['min_length' => 10, 'mixed_case' => false, 'numbers' => true, 'symbols' => false];

    /** @return array{min_length:int, mixed_case:bool, numbers:bool, symbols:bool} */
    public function settings(): array
    {
        return array_merge(self::DEFAULTS, (array) Setting::get('security.password_policy', []));
    }

    public function rule(): Password
    {
        $s = $this->settings();
        $rule = Password::min(max(8, (int) $s['min_length']))->letters();
        if ($s['mixed_case']) {
            $rule->mixedCase();
        }
        if ($s['numbers']) {
            $rule->numbers();
        }
        if ($s['symbols']) {
            $rule->symbols();
        }

        return $rule;
    }

    public function describe(): string
    {
        $s = $this->settings();
        $parts = ['at least '.max(8, (int) $s['min_length']).' characters'];
        if ($s['mixed_case']) {
            $parts[] = 'upper and lower case letters';
        }
        if ($s['numbers']) {
            $parts[] = 'a number';
        }
        if ($s['symbols']) {
            $parts[] = 'a symbol';
        }

        return 'Use '.implode(', ', $parts).'.';
    }
}
