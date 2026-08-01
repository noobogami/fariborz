<?php

namespace Tests\Feature;

use App\Application\Research\Tools\ToolRegistry;
use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\JobStatus;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Models\CustomTool;
use App\Models\ResearchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SandboxToolsTest extends TestCase
{
    use RefreshDatabase;

    private function ctx(): ResearchContext
    {
        $job = ResearchJob::create(['goal' => 'build', 'status' => JobStatus::Running, 'config' => []]);

        return new ResearchContext($job, 0, $job->goal, [], [], []);
    }

    public function test_run_command_returns_exit_stdout_stderr(): void
    {
        Http::fake(['*/exec' => Http::response([
            'exit_code' => 1, 'stdout' => 'hello', 'stderr' => 'boom', 'duration_ms' => 12, 'timed_out' => false,
        ])]);

        $res = app(ToolRegistry::class)->get('run_command')
            ->execute(new ToolArguments(['command' => 'make test']), $this->ctx());

        $this->assertTrue($res->success);                 // non-zero exit is a result, not a tool failure
        $this->assertSame(1, $res->data['exit_code']);
        $this->assertStringContainsString('boom', $res->observation);
        $this->assertStringContainsString('hello', $res->observation);
    }

    public function test_write_file_reports_bytes(): void
    {
        Http::fake(['*/write' => Http::response(['ok' => true, 'path' => 'a.js', 'bytes' => 5])]);

        $res = app(ToolRegistry::class)->get('write_file')
            ->execute(new ToolArguments(['path' => 'a.js', 'content' => 'x=1;']), $this->ctx());

        $this->assertTrue($res->success);
        $this->assertStringContainsString('a.js', $res->observation);
    }

    public function test_write_file_strips_leaked_prompt_scaffolding(): void
    {
        // The exact failure seen in a real run: the story text with the model's own
        // "CURRENT STATE" block echoed onto the end.
        $dirty = "She was *testing* it.\n\nCURRENT STATE\n=============\nIteration: 3 of max 20\nPending human questions (asked or queued):\n(none)";
        $sent = null;
        Http::fake(function ($request) use (&$sent) {
            $sent = $request->data();

            return Http::response(['ok' => true, 'path' => 'chapters/2.md', 'bytes' => 21]);
        });

        $res = app(ToolRegistry::class)->get('write_file')
            ->execute(new ToolArguments(['path' => 'chapters/2.md', 'content' => $dirty]), $this->ctx());

        $this->assertTrue($res->success);
        $this->assertStringContainsString('stripped', strtolower($res->observation));
        // The file written to the sandbox must be clean — no prompt scaffolding.
        $this->assertStringNotContainsString('CURRENT STATE', $sent['content']);
        $this->assertStringNotContainsString('Iteration:', $sent['content']);
        $this->assertStringContainsString('testing', $sent['content']);
    }

    public function test_supervisor_can_read_but_not_write(): void
    {
        $names = collect(app(ToolRegistry::class)->definitions(null, JobRole::Supervisor))->pluck('name');
        $this->assertContains('read_file', $names, 'supervisor must be able to verify artifacts');
        $this->assertContains('list_files', $names);
        $this->assertNotContains('write_file', $names, 'supervisor still cannot do the work');
        $this->assertNotContains('run_command', $names);
    }

    public function test_start_server_reports_a_listening_server_as_up(): void
    {
        Http::fake(['*/serve' => Http::response([
            'pid' => 808, 'alive' => true, 'port' => 8090, 'listening' => true,
            'url' => 'http://localhost:8090', 'log' => 'server.log', 'log_tail' => 'Server running…',
        ])]);

        $res = app(ToolRegistry::class)->get('start_server')
            ->execute(new ToolArguments(['command' => 'node server.js', 'port' => 8090]), $this->ctx());

        $this->assertTrue($res->success);
        $this->assertStringContainsString('reachable at http://localhost:8090', $res->observation);
    }

    public function test_start_server_refuses_an_unpublished_port(): void
    {
        // A server on :8000 would run inside the container but be unreachable from
        // the host — the tool must refuse before starting it (no HTTP call made).
        Http::fake(['*/serve' => Http::response(['pid' => 1, 'listening' => true, 'url' => 'http://localhost:8000'])]);

        $res = app(ToolRegistry::class)->get('start_server')
            ->execute(new ToolArguments(['command' => 'python3 -m http.server 8000', 'port' => 8000]), $this->ctx());

        $this->assertFalse($res->success);
        $this->assertStringContainsString('not published', strtolower($res->observation));
        $this->assertStringContainsString('8090-8099', $res->observation);
        Http::assertNothingSent();
    }

    public function test_start_server_surfaces_a_crash_instead_of_a_false_success(): void
    {
        // The exact failure mode: require('express') with nothing installed.
        Http::fake(['*/serve' => Http::response([
            'pid' => 810, 'alive' => false, 'port' => 8090, 'listening' => false,
            'url' => null, 'log' => 'server.log',
            'log_tail' => "Error: Cannot find module 'express'\n    at Module._resolveFilename",
        ])]);

        $res = app(ToolRegistry::class)->get('start_server')
            ->execute(new ToolArguments(['command' => 'node server.js', 'port' => 8090]), $this->ctx());

        $this->assertTrue($res->success);
        $this->assertStringContainsString('nothing is listening on port 8090', $res->observation);
        $this->assertStringContainsString("Cannot find module 'express'", $res->observation);   // the crash is visible
    }

    public function test_list_processes_surfaces_running_commands(): void
    {
        Http::fake(['*/ps' => Http::response(['processes' => [
            ['pid' => 812, 'ppid' => 40, 'elapsed_s' => 640, 'cpu' => 3.2, 'mem' => 1.1, 'command' => 'node downloader', 'job' => null],
        ]])]);

        $res = app(ToolRegistry::class)->get('list_processes')
            ->execute(new ToolArguments([]), $this->ctx());

        $this->assertTrue($res->success);
        $this->assertStringContainsString('pid 812', $res->observation);
        $this->assertStringContainsString('640s', $res->observation);   // elapsed time = the tell
    }

    public function test_kill_process_terminates_a_pid(): void
    {
        Http::fake(['*/kill' => Http::response(['ok' => true, 'output' => 'ok'])]);

        $res = app(ToolRegistry::class)->get('kill_process')
            ->execute(new ToolArguments(['pid' => 812]), $this->ctx());

        $this->assertTrue($res->success);
        $this->assertStringContainsString('812', $res->observation);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/kill') && $r['pid'] === 812);
    }

    public function test_agent_can_save_and_run_a_custom_skill(): void
    {
        $ctx = $this->ctx();

        app(ToolRegistry::class)->get('save_custom_tool')->execute(new ToolArguments([
            'name' => 'Count Words', 'description' => 'counts words', 'command' => 'echo {args} | wc -w',
        ]), $ctx);

        $this->assertDatabaseHas('custom_tools', ['name' => 'count_words']);

        Http::fake(['*/exec' => Http::response(['exit_code' => 0, 'stdout' => '3', 'stderr' => '', 'duration_ms' => 4])]);

        $res = app(ToolRegistry::class)->get('run_custom_tool')
            ->execute(new ToolArguments(['name' => 'count_words', 'args' => 'a b c']), $ctx);

        $this->assertTrue($res->success);
        $this->assertSame(0, $res->data['exit_code']);
        $this->assertSame(1, CustomTool::where('name', 'count_words')->first()->run_count);
    }
}
