<?php

namespace Tests\Feature;

use App\Application\Research\ContinueResearch;
use App\Domain\Research\Enums\JobStatus;
use App\Jobs\AdvanceResearchJob;
use App\Models\ResearchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContinueResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_continuing_a_finished_job_reopens_it_with_more_budget(): void
    {
        Queue::fake(); // don't actually run an iteration — assert the re-open only

        $job = ResearchJob::create([
            'goal' => 'g', 'status' => JobStatus::Completed, 'iteration' => 40, 'tool_call_count' => 55,
            'final_report' => 'old', 'confidence' => 0.7, 'finished_at' => now(),
            'config' => ['limits' => ['max_iterations' => 40, 'max_tool_calls' => 60, 'timeout_seconds' => 3600]],
        ]);

        app(ContinueResearch::class)->handle($job->id, 'Focus more on pricing.');

        $job->refresh();
        $this->assertSame(JobStatus::Running, $job->status);          // re-opened
        $this->assertNull($job->finished_at);
        $this->assertSame(60, $job->config['limits']['max_iterations']); // 40 + 20 headroom
        $this->assertSame(85, $job->config['limits']['max_tool_calls']); // 55 + 30

        // Guidance was appended as the latest memory turn.
        $this->assertDatabaseHas('research_messages', ['research_job_id' => $job->id, 'role' => 'human']);
        $last = $job->messages()->orderByDesc('sequence')->first();
        $this->assertStringContainsString('Focus more on pricing', $last->content);

        // A "resumed" event is on the timeline, and the next iteration was queued.
        $this->assertDatabaseHas('research_events', ['research_job_id' => $job->id, 'type' => 'resumed']);
        Queue::assertPushed(AdvanceResearchJob::class);
    }

    public function test_cannot_continue_an_active_job(): void
    {
        $job = ResearchJob::create(['goal' => 'g', 'status' => JobStatus::Running, 'config' => []]);

        $this->postJson("/jobs/{$job->id}/continue", ['guidance' => 'do more'])->assertStatus(409);
    }

    public function test_one_click_retry_reopens_a_failed_job(): void
    {
        Queue::fake();
        $job = ResearchJob::create([
            'goal' => 'g', 'status' => JobStatus::Failed, 'iteration' => 6, 'tool_call_count' => 8,
            'config' => ['limits' => ['max_iterations' => 40, 'max_tool_calls' => 60, 'timeout_seconds' => 3600]],
        ]);

        $this->postJson("/jobs/{$job->id}/retry")->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(JobStatus::Running, $job->refresh()->status);
        Queue::assertPushed(AdvanceResearchJob::class);
    }
}
