<?php

namespace Tests\Feature;

use App\Domain\Research\Contracts\LlmClient;
use App\Domain\Research\Enums\JobStatus;
use App\Models\Human;
use App\Models\HumanQuestion;
use App\Models\ResearchEvent;
use App\Models\ResearchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeLlmClient;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No real Ollama during tests — pretend it's reachable with a model.
        Http::fake([
            '*/api/version' => Http::response(['version' => '0.1.0']),
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:30b', 'size' => 19000000000, 'details' => ['parameter_size' => '30B', 'quantization_level' => 'Q4']]]]),
            '*/api/ps' => Http::response(['models' => []]),
            '*/health' => Http::response(['ok' => true, 'service' => 'playwright-browser']),
            '*/search' => Http::response(['query' => 'x', 'engine' => 'duckduckgo', 'results' => [['title' => 'Result', 'url' => 'https://example.com', 'snippet' => 's']]]),
            '*' => Http::response(['ok' => true]), // catch-all: no real network in tests
        ]);
    }

    public function test_dashboard_index_renders(): void
    {
        $this->get('/')->assertOk()->assertSee('Research Jobs');
    }

    public function test_job_detail_renders_with_goal_and_timeline(): void
    {
        $job = ResearchJob::create([
            'goal' => 'Evaluate supplier X', 'status' => JobStatus::Running, 'config' => ['limits' => config('research.limits')],
        ]);

        $this->get("/jobs/{$job->id}")->assertOk()->assertSee('Evaluate supplier X');
        $this->getJson("/ui/api/jobs/{$job->id}")->assertOk()->assertJsonStructure(['job', 'stats', 'timeline', 'questions']);
    }

    public function test_humans_page_renders(): void
    {
        Human::create(['name' => 'Father', 'status' => 'available', 'expertise' => null]);
        $this->get('/humans')->assertOk()->assertSee('Father');
    }

    public function test_humans_page_hides_questions_from_finished_jobs(): void
    {
        Human::create(['name' => 'Father', 'status' => 'available', 'expertise' => null]);

        $active = ResearchJob::create(['goal' => 'active job', 'status' => JobStatus::Running, 'config' => []]);
        $done = ResearchJob::create(['goal' => 'done job', 'status' => JobStatus::Completed, 'config' => []]);

        HumanQuestion::create(['research_job_id' => $active->id, 'question' => 'ACTIVE question here', 'status' => 'queued']);
        HumanQuestion::create(['research_job_id' => $done->id, 'question' => 'DONE question here', 'status' => 'queued']);

        $this->get('/humans')->assertOk()
            ->assertSee('ACTIVE question here')
            ->assertDontSee('DONE question here');
    }

    public function test_tools_and_ollama_render_under_settings(): void
    {
        // Tools & Ollama is now a tab inside Settings; its markup renders on the page.
        $this->get('/settings')->assertOk()
            ->assertSee('Ollama')
            ->assertSee('Browser')          // Playwright service card
            ->assertSee('browser_search')   // keyless search tool
            ->assertSee('wikipedia')        // a registered keyless tool
            ->assertSee('qwen3:30b');       // faked installed model
    }

    public function test_old_tools_url_redirects_to_settings(): void
    {
        $this->get('/tools')->assertRedirect(route('settings').'#tools');
    }

    public function test_browser_search_test_endpoint_returns_results(): void
    {
        $this->postJson('/tools/browser/test', ['query' => 'company x revenue'])
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonStructure(['ok', 'results' => [['title', 'url', 'snippet']]]);
    }

    public function test_can_delete_a_job_and_its_history_cascades(): void
    {
        $job = ResearchJob::create(['goal' => 'to be deleted', 'status' => JobStatus::Completed, 'config' => []]);
        ResearchEvent::create([
            'research_job_id' => $job->id, 'seq' => 1, 'iteration' => 0,
            'type' => 'job_started', 'level' => 'info', 'summary' => 'x', 'occurred_at' => now(),
        ]);
        HumanQuestion::create(['research_job_id' => $job->id, 'question' => 'q', 'status' => 'queued']);

        $this->delete("/jobs/{$job->id}")->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('research_jobs', ['id' => $job->id]);
        $this->assertDatabaseMissing('research_events', ['research_job_id' => $job->id]);
        $this->assertDatabaseMissing('human_questions', ['research_job_id' => $job->id]);
    }

    public function test_running_job_cannot_be_deleted_until_cancelled(): void
    {
        $job = ResearchJob::create(['goal' => 'busy', 'status' => JobStatus::Running, 'config' => []]);

        // Blocked while running (409 for JSON callers).
        $this->deleteJson("/jobs/{$job->id}")->assertStatus(409);
        $this->assertDatabaseHas('research_jobs', ['id' => $job->id]);

        // After cancelling, it deletes.
        $job->update(['status' => JobStatus::Cancelled]);
        $this->deleteJson("/jobs/{$job->id}")->assertOk();
        $this->assertDatabaseMissing('research_jobs', ['id' => $job->id]);
    }

    public function test_can_start_a_job_from_the_form(): void
    {
        // Deterministic brain: the job immediately finishes, no LLM network call.
        $this->app->instance(LlmClient::class, new FakeLlmClient([
            ['action' => 'finish', 'report' => 'done', 'confidence' => 0.9],
        ]));

        $res = $this->post('/jobs', ['goal' => 'Find the revenue of ACME Corp']);
        $res->assertRedirect();
        $this->assertDatabaseHas('research_jobs', ['goal' => 'Find the revenue of ACME Corp']);
    }
}
