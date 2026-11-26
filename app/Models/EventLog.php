<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One tracking event handed to one service, with what was sent and what came back. */
class EventLog extends Model
{
    public const KEEP = 500;

    public $timestamps = false;

    protected $fillable = ['event', 'service', 'payload', 'response', 'ok', 'created_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'ok' => 'boolean', 'created_at' => 'datetime'];
    }

    /** Add a line and drop the ones beyond the newest 500. */
    public static function record(string $event, string $service, array $payload, string $response, bool $ok = true): self
    {
        $log = self::create(['event' => $event, 'service' => $service, 'payload' => $payload, 'response' => mb_substr($response, 0, 2000), 'ok' => $ok, 'created_at' => now()]);
        $cutoff = self::query()->orderByDesc('id')->skip(self::KEEP)->value('id');
        if ($cutoff) {
            self::query()->where('id', '<=', $cutoff)->delete();
        }

        return $log;
    }
}
