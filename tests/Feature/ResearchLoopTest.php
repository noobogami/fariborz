<?php

namespace Tests\Feature;

use App\Application\Research\StartResearch;
use App\Domain\Research\Contracts\LlmClient;
use App\Domain\Research\Enums\JobStatus;
use App\Models\ResearchEvent;
use App\Models\ResearchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeLlmClient;
use Tests\TestCase;

class ResearchLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_uses_a_tool_then_finishes_and_records_a_full_trace(): void
    {
        // The agent will: run one calculation, then finish.
        $this->app->instance(LlmClient::class, new FakeLlmClient([
            ['thought' => 'I should compute the ratio.', 'action' => 'tool', 'tool' => 'calculator', 'arguments' => ['expression' => '1200000/350']],
            ['thought' => 'I have what I need.', 'action' => 'finish', 'report' => 'The ratio is ~3428.', 'confidence' => 0.95],
        ]));

        // QUEUE_CONNECTION=sync means each AdvanceResearchJob re-dispatch runs
        // inline, so the whole loop completes within this test.
        $job = app(StartResearch::class)->handle('Compute the revenue-per-employee ratio.');

        $job = ResearchJob::find($job->id);
        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertStringContainsString('3428', $job->final_report);
        $this->assertSame(0.95, $job->confidence);

        // The trace tells the story, in order.
        $timeline = ResearchEvent::where('research_job_id', $job->id)
            ->orderBy('seq')
            ->pluck('type')
            ->map(fn ($t) => $t->value)   // column is cast to the EventType enum
            ->all();

        $this->assertSame('job_started', $timeline[0]);
        $this->assertContains('tool_selected', $timeline);
        $this->assertContains('tool_succeeded', $timeline);
        $this->assertContains('observation', $timeline);
        $this->assertSame('finished', end($timeline));

        // The observation event carries the FULL reasoning + result in its payload,
        // so the detail popup can show everything even though the flow card is truncated.
        $obs = ResearchEvent::where('research_job_id', $job->id)->where('type', 'observation')->first();
        $this->assertSame('I should compute the ratio.', $obs->payload['thought']);
        $this->assertStringContainsString('3428', $obs->payload['observation']);
    }

    public function test_duplicate_search_is_blocked_and_fed_back(): void
    {
        Http::fake([
            '*/search' => Http::response(['results' => [
                ['title' => 'X', 'snippet' => 'rev 1.2M', 'url' => 'http://x'],
            ]]),
        ]);

        // Same web search twice, then finish. The second identical call must
        // be intercepted by the DuplicateActionGuardrail.
        $this->app->instance(LlmClient::class, new FakeLlmClient([
            ['action' => 'tool', 'tool' => 'browser_search', 'arguments' => ['query' => 'Company X revenue']],
            ['action' => 'tool', 'tool' => 'browser_search', 'arguments' => ['query' => 'Company X revenue']],
            ['action' => 'finish', 'report' => 'Revenue ~1.2M.', 'confidence' => 0.8],
        ]));

        $job = app(StartResearch::class)->handle('Find Company X revenue.');

        $this->assertDatabaseHas('research_events', [
            'research_job_id' => $job->id,
            'type' => 'guardrail_triggered',
        ]);
        $this->assertSame(JobStatus::Completed, ResearchJob::find($job->id)->status);
    }
}
