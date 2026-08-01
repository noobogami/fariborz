<?php

namespace App\Models;

use App\Domain\Research\Enums\StepType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchStep extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'type' => StepType::class,
        'action' => 'array',
        'iteration' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ResearchJob::class, 'research_job_id');
    }
}
