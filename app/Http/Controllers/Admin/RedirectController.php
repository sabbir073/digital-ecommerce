<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotFoundLog;
use App\Models\Redirect;
use App\Services\ActivityLogger;
use App\Services\Seo\Redirects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The redirect manager and the 404 log (PRD section 13). */
class RedirectController extends Controller
{
    private const IMPORT_MAX = 2000;

    public function __construct(private readonly Redirects $redirects, private readonly ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($search, '%_\\').'%';

        return Inertia::render('Seo/Redirects', [
            'redirects' => Redirect::query()
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('from_path', 'like', $like)->orWhere('to_path', 'like', $like)))
                ->latest('id')->paginate(50, ['*'], 'page')->withQueryString()
                ->through(fn (Redirect $r) => ['id' => $r->id, 'from' => $r->from_path, 'to' => $r->to_path, 'type' => $r->type, 'hits' => $r->hits, 'createdAt' => $r->created_at?->toDateString()]),
            'notFound' => NotFoundLog::query()->orderByDesc('hits')->orderByDesc('last_seen_at')
                ->paginate(50, ['*'], 'missing')->withQueryString()
                ->through(fn (NotFoundLog $log) => ['id' => $log->id, 'path' => $log->path, 'hits' => $log->hits, 'lastSeen' => $log->last_seen_at?->diffForHumans()]),
            'search' => $search,
            'tab' => $request->query('tab') === '404' ? '404' : 'redirects',
            'importMax' => self::IMPORT_MAX,
            'importProblems' => (array) $request->session()->get('importProblems', []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $redirect = $this->redirects->save(null, $data['from_path'], $data['to_path'], (int) $data['type']);
        // The address is taken care of, so it leaves the 404 log.
        NotFoundLog::query()->where('path_hash', $redirect->from_hash)->delete();
        $this->activity->log('create', 'seo', $redirect, "Redirect {$redirect->from_path} to {$redirect->to_path}");

        return back()->with('success', 'Redirect created.');
    }

    public function update(Request $request, Redirect $redirect): RedirectResponse
    {
        $data = $this->validated($request);
        $this->redirects->save($redirect, $data['from_path'], $data['to_path'], (int) $data['type']);
        $this->activity->log('edit', 'seo', $redirect, "Redirect {$redirect->from_path} to {$redirect->to_path}");

        return back()->with('success', 'Redirect saved.');
    }

    public function destroy(Redirect $redirect): RedirectResponse
    {
        $this->activity->log('delete', 'seo', $redirect, "Redirect {$redirect->from_path} to {$redirect->to_path}");
        $redirect->delete();
        Redirects::flush();

        return back()->with('success', 'Redirect deleted.');
    }

    /**
     * A CSV with one redirect per line: old address, new address, and optionally 301 or 302.
     * Good lines are saved; every line that is not is reported with its number and the reason.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:1024', 'mimes:csv,txt']], ['file.mimes' => 'Upload a CSV file.', 'file.max' => 'The file is larger than 1 MB.']);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $created = 0;
        $problems = [];
        $line = 0;
        while ($handle && ($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            $row = array_map(fn ($cell) => trim((string) $cell), $row);
            if ($line === 1) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]); // Excel's byte order mark
            }
            if (($row[0] ?? '') === '' && ($row[1] ?? '') === '') {
                continue;
            }
            // A heading line is recognised by not looking like an address.
            if ($line === 1 && ! str_starts_with($row[0], '/') && ! preg_match('~^https?://~i', $row[0])) {
                continue;
            }
            if ($created >= self::IMPORT_MAX) {
                $problems[] = "Line {$line} and after: only ".self::IMPORT_MAX.' redirects can be imported at once.';
                break;
            }
            $type = ($row[2] ?? '') === '' ? 301 : (int) $row[2];
            try {
                if (($row[1] ?? '') === '') {
                    throw ValidationException::withMessages(['to_path' => 'The new address is missing.']);
                }
                if (! in_array($type, [301, 302], true)) {
                    throw ValidationException::withMessages(['type' => 'The type must be 301 or 302.']);
                }
                if (mb_strlen($row[0]) > 500 || mb_strlen($row[1]) > 500) {
                    throw ValidationException::withMessages(['from_path' => 'An address is longer than 500 characters.']);
                }
                $redirect = $this->redirects->save(null, $row[0], $row[1], $type);
                NotFoundLog::query()->where('path_hash', $redirect->from_hash)->delete();
                $created++;
            } catch (ValidationException $e) {
                if (count($problems) < 50) {
                    $problems[] = "Line {$line}: ".collect($e->errors())->flatten()->first();
                }
            }
        }
        if ($handle) {
            fclose($handle);
        }

        if ($created > 0) {
            $this->activity->log('create', 'seo', null, "Imported {$created} redirects");
        }
        $message = $created === 1 ? '1 redirect imported.' : "{$created} redirects imported.";

        return back()->with($created > 0 ? 'success' : 'error', $message)->with('importProblems', $problems);
    }

    public function destroyNotFound(NotFoundLog $log): RedirectResponse
    {
        $log->delete();

        return back()->with('success', 'Removed from the log.');
    }

    public function clearNotFound(): RedirectResponse
    {
        NotFoundLog::query()->delete();
        $this->activity->log('delete', 'seo', null, 'Cleared the 404 log');

        return back()->with('success', 'The 404 log is empty.');
    }

    /** @return array{from_path:string, to_path:string, type:int|string} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'from_path' => ['required', 'string', 'max:500'],
            'to_path' => ['required', 'string', 'max:500'],
            'type' => ['required', Rule::in([301, 302, '301', '302'])],
        ], [
            'from_path.required' => 'Enter the old address, for example /old-page.',
            'to_path.required' => 'Enter the new address, for example /new-page.',
        ]);
    }
}
