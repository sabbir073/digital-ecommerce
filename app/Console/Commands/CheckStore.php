<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Backups\DatabaseBackup;
use App\Services\Payments\StripeSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/** A launch checklist for the server: what must be true before the store takes real money (PRD section 9 and phase 11). */
class CheckStore extends Command
{
    protected $signature = 'store:check';

    protected $description = 'Check that this server is set up safely for a live store';

    public function handle(StripeSettings $stripe, DatabaseBackup $backups): int
    {
        $live = app()->isProduction();
        $cron = Setting::get('system.cron_at');
        $last = Setting::get('backups.last');
        $database = true;
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $database = false;
        }

        // [what, passed, what to do, must it pass on a live store]
        $checks = [
            ['The database answers', $database, 'Check the DB_ lines in .env.', true],
            ['APP_ENV is production', $live, 'Set APP_ENV=production in .env on the live server.', false],
            ['Debug mode is off', ! config('app.debug'), 'Set APP_DEBUG=false in .env. Debug pages show secrets to visitors.', true],
            ['The store address uses https', str_starts_with((string) config('app.url'), 'https://'), 'Set APP_URL to the https address and turn on the SSL certificate in the hosting panel.', true],
            ['The storage folders can be written', is_writable(storage_path('app')) && is_writable(storage_path('framework')) && is_writable(storage_path('logs')), 'Make storage/ and bootstrap/cache/ writable by the web server.', true],
            ['Uploaded images are linked into public/', file_exists(public_path('storage')), 'Run: php artisan storage:link', true],
            ['The built CSS and JavaScript are present', file_exists(public_path('build/manifest.json')), 'Run: npm ci && npm run build, then upload public/build.', true],
            ['Stripe keys and the webhook secret are saved', $stripe->isConfigured() && (string) $stripe->webhookSecret() !== '', 'Enter them in Admin > Settings > Payments.', true],
            ['Stripe is in live mode', $stripe->mode() === 'live', 'Switch to live keys in Admin > Settings > Payments when you are ready to take real payments.', false],
            ['Email is delivered, not written to a log', ! in_array(config('mail.default'), ['log', 'array'], true), 'Enter an SMTP account in Admin > Integrations > Email sending.', true],
            ['The cron job ran in the last 15 minutes', $cron !== null && Carbon::parse($cron)->gt(now()->subMinutes(15)), 'Add a cron job that runs every minute: php artisan schedule:run', true],
            ['A backup was made in the last two days', is_array($last) && ($last['ok'] ?? false) && Carbon::parse($last['at'])->gt(now()->subDays(2)) && $backups->all() !== [], 'Run: php artisan backup:run  (the cron job then makes one every night).', true],
        ];

        $failed = 0;
        foreach ($checks as [$what, $passed, $fix, $required]) {
            if ($passed) {
                $this->line("  <info>OK</info>     {$what}");

                continue;
            }
            $serious = $required && $live;
            $failed += $serious ? 1 : 0;
            $this->line('  '.($serious ? '<error>FIX</error>   ' : '<comment>TO DO</comment> ')." {$what}");
            $this->line("          {$fix}");
        }

        $this->newLine();
        $this->line($failed > 0 ? "<error>{$failed} thing(s) must be fixed before this store is safe to run live.</error>"
            : ($live ? '<info>This server is ready.</info>' : 'This is not a live server (APP_ENV is not production), so the list above is a to-do list for launch.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
