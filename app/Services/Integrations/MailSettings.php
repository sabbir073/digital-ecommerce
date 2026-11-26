<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** How the store sends email: the server's own setup, or the SMTP account entered in admin (PRD section 14). */
class MailSettings
{
    private const SERVICE = 'email';

    private const CACHE = 'integrations.mail';

    public const ENCRYPTION = ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None'];

    /** @return array{driver:string, host:string, port:int, encryption:string, username:string, password:string, from_address:string, from_name:string} */
    public function all(): array
    {
        try {
            $saved = Cache::rememberForever(self::CACHE, fn () => (array) (Integration::query()->where('service', self::SERVICE)->first()?->settings ?? []));
        } catch (Throwable $e) {
            $saved = []; // the table is missing while the database is being set up
        }

        return [
            'driver' => ($saved['driver'] ?? 'server') === 'smtp' ? 'smtp' : 'server',
            'host' => (string) ($saved['host'] ?? ''),
            'port' => (int) ($saved['port'] ?? 587),
            'encryption' => isset(self::ENCRYPTION[$saved['encryption'] ?? '']) ? $saved['encryption'] : 'tls',
            'username' => (string) ($saved['username'] ?? ''),
            'password' => (string) ($saved['password'] ?? ''),
            'from_address' => (string) ($saved['from_address'] ?? ''),
            'from_name' => (string) ($saved['from_name'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $values an empty password keeps the saved one */
    public function save(array $values): void
    {
        $current = $this->all();
        if (trim((string) ($values['password'] ?? '')) === '') {
            $values['password'] = $current['password'];
        }
        Integration::query()->updateOrCreate(['service' => self::SERVICE], [
            'settings' => array_intersect_key($values, $current) + $current,
            'enabled' => ($values['driver'] ?? 'server') === 'smtp',
            'status' => ($values['driver'] ?? 'server') === 'smtp' ? 'on' : 'off',
            'last_error' => null,
        ]);
        Cache::forget(self::CACHE);
        $this->apply();
    }

    /** Point Laravel's mailer at the saved account. Called for every request, before any email is built. */
    public function apply(): void
    {
        $mail = $this->all();
        if ($mail['from_address'] !== '') {
            config(['mail.from.address' => $mail['from_address']]);
        }
        if ($mail['from_name'] !== '') {
            config(['mail.from.name' => $mail['from_name']]);
        }
        if ($mail['driver'] !== 'smtp' || $mail['host'] === '') {
            return;
        }
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => $mail['encryption'] === 'ssl' ? 'smtps' : 'smtp',
                'host' => $mail['host'],
                'port' => $mail['port'],
                'username' => $mail['username'] ?: null,
                'password' => $mail['password'] ?: null,
                'timeout' => 15,
                'require_tls' => $mail['encryption'] === 'tls',
            ] + (array) config('mail.mailers.smtp', []),
        ]);
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
    }

    public function report(bool $ok, ?string $error = null): void
    {
        Integration::query()->where('service', self::SERVICE)->update($ok
            ? ['status' => 'on', 'last_error' => null, 'last_event_at' => now()]
            : ['status' => 'error', 'last_error' => mb_substr((string) $error, 0, 500)]);
    }

    /** @return array{status:string, last_error:?string, last_event_at:?string} */
    public function status(): array
    {
        $row = Integration::query()->where('service', self::SERVICE)->first();

        return ['status' => $row?->status ?? 'off', 'last_error' => $row?->last_error, 'last_event_at' => $row?->last_event_at?->toIso8601String()];
    }
}
