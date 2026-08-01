<?php

namespace App\Models;

use App\Domain\Research\Enums\QuestionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HumanQuestion extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'status' => QuestionStatus::class,
        'tags' => 'array',
        'priority' => 'integer',
        'resolved_confidence' => 'float',
        'asked_at' => 'datetime',
        'answered_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ResearchJob::class, 'research_job_id');
    }

    /**
     * Canonical form used to decide whether two questions are "the same", so the
     * agent can't spawn ten rows with the same text.
     */
    public static function normalize(string $question): string
    {
        $q = mb_strtolower(trim($question));
        $q = preg_replace('/\s+/', ' ', $q);

        return rtrim($q, ' ?.!:;');
    }
}
