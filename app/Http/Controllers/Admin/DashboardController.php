<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user('admin');

        return Inertia::render('Dashboard', [
            'team' => $user->allows('staff', 'view') ? [
                'active' => AdminUser::query()->where('status', AdminUser::STATUS_ACTIVE)->count(),
                'invited' => AdminUser::query()->where('status', AdminUser::STATUS_INVITED)->count(),
                'suspended' => AdminUser::query()->where('status', AdminUser::STATUS_SUSPENDED)->count(),
                'roles' => Role::query()->count(),
                'withTwoFactor' => AdminUser::query()->whereNotNull('two_factor_confirmed_at')->count(),
            ] : null,
            'recentActivity' => $user->allows('activity', 'view')
                ? ActivityLog::query()->latest('id')->limit(8)->get()->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'actor' => $log->actor_name,
                    'action' => $log->action,
                    'module' => $log->module,
                    'label' => $log->subject_label,
                    'at' => $log->created_at?->toIso8601String(),
                ])
                : null,
        ]);
    }
}
