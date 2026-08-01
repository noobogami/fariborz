<?php

namespace App\Models;

use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\JobStatus;
use App\Domain\Research\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property string $id
 * @property string $goal
 * @property array|null $requirements
 * @property JobStatus $status
 * @property array $config
 * @property int $iteration
 * @property int $tool_call_count
 * @property int $parse_failures
 */
class ResearchJob extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** In-memory defaults so a freshly created model matches the DB defaults. */
    protected $attributes = [
        'role' => 'solo',
        'iteration' => 0,
        'tool_call_count' => 0,
        'parse_failures' => 0,
        'partial' => false,
    ];

    protected $casts = [
        'status' => JobStatus::class,
        'role' => JobRole::class,
        'config' => 'array',
        'confidence' => 'float',
        'partial' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'deadline_at' => 'datetime',
        'activity_updated_at' => 'datetime',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(ResearchStep::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ResearchEvent::class)->orderBy('seq');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ResearchMessage::class)->orderBy('sequence');
    }

    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ToolExecution::class);
    }

    public function humanQuestions(): HasMany
    {
        return $this->hasMany(HumanQuestion::class);
    }

    /** The task list this supervisor is working through. */
    public function tasks(): HasMany
    {
        return $this->hasMany(ResearchTask::class)->orderBy('seq');
    }

    /** Worker sub-jobs this supervisor spawned. */
    public function children(): HasMany
    {
        return $this->hasMany(ResearchJob::class, 'parent_job_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ResearchJob::class, 'parent_job_id');
    }

    public function isSupervisor(): bool
    {
        return $this->role === JobRole::Supervisor;
    }

    /** How many levels of supervision are above this job (root = 0). */
    public function depth(): int
    {
        $d = 0;
        $p = $this->parent;
        while ($p) {
            $d++;
            $p = $p->parent;
        }

        return $d;
    }

    /**
     * Whether the supervisor should PARK (stop looping) and just wait: it only
     * waits when workers are in flight AND there is nothing it could do right now
     * — no task awaiting review, and no pending task whose dependencies are met.
     *
     * @param  Collection<int,ResearchTask>|null  $tasks
     */
    public function supervisorShouldWait($tasks = null): bool
    {
        $tasks ??= $this->tasks()->get();
        $done = $tasks->where('status', TaskStatus::Done)->pluck('seq')->map(fn ($s) => (int) $s)->all();

        $hasReviewable = $tasks->contains(fn ($t) => $t->status === TaskStatus::AwaitingReview);
        $inFlight = $tasks->contains(fn ($t) => $t->status === TaskStatus::InProgress);
        $hasReadyPending = $tasks->contains(fn ($t) => $t->status === TaskStatus::Pending && $t->isReady($done));

        return $inFlight && ! $hasReviewable && ! $hasReadyPending;
    }

    public function isRunnable(): bool
    {
        return $this->status->isRunnable();
    }

    /** A convenience limit accessor with config fallback. */
    public function limit(string $key): int
    {
        return (int) ($this->config['limits'][$key] ?? config("research.limits.$key"));
    }
}
