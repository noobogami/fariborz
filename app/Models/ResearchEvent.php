<?php

namespace App\Models;

use App\Domain\Research\Enums\EventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row = one thing that happened, in order. The agent's diary.
 */
class ResearchEvent extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'type' => EventType::class,
        'payload' => 'array',
        'seq' => 'integer',
        'iteration' => 'integer',
        'duration_ms' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ResearchJob::class, 'research_job_id');
    }
}
