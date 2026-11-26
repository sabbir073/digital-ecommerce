<?php

namespace App\Http\Middleware;

use App\Models\NotFoundLog;
use App\Models\Redirect;
use App\Services\Seo\Redirects;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sends old addresses to their new ones (the redirect manager in admin) and keeps a log of
 * addresses that were asked for but do not exist, so the owner can redirect those too.
 */
class HandleRedirects
{
    public function __construct(private readonly Redirects $redirects) {}

    public function handle(Request $request, Closure $next): Response
    {
        $watched = $request->isMethod('GET') && ! $request->is('admin', 'admin/*', 'up', 'build/*', 'storage/*');

        if ($watched) {
            try {
                $match = $this->redirects->match($request->getPathInfo());
            } catch (Throwable $e) {
                $match = null; // the table is missing while the database is being set up
            }
            if ($match) {
                Redirect::query()->whereKey($match['id'])->increment('hits');
                $to = $match['to'];
                // The visitor's query string travels along unless the target has its own.
                if ($request->getQueryString() && ! str_contains($to, '?')) {
                    $to .= '?'.$request->getQueryString();
                }

                return redirect()->to($to, $match['type']);
            }
        }

        $response = $next($request);

        if ($watched && $response->getStatusCode() === 404 && ! $request->expectsJson()) {
            $this->log($request->getPathInfo());
        }

        return $response;
    }

    private function log(string $path): void
    {
        $path = mb_substr(Redirects::normalize($path), 0, 500);
        // Files that browsers and bots ask for on their own say nothing about broken links.
        if (preg_match('~\.(png|jpe?g|gif|webp|svg|ico|css|js|map|woff2?|ttf|php|env|xml|txt|json)$~i', $path) || str_starts_with($path, '/.')) {
            return;
        }
        try {
            $hash = Redirects::hash($path);
            $updated = NotFoundLog::query()->where('path_hash', $hash)->update(['hits' => \DB::raw('hits + 1'), 'last_seen_at' => now()]);
            if ($updated === 0 && NotFoundLog::query()->count() < 5000) {
                NotFoundLog::query()->create(['path' => $path, 'path_hash' => $hash, 'hits' => 1, 'last_seen_at' => now()]);
            }
        } catch (Throwable $e) {
            Log::warning('404 log failed', ['message' => $e->getMessage()]);
        }
    }
}
