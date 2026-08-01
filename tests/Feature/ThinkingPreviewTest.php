<?php

namespace Tests\Feature;

use App\Application\Research\Planner\LlmPlanner;
use App\Domain\Research\Enums\JobStatus;
use App\Models\ResearchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class ThinkingPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['ok' => true])]);
    }

    private function thinkingJob(int $sinceSeconds): ResearchJob
    {
        $job = ResearchJob::create(['goal' => 'g', 'status' => JobStatus::Running, 'config' => []]);
        $job->forceFill([
            'current_activity' => 'thinking',
            'activity_updated_at' => now()->subSeconds($sinceSeconds),
        ])->save();

        Cache::put('research:worker:last_seen', time(), 300);   // worker alive

        return $job;
    }

    public function test_reasoning_is_shown_as_soon_as_the_model_emits_it(): void
    {
        // No time gate: even a few seconds in, a fresh preview is surfaced.
        $job = $this->thinkingJob(3);
        Cache::put("research:llm:think:{$job->id}", ['text' => 'user wants pricing so I need to search', 'at' => time()], 300);

        $this->getJson("/ui/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('activity.phase', 'thinking')
            ->assertJsonPath('activity.thinking_preview', 'user wants pricing so I need to search');
    }

    public function test_raw_reasoning_is_shown_when_no_structured_thought_yet(): void
    {
        // A reasoning model streaming chain-of-thought (no parsed `thought` field
        // yet) still gets narrated via the `raw` fallback.
        $job = $this->thinkingJob(5);
        Cache::put("research:llm:think:{$job->id}", ['text' => '', 'raw' => 'let me weigh the two sources', 'at' => time()], 300);

        $this->getJson("/ui/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('activity.thinking_preview', 'let me weigh the two sources');
    }

    public function test_structured_thought_wins_over_raw(): void
    {
        $job = $this->thinkingJob(5);
        Cache::put("research:llm:think:{$job->id}", ['text' => 'clean thought', 'raw' => 'noisy fallback', 'at' => time()], 300);

        $this->getJson("/ui/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('activity.thinking_preview', 'clean thought');
    }

    public function test_nothing_is_shown_when_the_model_emits_no_text(): void
    {
        $job = $this->thinkingJob(120);   // long think, but no preview text produced

        $this->getJson("/ui/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('activity.thinking_preview', null);
    }

    public function test_stale_preview_is_not_shown(): void
    {
        // A preview from an earlier think that stopped streaming must not linger.
        $job = $this->thinkingJob(30);
        Cache::put("research:llm:think:{$job->id}", ['text' => 'from a finished think', 'at' => time() - 30], 300);

        $this->getJson("/ui/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('activity.thinking_preview', null);
    }

    public function test_active_streaming_is_not_flagged_as_stalled(): void
    {
        $job = $this->thinkingJob(240);   // >210s, would normally read as "stuck"…
        Cache::put("research:llm:think:{$job->id}", ['text' => 'still working through the sources', 'at' => time()], 300);

        $this->getJson("/ui/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('activity.phase', 'thinking');   // …but a fresh preview proves it's alive
    }

    #[DataProvider('partialJson')]
    public function test_partial_thought_extraction(string $partial, string $expected): void
    {
        $m = new ReflectionMethod(LlmPlanner::class, 'partialThought');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke(app(LlmPlanner::class), $partial));
    }

    public static function partialJson(): array
    {
        return [
            'nothing yet' => ['{', ''],
            'key only' => ['{"thought"', ''],
            'mid-value (still streaming)' => ['{"thought": "I should search for', 'I should search for'],
            'escaped quote & newline' => ['{"thought": "line one\nsaid \"hi\"', "line one\nsaid \"hi\""],
            'complete thought' => ['{"thought": "done reasoning", "action": "tool"}', 'done reasoning'],
        ];
    }
}
