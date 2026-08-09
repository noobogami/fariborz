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
            .'"brief" (what to do + what "done" looks like), "outputs": the file path(s) the '
            .'task WRITES into the shared workspace, "inputs": the file path(s) it must READ '
            .'(anything an earlier task produces that this task builds on, continues from, or '
            .'must stay consistent with — e.g. the outline for every chapter), and '
            .'"depends_on": the task numbers that must be FINISHED & VERIFIED before it can '
            .'start (e.g. chapter 2 depends_on [1]). Dependencies are also derived from '
            .'"inputs", so a task that reads another task\'s file is ordered after it '
            .'automatically. Leave depends_on EMPTY [] and inputs EMPTY [] only for a truly '
            .'independent task that needs NOTHING from any other task (e.g. a web UI shell that '
            .'does not need the chapters) — those run in parallel. If you omit depends_on it '
            .'defaults to the previous task. HONOR THE ORDERING the user demanded: if they said '
            .'deploy the UI first, that task must run early (empty deps), not last. Call again '
            .'to append tasks.';
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
                            'outputs' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'File path(s) this task writes into the shared workspace (e.g. ["notes.md"]). A dependent task is told to read these.',
                            ],
                            'inputs' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'File path(s) this task must READ — anything an earlier task writes that this one builds on or must stay consistent with (e.g. ["outline.md"] for every chapter). The task is automatically ordered AFTER whichever task produces each file.',
                            ],
                            'tier' => [
                                'type' => 'string',
                                'description' => 'How hard THIS task is, which picks the model that runs it. One of: '
                                    .implode(', ', $this->tierNames()).'. '.$this->tierHelp()
                                    .' Omit for the default ('.$this->defaultTier().').',
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
        $existing = ResearchTask::where('research_job_id', $jobId)->orderBy('seq')->get();
        $seq = (int) $existing->max('seq');

        $rows = array_values(array_filter($args->array('tasks'), fn ($t) => is_array($t)
            && trim((string) ($t['title'] ?? '')) !== ''
            && trim((string) ($t['brief'] ?? '')) !== ''));

        // Did the model express ANY ordering at all — here or in an earlier batch?
        // A plan where nothing depends on anything is the weak-model failure mode
        // (every chapter starting before the outline exists), not a real finding,
        // so when there is no signal whatsoever we fall back to a sequential chain.
        $declaresOrder = $existing->contains(fn (ResearchTask $t) => ! empty($t->depends_on))
            || collect($rows)->contains(fn ($t) => ! empty($t['depends_on']) || ! empty($t['inputs']));

        // basename => the seq that WRITES it, so "task X reads foo.md" becomes a
        // real edge to whoever produces foo.md.
        $producers = [];
        foreach ($existing as $t) {
            foreach ((array) ($t->outputs ?? []) as $p) {
                $producers[$this->fileKey($p)] = (int) $t->seq;
            }
        }
        $nextSeq = $seq;
        foreach ($rows as $t) {
            $nextSeq++;
            foreach ((array) ($t['outputs'] ?? []) as $p) {
                $producers[$this->fileKey($p)] ??= $nextSeq;
            }
        }

        $added = [];
        foreach ($rows as $t) {
            $title = trim((string) $t['title']);
            $brief = trim((string) $t['brief']);
            $prev = $seq;
            $seq++;

            // Explicit deps win; otherwise default to the previous task (so a plan
            // with no deps is safely sequential).
            $deps = array_key_exists('depends_on', $t)
                ? array_map('intval', (array) $t['depends_on'])
                : ($prev >= 1 ? [$prev] : []);

            // Wire producer → consumer from the files this task reads. `inputs` is
            // the declared version; file paths named in the brief catch the rest
            // ("verify /app/chapter1.md through chapter5.md"). Both only ever point
            // BACKWARD, so the graph stays a DAG.
            foreach ($this->referencedFiles($t, $brief) as $key) {
                $producer = $producers[$key] ?? null;
                if ($producer !== null && $producer < $seq) {
                    $deps[] = $producer;
                }
            }

            // No ordering anywhere in the plan → treat "independent" as unstated.
            if (empty($deps) && ! $declaresOrder && $prev >= 1) {
                $deps[] = $prev;
            }

            // Only keep deps that reference a real earlier task.
            $deps = array_values(array_unique(array_filter($deps, fn ($d) => $d >= 1 && $d < $seq)));
            sort($deps);

            $outputs = array_values(array_filter(
                array_map(fn ($p) => trim((string) $p), (array) ($t['outputs'] ?? [])),
                fn ($p) => $p !== ''
            ));

            // Keep a tier only if it names a configured one; otherwise leave it
            // null so the worker falls back to research.llm.default_tier.
            $tier = trim((string) ($t['tier'] ?? ''));
            $tier = isset($this->tiers()[$tier]) ? $tier : null;

            ResearchTask::create([
                'research_job_id' => $jobId,
                'seq' => $seq,
                'title' => $title,
                'brief' => $brief,
                'depends_on' => $deps,
                'outputs' => $outputs ?: null,
                'tier' => $tier,
            ]);
            $added[] = "#{$seq} {$title}".($deps ? ' (needs '.implode(',', array_map(fn ($d) => "#$d", $deps)).')' : ' (independent)');
        }

        if (empty($added)) {
            return ToolResult::fail('No valid tasks were provided (each needs a title and a brief).');
        }

        return ToolResult::ok(
            'Added '.count($added).' task(s) to the plan:'."\n".implode("\n", $added)."\n\n"
            .'Delegation is automatic — every ready task (its deps Done) is started for you. '
            .'Your job now is to review each finished task and finish when all are Done.',
            ['added' => $added]
        );
    }

    /**
     * The files a task reads: its declared "inputs" plus any file path written out
     * in the brief. Returned as comparison keys so "/app/outline.md", "outline.md"
     * and "./Outline.MD" all match the task that wrote it.
     *
     * @param  array<string, mixed>  $t
     * @return list<string>
     */
    private function referencedFiles(array $t, string $brief): array
    {
        $paths = array_map(fn ($p) => (string) $p, (array) ($t['inputs'] ?? []));

        preg_match_all('~[\w./\\\\-]+\.[A-Za-z0-9]{1,6}\b~', $brief, $m);
        $paths = array_merge($paths, $m[0]);

        $keys = array_filter(array_map(fn ($p) => $this->fileKey($p), $paths), fn ($k) => $k !== '');

        return array_values(array_unique($keys));
    }

    /** A path's comparison key: its lowercased basename. */
    private function fileKey(string $path): string
    {
        return mb_strtolower(trim(basename(str_replace('\\', '/', trim($path))), " \t\n\r\0\x0B.,;:\"')"));
    }

    /** @return array<string, array{model?:string, hint?:string}> */
    private function tiers(): array
    {
        return (array) config('research.llm.tiers', []);
    }

    /** @return list<string> */
    private function tierNames(): array
    {
        return array_keys($this->tiers());
    }

    private function defaultTier(): string
    {
        return (string) config('research.llm.default_tier', 'standard');
    }

    /** A one-line "name = when to use it" guide built from the configured tiers. */
    private function tierHelp(): string
    {
        $parts = [];
        foreach ($this->tiers() as $name => $meta) {
            $parts[] = $name.' = '.trim((string) ($meta['hint'] ?? ''));
        }

        return implode('  ', $parts);
    }
}
