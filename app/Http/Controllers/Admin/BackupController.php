<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\Backups\DatabaseBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Admin > Backups. A backup holds every customer and order, so only Super Admins get in:
 * the rule is in code, not in the permission matrix.
 */
class BackupController extends Controller
{
    public function __construct(private readonly DatabaseBackup $backups, private readonly ActivityLogger $activity) {}

    private function superOnly(): void
    {
        abort_unless(Auth::guard('admin')->user()?->isSuper(), 403);
    }

    public function index(): Response
    {
        $this->superOnly();

        return Inertia::render('Settings/Backups', [
            'backups' => array_map(fn (array $b) => ['name' => $b['name'], 'size' => $b['size'], 'at' => date(DATE_ATOM, $b['at'])], $this->backups->all()),
            'keep' => $this->backups->keep(),
            'last' => Setting::get('backups.last'),
            'database' => ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'sqlite' => 'SQLite'][config('database.default')] ?? config('database.default'),
        ]);
    }

    public function store(): RedirectResponse
    {
        $this->superOnly();
        try {
            $backup = $this->backups->create();
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'The backup failed: '.$e->getMessage());
        }
        $this->activity->log('create', 'settings', null, 'Backup '.$backup['name']);

        return back()->with('success', 'Backup made.');
    }

    public function update(Request $request): RedirectResponse
    {
        $this->superOnly();
        $data = $request->validate(['keep' => ['required', 'integer', 'min:3', 'max:90']]);
        Setting::put('backups.keep', (int) $data['keep']);
        $this->backups->prune();
        $this->activity->log('edit', 'settings', null, 'Backups kept: '.$data['keep']);

        return back()->with('success', 'Saved.');
    }

    public function download(string $name): BinaryFileResponse
    {
        $this->superOnly();
        $path = $this->backups->path($name);
        abort_unless($path, 404);
        $this->activity->log('view', 'settings', null, 'Downloaded backup '.$name);

        return response()->download($path, $name, ['Cache-Control' => 'no-store']);
    }

    public function destroy(string $name): RedirectResponse
    {
        $this->superOnly();
        abort_unless($this->backups->path($name), 404);
        $this->backups->delete($name);
        $this->activity->log('delete', 'settings', null, 'Backup '.$name);

        return back()->with('success', 'Backup deleted.');
    }
}
