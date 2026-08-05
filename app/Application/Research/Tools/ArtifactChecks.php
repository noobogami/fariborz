<?php

namespace App\Application\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Models\ResearchTask;
use Throwable;

/**
 * The deterministic, code-only half of task review: does a task's DECLARED
 * output really exist in the shared workspace with real content? This is the
 * asymmetric pre-gate — it may only REJECT (fail-fast → revise), never ACCEPT,
 * because "done" has a quality dimension no deterministic check captures. Every
 * real acceptance must go through a judgment call (a Reviewer agent, or the
 * supervisor's own review_task as a fallback) — this class only ever narrows
 * what they have to think about.
 *
 * Shared by ReviewTaskTool (the supervisor's review_task) and
 * ResearchOrchestrator::autoDispatchReviews (the deterministic pre-gate before a
 * Reviewer agent is even spawned), so the check is defined exactly once.
 */
class ArtifactChecks
{
    /** Trimmed content shorter than this (chars) counts as "essentially empty". */
    private const MIN_CONTENT_CHARS = 30;

    public function __construct(private SandboxClient $sandbox) {}

    /**
     * Check each declared output path exists in the workspace with real content.
     *
     * Returns ['problems' => string[], 'verified' => string[]]. `problems` is
     * empty when there is nothing blocking acceptance. The check FAILS CLOSED on
     * real evidence (a 404 / empty file is a genuine problem) but FAILS OPEN on
     * infrastructure trouble (sandbox unreachable) so a sandbox outage can never
     * wedge every review — we simply can't verify, so we don't block.
     *
     * @return array{problems: list<string>, verified: list<string>}
     */
    public function verifyOutputs(string $workspace, ResearchTask $task): array
    {
        $paths = array_values(array_filter(
            array_map('trim', (array) ($task->outputs ?? [])),
            fn ($p) => is_string($p) && $p !== '' && $p !== '...'
                // Skip directory markers and glob placeholders — we can only
                // meaningfully read concrete files back.
                && ! str_ends_with($p, '/') && ! str_contains($p, '*'),
        ));

        // No concrete file deliverable declared (e.g. a pure research task) —
        // nothing to verify, so don't stand in the way of acceptance.
        if (! $paths) {
            return ['problems' => [], 'verified' => []];
        }

        $problems = [];
        $verified = [];

        foreach ($paths as $path) {
            try {
                $r = $this->sandbox->read($workspace, $path);
            } catch (SandboxException $e) {
                // The sandbox reachably reported the file isn't there (404) —
                // that's real evidence the deliverable is missing.
                $problems[] = "\"{$path}\" was not found in the workspace (worker never wrote it).";

                continue;
            } catch (Throwable $e) {
                // Sandbox unreachable / transport error — cannot verify. Fail open:
                // abandon the whole check rather than block review on infra trouble.
                return ['problems' => [], 'verified' => []];
            }

            $content = trim((string) ($r['content'] ?? ''));
            if ($content === '') {
                $problems[] = "\"{$path}\" exists but is empty.";
            } elseif (mb_strlen($content) < self::MIN_CONTENT_CHARS) {
                $problems[] = "\"{$path}\" is essentially empty (".mb_strlen($content).' chars).';
            } else {
                $verified[] = $path.' ('.mb_strlen($content).' chars)';
            }
        }

        return ['problems' => $problems, 'verified' => $verified];
    }
}
