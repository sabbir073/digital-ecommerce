<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'person' => ['nullable', 'integer'],
            'module' => ['nullable', 'string', 'max:40'],
        ]);

        $logs = ActivityLog::query()
            ->when($filters['person'] ?? null, fn ($q, $id) => $q->where('admin_user_id', $id))
            ->when($filters['module'] ?? null, fn ($q, $module) => $q->where('module', $module))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where(fn ($w) => $w->where('actor_name', 'like', $like)
                    ->orWhere('action', 'like', $like)
                    ->orWhere('subject_label', 'like', $like));
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'actor' => $log->actor_name,
                'action' => $log->action,
                'module' => $log->module,
                'label' => $log->subject_label,
                'changes' => $log->changes,
                'ip' => $log->ip,
                'at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Activity/Index', [
            'logs' => $logs,
            'filters' => (object) array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'people' => AdminUser::query()->orderBy('name')->get(['id', 'name']),
            'modules' => config('permissions.modules'),
        ]);
    }
}
