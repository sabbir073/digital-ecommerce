<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    /**
     * Record one admin action.
     *
     * @param  array<string, mixed>  $changes
     */
    public function log(
        string $action,
        string $module,
        ?Model $subject = null,
        ?string $label = null,
        array $changes = [],
        ?AdminUser $actor = null,
    ): ActivityLog {
        $actor ??= Auth::guard('admin')->user();

        return ActivityLog::create([
            'admin_user_id' => $actor?->getKey(),
            'actor_name' => $actor?->name ?? 'System',
            'action' => $action,
            'module' => $module,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label,
            'changes' => $changes ?: null,
            'ip' => request()?->ip(),
        ]);
    }
}
