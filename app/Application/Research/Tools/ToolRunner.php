<?php

namespace App\Application\Research\Tools;

use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use App\Models\ToolExecution;
use Throwable;

/**
 * Executes a tool with:
 *   - a durable tool_executions row (pending → running → success/failed)
 *   - retries with exponential backoff for transient failures
 *   - full tracing of start / retry / success / failure
 *
 * A failure NEVER throws out of here — it returns ToolResult::fail(), which the
 * orchestrator feeds back to the LLM as an observation so research continues.
 */
class ToolRunner
{
    public function __construct(private TraceRecorder $trace) {}

    public function run(Tool $tool, ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $execution = ToolExecution::create([
            'research_job_id' => $ctx->jobId(),
            'tool_name' => $tool->name(),
            'arguments' => $args->all(),
            'fingerprint' => ToolExecution::fingerprint($tool->name(), $args->all()),
            'status' => 'running',
        ]);

        $this->trace->record($ctx->job, EventType::ToolStarted,
            "Running {$tool->name()}", ['arguments' => $args->all(), 'execution_id' => $execution->id]);

        $maxAttempts = (int) config('research.retries.tool_attempts', 3);
        $started = (int) (microtime(true) * 1000);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $result = $tool->execute($args, $ctx);
                $elapsed = (int) (microtime(true) * 1000) - $started;

                $execution->update([
                    'status' => $result->success ? 'success' : 'failed',
                    'observation' => $result->observation,
                    'result' => $result->data ?: null,
                    'error' => $result->error,
                    'attempts' => $attempt,
                    'duration_ms' => $elapsed,
                ]);

                $this->trace->record($ctx->job, EventType::ToolSucceeded,
                    $this->successSummary($tool, $result),
                    ['execution_id' => $execution->id, 'observation' => $result->observation, 'data' => $result->data, 'deferred' => $result->deferred],
                    $elapsed);

                return $result;
            } catch (RetryableToolException $e) {
                if ($attempt < $maxAttempts) {
                    $this->backoff($attempt);
                    $this->trace->record($ctx->job, EventType::ToolRetried,
                        "{$tool->name()} failed (attempt {$attempt}/{$maxAttempts}), retrying: {$e->getMessage()}",
                        ['execution_id' => $execution->id, 'attempt' => $attempt]);

                    continue;
                }

                return $this->fail($tool, $execution, $ctx, $e, $attempt, $started);
            } catch (Throwable $e) {
                // Non-retryable: stop immediately.
                return $this->fail($tool, $execution, $ctx, $e, $attempt, $started);
            }
        }

        // Unreachable, but keeps the type checker happy.
        return ToolResult::fail('Exhausted retries.');
    }

    private function fail(Tool $tool, ToolExecution $execution, ResearchContext $ctx, Throwable $e, int $attempt, int $started): ToolResult
    {
        $elapsed = (int) (microtime(true) * 1000) - $started;

        $execution->update([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'attempts' => $attempt,
            'duration_ms' => $elapsed,
        ]);

        report($e); // still surface to Sentry/logs for engineers

        $this->trace->record($ctx->job, EventType::ToolFailed,
            "{$tool->name()} failed: {$e->getMessage()}",
            ['execution_id' => $execution->id, 'exception' => $e::class, 'attempts' => $attempt],
            $elapsed);

        return ToolResult::fail($e->getMessage());
    }

    private function successSummary(Tool $tool, ToolResult $result): string
    {
        $verb = $result->deferred ? 'deferred' : 'returned';

        return "{$tool->name()} {$verb}: ".$this->firstLine($result->observation);
    }

    private function firstLine(string $s): string
    {
        $line = trim(strtok($s, "\n") ?: $s);

        return mb_strlen($line) > 160 ? mb_substr($line, 0, 160).'…' : $line;
    }

    private function backoff(int $attempt): void
    {
        $base = (int) config('research.retries.tool_backoff_base_ms', 1000);
        $max = (int) config('research.retries.tool_backoff_max_ms', 15000);
        usleep(min($base * (2 ** ($attempt - 1)), $max) * 1000);
    }
}
