<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SandboxControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sandbox_page_renders_status_and_env(): void
    {
        Http::fake([
            '*/health' => Http::response(['ok' => true]),
            '*/info' => Http::response([
                'is_root' => true,
                'published_app_ports' => '8090-8099',
                'free_app_ports' => [8091, 8092],
                'in_use_app_ports' => [8090],
                'diagnostics' => 'node v20',
            ]),
        ]);

        $this->get('/sandbox')
            ->assertOk()
            ->assertSee('8090-8099')
            ->assertSee('Running processes');
    }

    public function test_processes_endpoint_returns_running_servers(): void
    {
        Http::fake(['*/processes' => Http::response([
            'processes' => [
                ['pid' => 34, 'port' => 8090, 'command' => 'python3 -m http.server', 'job' => 'abc'],
            ],
        ])]);

        $this->getJson('/ui/api/sandbox/processes')
            ->assertOk()
            ->assertJsonPath('processes.0.pid', 34)
            ->assertJsonPath('processes.0.port', 8090);
    }

    public function test_kill_requires_a_valid_pid(): void
    {
        $this->postJson('/sandbox/kill', ['pid' => 1])->assertStatus(422);
    }

    public function test_kill_forwards_to_sandbox(): void
    {
        Http::fake(['*/kill' => Http::response(['ok' => true, 'output' => 'ok'])]);

        $this->postJson('/sandbox/kill', ['pid' => 34])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/kill') && $r['pid'] === 34);
    }

    public function test_console_exec_forwards_command_and_returns_output(): void
    {
        Http::fake(['*/exec' => Http::response([
            'exit_code' => 0, 'stdout' => 'hi', 'stderr' => '', 'timed_out' => false,
        ])]);

        $this->postJson('/sandbox/exec', ['job' => 'console', 'cmd' => 'echo hi', 'cwd' => '.'])
            ->assertOk()
            ->assertJsonPath('stdout', 'hi');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/exec')
            && $r['job'] === 'console' && $r['cmd'] === 'echo hi');
    }

    public function test_console_exec_requires_job_and_cmd(): void
    {
        $this->postJson('/sandbox/exec', ['cmd' => 'echo hi'])->assertStatus(422);
        $this->postJson('/sandbox/exec', ['job' => 'console'])->assertStatus(422);
    }
}
