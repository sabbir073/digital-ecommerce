<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Server-side permission check: `permission:module,action`. */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $module, string $action = 'view'): Response
    {
        $user = Auth::guard('admin')->user();

        abort_unless($user !== null && $user->allows($module, $action), 403, 'You do not have permission to do this.');

        return $next($request);
    }
}
