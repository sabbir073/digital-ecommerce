<?php

namespace App\Services\Chat;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/** Live chat settings, and whether someone from the team is at the inbox right now. */
class ChatSettings
{
    public const DEFAULTS = [
        'enabled' => true,
        'on_selling_pages' => false,
        'welcome' => 'Hi! Ask us anything about our products or your order.',
        'away' => 'We are away right now. Leave your message and email, and we will reply by email.',
        'notify_email' => '',
    ];

    /** Staff count as online for this long after their inbox last checked for messages. */
    private const ONLINE_SECONDS = 45;

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key((array) Setting::get('chat.settings', []), self::DEFAULTS));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key];
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        Setting::put('chat.settings', array_merge($this->all(), array_intersect_key($values, self::DEFAULTS)));
    }

    /** Called each time a moderator's open inbox checks for new messages. */
    public function markStaffOnline(): void
    {
        Cache::put('chat.staff_seen', now()->timestamp, 300);
    }

    public function staffOnline(): bool
    {
        return now()->timestamp - (int) Cache::get('chat.staff_seen', 0) <= self::ONLINE_SECONDS;
    }
}
