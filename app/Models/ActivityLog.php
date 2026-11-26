<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail (PRD section 15). Rows can be created but never
 * changed or removed through the application.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_user_id', 'actor_name', 'action', 'module', 'subject_type',
        'subject_id', 'subject_label', 'changes', 'ip',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity log entries cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Activity log entries cannot be deleted.'));
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
