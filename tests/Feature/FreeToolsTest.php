<?php

namespace Tests\Feature;

use App\Application\Research\Tools\ToolRegistry;
use App\Domain\Research\Enums\JobStatus;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Infrastructure\Research\Tools\BraveSearchTool;
use App\Infrastructure\Research\Tools\TavilySearchTool;
use App\Models\Human;
use App\Models\HumanQuestion;
use App\Models\ResearchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FreeToolsTest extends TestCase
{
    use RefreshDatabase;

    private function context(ResearchJob $job): ResearchContext
    {
        return new ResearchContext($job, 0, $job->goal, [], [], []);
    }

    private function job(): ResearchJob
    {
        return ResearchJob::create(['goal' => 'g', 'status' => JobStatus::Running, 'config' => ['limits' => config('research.limits')]]);
    }

    public function test_wikipedia_tool_returns_a_summary(): void
    {
        Http::fake([
            '*list=search*' => Http::response(['query' => ['search' => [['title' => 'PHP']]]]),
            '*prop=extracts*' => Http::response(['query' => ['pages' => ['123' => ['extract' => 'PHP is a scripting language.']]]]),
        ]);

        $tool = app(ToolRegistry::class)->get('wikipedia');
        $res = $tool->execute(new ToolArguments(['query' => 'php language']), $this->context($this->job()));

        $this->assertTrue($res->success);
        $this->assertStringContainsString('scripting language', $res->observation);
    }

    public function test_stackoverflow_tool_lists_questions(): void
    {
        Http::fake(['*api.stackexchange.com*' => Http::response(['items' => [
            ['title' => 'How to fix X?', 'link' => 'https://stackoverflow.com/q/1', 'score' => 42, 'is_answered' => true, 'answer_count' => 3],
        ]])]);

        $tool = app(ToolRegistry::class)->get('stackoverflow_search');
        $res = $tool->execute(new ToolArguments(['query' => 'fix X']), $this->context($this->job()));

        $this->assertTrue($res->success);
        $this->assertStringContainsString('How to fix X?', $res->observation);
    }

    public function test_ask_human_does_not_create_duplicate_rows(): void
    {
        Human::create(['name' => 'A', 'status' => 'offline', 'expertise' => []]);
        $job = $this->job();
        $tool = app(ToolRegistry::class)->get('ask_human');
        $ctx = $this->context($job);
        $q = ['question' => 'Can you check the ERP for prior purchases?'];

        // Ask the same thing five times (mixed casing/whitespace/punctuation).
        $tool->execute(new ToolArguments($q), $ctx);
        $tool->execute(new ToolArguments(['question' => 'can you check the ERP for prior purchases']), $ctx);
        $tool->execute(new ToolArguments(['question' => '  Can you check the ERP for prior purchases?  ']), $ctx);
        $tool->execute(new ToolArguments($q), $ctx);
        $last = $tool->execute(new ToolArguments($q), $ctx);

        // Exactly ONE row exists, and the agent is told it already asked.
        $this->assertSame(1, HumanQuestion::where('research_job_id', $job->id)->count());
        $this->assertStringContainsString('ALREADY asked', $last->observation);
    }

    public function test_keyed_search_tools_are_absent_without_a_key(): void
    {
        // No TAVILY/BRAVE/SERPAPI keys in the test env → not registered.
        $registry = app(ToolRegistry::class);
        $this->assertFalse($registry->has('tavily_search'));
        $this->assertFalse($registry->has('brave_search'));
        $this->assertFalse($registry->has('google_search'));
    }

    public function test_tavily_tool_parses_answer_and_results(): void
    {
        config(['services.tavily.key' => 'tvly-test']);
        Http::fake(['*api.tavily.com*' => Http::response([
            'answer' => 'ACME makes widgets.',
            'results' => [['title' => 'ACME', 'url' => 'https://acme.example', 'content' => 'ACME Corp…']],
        ])]);

        $res = app(TavilySearchTool::class)
            ->execute(new ToolArguments(['query' => 'acme corp']), $this->context($this->job()));

        $this->assertTrue($res->success);
        $this->assertStringContainsString('ACME makes widgets', $res->observation);
        $this->assertStringContainsString('acme.example', $res->observation);
    }

    public function test_brave_tool_parses_results(): void
    {
        config(['services.brave.key' => 'brave-test']);
        Http::fake(['*api.search.brave.com*' => Http::response([
            'web' => ['results' => [['title' => 'Result', 'url' => 'https://ex.com', 'description' => 'A <b>snippet</b>']]],
        ])]);

        $res = app(BraveSearchTool::class)
            ->execute(new ToolArguments(['query' => 'test']), $this->context($this->job()));

        $this->assertTrue($res->success);
        $this->assertStringContainsString('Result', $res->observation);
        $this->assertStringContainsString('A snippet', $res->observation); // tags stripped
    }

    public function test_ask_human_returns_the_answer_if_already_answered(): void
    {
        $job = $this->job();
        $tool = app(ToolRegistry::class)->get('ask_human');
        $ctx = $this->context($job);

        $tool->execute(new ToolArguments(['question' => 'What is our budget?']), $ctx);
        HumanQuestion::where('research_job_id', $job->id)->update([
            'status' => 'answered', 'answer' => 'The budget is $50k.',
        ]);

        $res = $tool->execute(new ToolArguments(['question' => 'what is our budget']), $ctx);

        $this->assertStringContainsString('$50k', $res->observation);
        $this->assertSame(1, HumanQuestion::where('research_job_id', $job->id)->count());
    }
}
