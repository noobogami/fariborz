<?php

namespace App\Models;

use App\Domain\Research\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a supervisor's task list. The supervisor delegates it to a worker
 * job (child_job_id), whose final report becomes `result` for review.
 *
 * @property int $seq
 * @property string $title
 * @property string $brief
 * @property TaskStatus $status
 */
class ResearchTask extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected $casts = [
        'status' => TaskStatus::class,
        'depends_on' => 'array',
        'outputs' => 'array',
    ];

    /** True if every task this one depends on is in the given set of Done seqs. */
    public function isReady(array $doneSeqs): bool
    {
        foreach ($this->depends_on ?? [] as $dep) {
            if (! in_array((int) $dep, $doneSeqs, true)) {
                return false;
            }
        }

        return true;
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ResearchJob::class, 'research_job_id');
    }

    public function childJob(): BelongsTo
    {
        return $this->belongsTo(ResearchJob::class, 'child_job_id');
    }
}
