<?php

namespace App\Infrastructure\Research\Tools;

use App\Domain\Research\Contracts\ControlTool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use App\Models\ResearchTask;

/**
 * SUPERVISOR tool. Break the goal into an ordered task list. Each task is
 * handed to a fresh worker sub-agent later via delegate_task. Can be called
 * again to append tasks as the plan evolves.
 */
class PlanTasksTool implements ControlTool
{
    public function name(): string
    {
        return 'plan_tasks';
    }

    public function description(): string
    {
        return 'Break the GOAL into a list of concrete, doable tasks. Each needs a title, a '
            .'"brief" (what to do + what "done" looks like), and "depends_on": the task numbers '
            .'that must be FINISHED & VERIFIED before it can start. Use deps to model order — '
            .'e.g. chapter 2 depends_on [1]. Leave depends_on EMPTY [] for independent tasks '
            .'that can run in parallel (e.g. a web server that does not need the chapters). If '
            .'you omit depends_on it defaults to the previous task. Call again to append tasks.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tasks' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'Short name for the task.'],
                            'brief' => ['type' => 'string', 'description' => 'What to do + what "done" looks like (acceptance criteria).'],
                            'depends_on' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                                'description' => 'Task numbers that must be Done first. [] = independent (runs in parallel). Omit = the previous task.',
                            ],
                        ],
                        'required' => ['title', 'brief'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['tasks'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $jobId = $ctx->jobId();
        $seq = (int) ResearchTask::where('research_job_id', $jobId)->max('seq');

        $added = [];
        foreach ($args->array('tasks') as $t) {
            $title = trim((string) ($t['title'] ?? ''));
            $brief = trim((string) ($t['brief'] ?? ''));
            if ($title === '' || $brief === '') {
                continue;
            }
            $prev = $seq;
            $seq++;

            // Explicit deps win; otherwise default to the previous task (so a plan
            // with no deps is safely sequential). Only keep deps that reference a
            // real earlier task.
            $deps = array_key_exists('depends_on', $t)
                ? array_values(array_unique(array_map('intval', (array) $t['depends_on'])))
                : ($prev >= 1 ? [$prev] : []);
            $deps = array_values(array_filter($deps, fn ($d) => $d >= 1 && $d < $seq));

            ResearchTask::create([
                'research_job_id' => $jobId,
                'seq' => $seq,
                'title' => $title,
                'brief' => $brief,
                'depends_on' => $deps,
            ]);
            $added[] = "#{$seq} {$title}".($deps ? ' (needs '.implode(',', array_map(fn ($d) => "#$d", $deps)).')' : ' (independent)');
        }

        if (empty($added)) {
            return ToolResult::fail('No valid tasks were provided (each needs a title and a brief).');
        }

        return ToolResult::ok(
            'Added '.count($added).' task(s) to the plan:'."\n".implode("\n", $added)."\n\n"
            .'Now delegate_task the first pending one.',
            ['added' => $added]
        );
    }
}
