<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only for a signed-in, active admin user whose
 * session has not been revoked (forced sign-out bumps session_version).
 */
class AuthenticateAdmin
{
    public const SESSION_KEY = 'admin_session_version';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('admin');
        $user = $guard->user();

        if ($user === null) {
            return redirect()->guest(route('admin.login'));
        }

        $valid = $user->isActive()
            && (int) $request->session()->get(self::SESSION_KEY) === (int) $user->session_version;

        if (! $valid) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')
                ->with('error', 'Your session has ended. Please sign in again.');
        }

        Auth::shouldUse('admin');

        return $next($request);
    }
}
