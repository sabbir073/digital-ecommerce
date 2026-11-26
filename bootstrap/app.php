<?php

use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware(['web', HandleInertiaRequests::class])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.auth' => AuthenticateAdmin::class,
            'permission' => RequirePermission::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin', 'admin/*') ? '/admin/login' : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Friendly error pages: Inertia for admin, Blade for the storefront.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            if (! in_array($status, [403, 404, 419, 500, 503], true) || $request->expectsJson()) {
                return $response;
            }
            if ($status === 500 && config('app.debug')) {
                return $response;
            }

            if ($request->is('admin', 'admin/*')) {
                if ($status === 419) {
                    return back()->with('error', 'The page expired. Please try again.');
                }

                Inertia::setRootView('admin');

                return Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            $messages = [
                403 => ['Not allowed', 'You do not have access to this page.'],
                404 => ['Page not found', 'The page you are looking for does not exist or was moved.'],
                419 => ['Page expired', 'Please go back, reload the page and try again.'],
                500 => ['Something went wrong', 'Please try again in a moment.'],
                503 => ['Back soon', 'The store is down for maintenance. Please check back shortly.'],
            ];

            return response()->view('storefront.error', [
                'status' => $status,
                'title' => $messages[$status][0],
                'text' => $messages[$status][1],
            ], $status);
        });
    })->create();
