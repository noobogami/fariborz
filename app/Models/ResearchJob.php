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
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $slug
 * @property string $workspace_slug
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
        'requirements' => 'array',
        'confidence' => 'float',
        'review_verdict' => 'array',
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
     * waits when workers OR reviewers are in flight AND there is nothing it could
     * do right now — no task awaiting review, and no pending task whose
     * dependencies are met. A task being Reviewing counts as in-flight (a
     * reviewer agent owns it right now) exactly like InProgress does.
     *
     * @param  Collection<int,ResearchTask>|null  $tasks
     */
    public function supervisorShouldWait($tasks = null): bool
    {
        $tasks ??= $this->tasks()->get();
        $done = $tasks->where('status', TaskStatus::Done)->pluck('seq')->map(fn ($s) => (int) $s)->all();

        $hasReviewable = $tasks->contains(fn ($t) => $t->status === TaskStatus::AwaitingReview);
        $inFlight = $tasks->contains(fn ($t) => in_array($t->status, [TaskStatus::InProgress, TaskStatus::Reviewing], true));
        $hasReadyPending = $tasks->contains(fn ($t) => $t->status === TaskStatus::Pending && $t->isReady($done));

        return $inFlight && ! $hasReviewable && ! $hasReadyPending;
    }

    public function isRunnable(): bool
    {
        return $this->status->isRunnable();
    }

    /**
     * Whether any supervisor above this sub-agent has been cancelled — stopping a
     * job stops everything under it, so a worker whose parent (or its parent's
     * parent) was stopped must stop too. Reads fresh, id-only rows; the depth
     * guard also makes a corrupt parent chain impossible to loop on.
     */
    public function hasCancelledAncestor(): bool
    {
        $parentId = $this->parent_job_id;

        for ($depth = 0; $parentId && $depth < 10; $depth++) {
            $ancestor = static::query()
                ->select(['parent_job_id', 'status'])
                ->find($parentId);

            if (! $ancestor) {
                return false;
            }
            if ($ancestor->status === JobStatus::Cancelled) {
                return true;
            }
            $parentId = $ancestor->parent_job_id;
        }

        return false;
    }

    /** A convenience limit accessor with config fallback. */
    public function limit(string $key): int
    {
        return (int) ($this->config['limits'][$key] ?? config("research.limits.$key"));
    }

    /**
     * A short, human-readable id derived from the goal plus a random suffix
     * (e.g. "todo-app-4f21a"). Unique — retries the suffix on the rare clash.
     * Non-latin goals collapse to the "job-…" fallback, still legible + unique.
     */
    public static function generateSlug(string $goal): string
    {
        $base = Str::of($goal)->ascii()->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')->limit(32, '')->trim('-')->value();

        if ($base === '') {
            $base = 'job';
        }

        do {
            $slug = $base.'-'.substr(bin2hex(random_bytes(3)), 0, 5);
        } while (static::where('slug', $slug)->exists());

        return $slug;
    }
}
