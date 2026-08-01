<?php

namespace App\Application\Research\Tracing;

use App\Models\ResearchJob;

/**
 * Turns the raw event timeline into a readable story for a supervisor.
 *
 * This is deliberately a READ model, fully decoupled from how events are
 * written. It powers the `research:trace` command today and could back an
 * HTTP/JSON endpoint or a Livewire dashboard tomorrow with no changes.
 */
class ResearchTraceReader
{
    /**
     * @return array{
     *   job: array,
     *   stats: array,
     *   timeline: array<int, array{seq:int, iteration:int, type:string, glyph:string, level:string, summary:string, at:string, duration_ms:?int, payload:?array}>
     * }
     */
    public function build(string $jobId, bool $withPayloads = false): array
    {
        $job = ResearchJob::with('events')->findOrFail($jobId);

        $timeline = $job->events->map(fn ($e) => [
            'id' => $e->id,
            'seq' => $e->seq,
            'iteration' => $e->iteration,
            'type' => $e->type->value,
            'glyph' => $e->type->glyph(),
            'level' => $e->level,
            'summary' => $e->summary,
            'at' => optional($e->occurred_at)->toDateTimeString(),
            'duration_ms' => $e->duration_ms,
            'payload' => $withPayloads ? $e->payload : null,
        ])->all();

        return [
            'job' => $this->jobHeader($job),
            'stats' => $this->stats($job),
            'timeline' => $timeline,
        ];
    }

    private function jobHeader(ResearchJob $job): array
    {
        $children = $job->children()->get();

        // "Agent calls" = every LLM decision across the whole tree: the supervisor
        // plus each worker sub-agent's iterations. This is the "how many calls it
        // took" number the user asked to see.
        $agentCalls = $job->iteration + $children->sum('iteration');

        return [
            'id' => $job->id,
            'goal' => $job->goal,
            'role' => $job->role->value,
            'status' => $job->status->value,
            'iteration' => $job->iteration,
            'tool_calls' => $job->tool_call_count,
            'confidence' => $job->confidence,
            'partial' => $job->partial,
            'started_at' => optional($job->started_at)->toDateTimeString(),
            'finished_at' => optional($job->finished_at)->toDateTimeString(),
            'report' => $job->final_report,
            'last_error' => $job->last_error,
            'agent_calls' => $agentCalls,
            'worker_count' => $children->count(),
            'tasks' => $job->isSupervisor()
                ? $job->tasks()->get()->map(fn ($t) => [
                    'seq' => $t->seq, 'title' => $t->title, 'status' => $t->status->value,
                    'child_job_id' => $t->child_job_id, 'depends_on' => $t->depends_on ?? [],
                ])->all()
                : [],
            'children' => $children->map(fn ($c) => [
                'id' => $c->id, 'goal' => $c->goal, 'status' => $c->status->value,
                'iteration' => $c->iteration, 'confidence' => $c->confidence,
            ])->all(),
            'parent_job_id' => $job->parent_job_id,
        ];
    }

    private function stats(ResearchJob $job): array
    {
        $byType = $job->events()
            ->selectRaw('type, count(*) as c')
            ->groupBy('type')
            ->pluck('c', 'type')
            ->all();

        $toolUsage = $job->toolExecutions()
            ->selectRaw('tool_name, count(*) as c, sum(case when status = \'failed\' then 1 else 0 end) as failed')
            ->groupBy('tool_name')
            ->get()
            ->map(fn ($r) => ['tool' => $r->tool_name, 'calls' => (int) $r->c, 'failed' => (int) $r->failed])
            ->all();

        return [
            'events_by_type' => $byType,
            'tool_usage' => $toolUsage,
            'open_questions' => $job->humanQuestions()->whereIn('status', ['queued', 'asked'])->count(),
            'total_events' => $job->events()->count(),
        ];
    }
}
