<?php

namespace App\Models;

use App\Domain\Research\Enums\JobStatus;
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

    /**
     * Marks a task's `result` as a WORKER FAILURE (crash / rate-limit / infra error)
     * rather than a produced artifact. The orchestrator reads this to decide whether
     * an attempt-cap-exhausted task should be force-accepted (had output, just kept
     * getting revised) or marked Failed (never produced anything worth keeping).
     */
    public const WORKER_ERROR_PREFIX = '⚠ Worker error: ';

    /**
     * DISPLAY-ONLY status (never a TaskStatus, never written): the task row still
     * says "in flight" but nothing is actually working on it.
     */
    public const DISPLAY_STOPPED = 'stopped';

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

    /**
     * The status to SHOW for this task. `in_progress`/`reviewing` is a claim that a
     * sub-agent is working on it RIGHT NOW — a lie once the supervisor that owns the
     * task is over, or the sub-agent holding it was cancelled. Both leave the row
     * un-released: a job stopped before CancelResearch existed never reset its tasks,
     * and a cancel that races an in-flight supervisor iteration can see the iteration
     * re-delegate right after the reset. Rather than parade a dead worker as running,
     * report it as `stopped`; the underlying TaskStatus is left untouched, so a rerun
     * still sees the real state.
     *
     * @param  ResearchJob  $owner  the supervisor this task belongs to
     * @param  ?string  $holderStatus  status of the sub-agent holding it (child_job_id), if known
     */
    public function displayStatus(ResearchJob $owner, ?string $holderStatus = null): string
    {
        $inFlight = in_array($this->status, [TaskStatus::InProgress, TaskStatus::Reviewing], true);

        return $inFlight && ($owner->status->isTerminal() || $holderStatus === JobStatus::Cancelled->value)
            ? self::DISPLAY_STOPPED
            : $this->status->value;
    }

    /**
     * True when this task's last outcome was a WORKER FAILURE (its `result` carries
     * the error prefix) rather than a produced artifact — i.e. the pending task in
     * hand is a RETRY of a failure, not a first attempt or a review revision.
     */
    public function lastAttemptFailed(): bool
    {
        return str_starts_with((string) $this->result, self::WORKER_ERROR_PREFIX);
    }

    /** The bare failure reason (error prefix stripped), or '' if the last outcome wasn't a failure. */
    public function failureReason(): string
    {
        return $this->lastAttemptFailed()
            ? trim(substr((string) $this->result, strlen(self::WORKER_ERROR_PREFIX)))
            : '';
    }

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
