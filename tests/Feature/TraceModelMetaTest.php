<?php

namespace Tests\Feature;

use App\Application\Research\Tracing\ResearchTraceReader;
use App\Domain\Research\Enums\EventType;
use App\Domain\Research\Enums\JobStatus;
use App\Models\ResearchEvent;
use App\Models\ResearchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TraceModelMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_thought_turns_surface_model_and_tier_meta(): void
    {
        $job = ResearchJob::create(['goal' => 'g', 'status' => JobStatus::Running, 'config' => []]);

        ResearchEvent::create([
            'research_job_id' => $job->id, 'seq' => 1, 'iteration' => 0,
            'type' => EventType::Thought, 'level' => 'info', 'summary' => 'decided',
            'payload' => ['model' => 'anthropic/claude-3.5-sonnet', 'tier' => 'hard'],
            'occurred_at' => now(),
        ]);
        ResearchEvent::create([
            'research_job_id' => $job->id, 'seq' => 2, 'iteration' => 0,
            'type' => EventType::Observation, 'level' => 'info', 'summary' => 'result',
            'payload' => ['observation' => 'x'],
            'occurred_at' => now(),
        ]);

        // Meta is present even when full payloads are withheld (the default).
        $timeline = app(ResearchTraceReader::class)->build($job->id)['timeline'];

        $thought = collect($timeline)->firstWhere('type', 'thought');
        $this->assertSame(['model' => 'anthropic/claude-3.5-sonnet', 'tier' => 'hard'], $thought['meta']);
        $this->assertNull($thought['payload']);   // full payload still hidden

        // Non-decision events carry no model meta.
        $obs = collect($timeline)->firstWhere('type', 'observation');
        $this->assertNull($obs['meta']);
    }
}
