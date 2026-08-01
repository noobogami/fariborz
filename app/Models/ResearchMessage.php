<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchMessage extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'sequence' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ResearchJob::class, 'research_job_id');
    }
}
