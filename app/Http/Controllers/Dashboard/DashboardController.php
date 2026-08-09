<?php

namespace App\Http\Controllers\Dashboard;

use App\Application\Research\CancelResearch;
use App\Application\Research\ContinueResearch;
use App\Application\Research\Llm\ModelCatalog;
use App\Application\Research\StartResearch;
use App\Application\Research\Tracing\ResearchTraceReader;
use App\Domain\Research\Contracts\ResearchJobRepository;
use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\JobStatus;
use App\Domain\Research\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\HumanQuestion;
use App\Models\ResearchEvent;
use App\Models\ResearchJob;
use App\Models\ResearchTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function __construct(
        private ResearchJobRepository $jobs,
        private ModelCatalog $catalog,
    ) {}

    /** Jobs list + new-job form. */
    public function index()
    {
        return view('dashboard.index', ['jobs' => collect($this->jobsPayload())]);
    }

    /**
     * Top-level jobs (workers are nested under their supervisor). For supervisor
     * jobs we attach the task-status list (for the progress bar) and the worker
     * sub-jobs (for the expandable child rows).
     */
    private function jobsPayload(): array
    {
        $jobs = ResearchJob::query()
            ->whereNull('parent_job_id')
            ->latest('created_at')
            ->limit(50)
            ->get(['id', 'slug', 'workspace_slug', 'goal', 'status', 'role', 'iteration', 'tool_call_count', 'confidence', 'created_at']);

        $supIds = $jobs->where('role', JobRole::Supervisor)->pluck('id');
        $tasksByJob = $supIds->isEmpty() ? collect()
            : ResearchTask::whereIn('research_job_id', $supIds)->orderBy('seq')->get()->groupBy('research_job_id');
        $childrenByParent = $supIds->isEmpty() ? collect()
            : ResearchJob::whereIn('parent_job_id', $supIds)->latest('created_at')
                ->get(['id', 'slug', 'goal', 'status', 'role', 'iteration', 'tool_call_count', 'confidence', 'created_at', 'parent_job_id'])
                ->groupBy('parent_job_id');

        return $jobs->map(function (ResearchJob $j) use ($tasksByJob, $childrenByParent) {
            $row = [
                'id' => $j->id,
                'slug' => $j->slug,
                // The sandbox dir the whole job tree shares — the list links straight
                // to it, so a finished job's files are reachable without opening it.
                'workspace_slug' => $j->workspace_slug,
                'goal' => $j->goal,
                'status' => $j->status->value,
                'role' => $j->role->value,
                'iteration' => (int) $j->iteration,
                'tool_call_count' => (int) $j->tool_call_count,
                'confidence' => $j->confidence,
                'created_at' => optional($j->created_at)->toIso8601String(),
            ];

            if ($j->role === JobRole::Supervisor) {
                $tasks = $tasksByJob->get($j->id, collect());
                $row['tasks'] = $tasks->map(fn (ResearchTask $t) => ['seq' => $t->seq, 'status' => $t->status->value])->values()->all();
                $row['children'] = $childrenByParent->get($j->id, collect())->map(function (ResearchJob $w) use ($tasks) {
                    $task = $tasks->firstWhere('child_job_id', $w->id);

                    return [
                        'id' => $w->id,
                        'slug' => $w->slug,
                        'status' => $w->status->value,
                        'iteration' => (int) $w->iteration,
                        'tool_call_count' => (int) $w->tool_call_count,
                        'confidence' => $w->confidence,
                        'created_at' => optional($w->created_at)->toIso8601String(),
                        'task_seq' => $task?->seq,
                        'task_title' => $task?->title ?? $w->goal,
                    ];
                })->values()->all();
            }

            return $row;
        })->all();
    }

    /** Job detail: goal, current state, human questions, live operation history. */
    public function show(string $id, ResearchTraceReader $reader)
    {
        $job = $this->jobs->find($id);
        $data = $reader->build($id); // chronological — reads as the flow of thinking
        $data['activity'] = $this->resolveActivity($job);
        $data['role_extras'] = $this->roleExtras($job);

        return view('dashboard.show', [
            'jobId' => $id,
            'jobSlug' => $job->slug,
            'workspaceSlug' => $job->workspace_slug,
            'initial' => $data,
            'questions' => $this->questionsFor($id),
        ]);
    }

    /** Start a new research job from the UI form. */
    public function store(Request $request, StartResearch $start)
    {
        $data = $request->validate([
            'goal' => ['required', 'string', 'min:5'],
            'max_iterations' => ['nullable', 'integer', 'min:1', 'max:500'],
            'supervised' => ['nullable', 'boolean'],
        ]);

        $overrides = [];
        if (! empty($data['max_iterations'])) {
            $overrides['limits']['max_iterations'] = (int) $data['max_iterations'];
        }

        $role = ! empty($data['supervised']) ? JobRole::Supervisor : JobRole::Solo;
        $job = $start->handle($data['goal'], $overrides, $role);

        return redirect()->route('jobs.show', $job->id)
            ->with('status', $role === JobRole::Supervisor ? 'Supervised project started.' : 'Research job started.');
    }

    /** Stop a job AND every sub-agent under it (workers, reviewers, sub-projects). */
    public function cancel(string $id, CancelResearch $cancel)
    {
        $subAgents = max(0, $cancel->handle($this->jobs->find($id)) - 1);

        return back()->with('status', $subAgents > 0
            ? 'Job cancelled, along with '.$subAgents.' sub-agent'.($subAgents === 1 ? '' : 's').'.'
            : 'Job cancelled.');
    }

    /** One-click retry of a finished/failed job (no new guidance needed). */
    public function retry(string $id, ContinueResearch $continue): JsonResponse
    {
        $job = $this->jobs->find($id);
        if (! $job->status->isTerminal()) {
            return response()->json(['error' => 'This research is still active.'], 409);
        }

        $continue->handle($id, 'Continue and complete the original goal. A previous attempt was '
            .'interrupted or incomplete — resume from your existing findings and finish.');

        return response()->json(['ok' => true]);
    }

    /** Continue a finished job with new guidance (refine instead of restart). */
    public function continueJob(string $id, Request $request, ContinueResearch $continue)
    {
        $data = $request->validate(['guidance' => ['required', 'string', 'min:3']]);

        $job = $this->jobs->find($id);
        if (! $job->status->isTerminal()) {
            $msg = 'This research is still active — wait for it to finish, then continue it.';

            return $request->wantsJson() ? response()->json(['error' => $msg], 409) : back()->withErrors(['continue' => $msg]);
        }

        $continue->handle($id, $data['guidance']);

        return $request->wantsJson()
            ? response()->json(['ok' => true])
            : back()->with('status', 'Continuing research with your guidance…');
    }

    /** Delete a research job and everything under it (FK cascade). */
    public function destroy(string $id, Request $request)
    {
        $job = $this->jobs->find($id);

        // Guard: a running job may have an iteration in flight on the worker.
        // Require an explicit Cancel first so deletion is never a race.
        if ($job->fresh()->status === JobStatus::Running) {
            $msg = 'This research is still running — cancel it first, then delete.';

            return $request->wantsJson()
                ? response()->json(['error' => $msg], 409)
                : back()->withErrors(['delete' => $msg]);
        }

        $job->delete(); // steps, events, messages, tool_executions, questions cascade

        return $request->wantsJson()
            ? response()->json(['deleted' => $id])
            : redirect()->route('dashboard')->with('status', 'Research deleted.');
    }

    // ── JSON for live polling ────────────────────────────────────────────────

    public function jobsJson(): JsonResponse
    {
        return response()->json($this->jobsPayload());
    }

    public function jobJson(string $id, ResearchTraceReader $reader): JsonResponse
    {
        $job = $this->jobs->find($id);
        $data = $reader->build($id, withPayloads: request()->boolean('full')); // chronological
        $data['questions'] = $this->questionsFor($id);
        $data['activity'] = $this->resolveActivity($job);
        $data['role_extras'] = $this->roleExtras($job);

        return response()->json($data);
    }

    /** Full detail for one timeline node — loaded on demand when a node is clicked. */
    public function eventJson(string $id): JsonResponse
    {
        $e = ResearchEvent::findOrFail($id);

        return response()->json([
            'id' => $e->id,
            'type' => $e->type->value,
            'glyph' => $e->type->glyph(),
            'level' => $e->level,
            'iteration' => $e->iteration,
            'summary' => $e->summary,
            'at' => optional($e->occurred_at)->toDateTimeString(),
            'duration_ms' => $e->duration_ms,
            'payload' => $e->payload,
        ]);
    }

    /**
     * Compute the live "what is it doing right now / waiting for what" state.
     * Combines the job's current phase, elapsed time, open human questions, and
     * a worker heartbeat so the UI can distinguish real work from a dead queue.
     */
    private function resolveActivity(ResearchJob $job): array
    {
        $model = $this->catalog->displayModel();
        $open = HumanQuestion::where('research_job_id', $job->id)
            ->whereIn('status', ['queued', 'asked'])->count();

        // Terminal states first.
        if ($job->status->isTerminal()) {
            return [
                'phase' => $job->status->value,
                'label' => match ($job->status) {
                    JobStatus::Completed => 'Completed'.($job->confidence !== null ? " · confidence {$job->confidence}" : ''),
                    JobStatus::Failed => 'Failed: '.($job->last_error ?: 'unknown error'),
                    JobStatus::Cancelled => 'Cancelled',
                    default => $job->status->value,
                },
                'waiting_for' => null,
                'since_seconds' => null,
                'worker_alive' => true,
                'open_questions' => $open,
                'active' => false,
            ];
        }

        // Is a worker actually consuming the queue? Fresh heartbeat OR a very
        // recent iteration both prove liveness.
        $hb = (int) Cache::get('research:worker:last_seen', 0);
        $recentEvent = $job->activity_updated_at && $job->activity_updated_at->diffInSeconds(now()) < 30;
        $workerAlive = (time() - $hb < 120) || $recentEvent;

        $since = $job->activity_updated_at ? (int) $job->activity_updated_at->diffInSeconds(now()) : null;
        $act = (string) $job->current_activity;

        // Live "thinking" preview streamed from the model (see LlmPlanner). We
        // surface it the moment the model produces anything — no time gate — so
        // there's always visibility into what it's doing. Prefer the clean parsed
        // `thought`; fall back to the model's raw reasoning/output otherwise.
        $think = Cache::get("research:llm:think:{$job->id}");
        $thinkingFresh = is_array($think) && (time() - (int) ($think['at'] ?? 0) < 15);
        $thinkingText = is_array($think)
            ? (($think['text'] ?? '') !== '' ? $think['text'] : ($think['raw'] ?? ''))
            : '';

        // No worker → the single most useful message for "it's stuck".
        if (! $workerAlive) {
            return [
                'phase' => 'no_worker',
                'label' => 'No worker is processing the research queue',
                'waiting_for' => 'a queue worker — run: php artisan queue:work --queue=research',
                'since_seconds' => $since,
                'worker_alive' => false,
                'open_questions' => $open,
                'active' => false,
            ];
        }

        // A supervisor that delegated is PARKED waiting on a worker — this is
        // normal (workers can take minutes), not a stall. Describe what it awaits.
        $runningTask = $act === 'awaiting_worker'
            ? $job->tasks()->where('status', 'in_progress')->orderBy('seq')->first()
            : null;

        // Worker alive → describe the current phase.
        [$phase, $label, $waiting] = match (true) {
            $act === 'awaiting_worker' => [
                'awaiting_worker',
                $runningTask ? "Delegated task #{$runningTask->seq}: {$runningTask->title}" : 'Waiting for a worker sub-agent',
                $runningTask ? "worker sub-agent to finish task #{$runningTask->seq}" : 'a worker sub-agent to report back',
            ],
            str_starts_with($act, 'tool:') => [
                'running_tool',
                'Running tool: '.substr($act, 5),
                'the '.substr($act, 5).' tool to return',
            ],
            $act === 'thinking' => ['thinking', 'Analysing state & deciding the next step', "the model ({$model}) to respond"],
            $act === 'queued' => ['queued', 'Next step queued', 'a worker to pick up the next iteration'],
            default => ['working', 'Working…', null],
        };

        // Alive but no movement for a long time → likely stuck mid-iteration.
        // A fresh streaming preview, or a supervisor legitimately waiting on a
        // worker, both prove it's NOT stuck.
        if ($since !== null && $since > 210 && ! $thinkingFresh && $act !== 'awaiting_worker') {
            $phase = 'stalled';
            $label = "No progress for {$since}s — the current iteration may be stuck";
        }

        return [
            'phase' => $phase,
            'label' => $label,
            'waiting_for' => $waiting,
            'since_seconds' => $since,
            'worker_alive' => true,
            'open_questions' => $open,
            'active' => true,
            // Narrate the reasoning live, as soon as the model emits anything —
            // whether that's structured thought, chain-of-thought, or raw output.
            'thinking_preview' => ($phase === 'thinking' && $thinkingFresh && $thinkingText !== '')
                ? $thinkingText
                : null,
        ];
    }

    /**
     * Role-specific detail the Job Detail page renders on top of the trace:
     * a supervisor's task list + worker sub-agents, or a worker's parent link.
     */
    private function roleExtras(ResearchJob $job): array
    {
        $role = $job->role->value;
        $maxIter = (int) ($job->config['limits']['max_iterations'] ?? config('research.limits.max_iterations', 40));

        $out = ['role' => $role, 'max_iterations' => $maxIter, 'tasks' => [], 'workers' => [], 'parent' => null];

        if ($role === 'supervisor') {
            $tasks = $job->tasks()->get();
            $out['tasks'] = $tasks->map(fn (ResearchTask $t) => [
                'seq' => $t->seq,
                'title' => $t->title,
                'brief' => $t->brief,
                'status' => $t->status->value,               // pending|in_progress|awaiting_review|done|failed
                'attempts' => (int) $t->attempts,
                'worker' => $t->child_job_id,
                'result' => $t->result,
                'at' => optional($t->updated_at)->format('H:i'),
            ])->all();

            $out['task_counts'] = [
                'total' => $tasks->count(),
                'done' => $tasks->where('status', TaskStatus::Done)->count(),
                'failed' => $tasks->where('status', TaskStatus::Failed)->count(),
                'in_progress' => $tasks->where('status', TaskStatus::InProgress)->count(),
                'awaiting_review' => $tasks->where('status', TaskStatus::AwaitingReview)->count(),
                'reviewing' => $tasks->where('status', TaskStatus::Reviewing)->count(),
            ];

            $out['workers'] = $job->children()->latest('created_at')->get()->map(function (ResearchJob $w) use ($tasks) {
                $task = $tasks->firstWhere('child_job_id', $w->id);

                return [
                    'id' => $w->id,
                    'slug' => $w->slug,
                    'status' => $w->status->value,
                    'iteration' => (int) $w->iteration,
                    'max_iterations' => (int) ($w->config['limits']['max_iterations'] ?? config('research.supervisor.worker_max_iterations', 20)),
                    'confidence' => $w->confidence,
                    'task_seq' => $task?->seq,
                    'task_title' => $task?->title ?? $w->goal,
                    'activity' => (string) $w->current_activity,
                ];
            })->all();
        }

        if ($role === 'worker' && $job->parent_job_id) {
            $parent = $job->parent()->first();
            $task = ResearchTask::where('child_job_id', $job->id)->first();
            $out['parent'] = $parent ? [
                'id' => $parent->id,
                'slug' => $parent->slug,
                'goal' => $parent->goal,
                'task_seq' => $task?->seq,
                'task_title' => $task?->title,
            ] : null;
        }

        return $out;
    }

    private function questionsFor(string $jobId): array
    {
        return HumanQuestion::where('research_job_id', $jobId)
            ->latest()
            ->get()
            ->map(fn (HumanQuestion $q) => [
                'id' => $q->id,
                'question' => $q->question,
                'status' => $q->status->value,
                'answer' => $q->answer,
                'source' => $q->resolved_source,
                'tags' => $q->tags,
            ])->all();
    }
}
