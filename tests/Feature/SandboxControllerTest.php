<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SandboxControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sandbox_page_renders_status_and_env(): void
    {
        Http::fake([
            '*/health' => Http::response(['ok' => true]),
            '*/info*' => Http::response([
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
            ->assertSee('Running processes')
            // The offline veil ships on every render but must start hidden and
            // must not dim/clip the page when the sandbox is up.
            ->assertDontSee('class="sb-dim"', false);
    }

    public function test_sandbox_page_renders_offline_instead_of_500_when_the_container_is_down(): void
    {
        // Connection refused (container not running) — a transport error, not an
        // HTTP error response. The page must degrade, not blow up.
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $this->get('/sandbox')
            ->assertOk()
            ->assertSee('ENVIRONMENT UNREACHABLE')
            ->assertSee('docker compose up -d sandbox')
            // Full-section veil, dimmed+clipped body underneath — and not cloaked,
            // so it paints before Alpine boots rather than flashing a dead UI.
            ->assertSee('Sandbox is offline')
            ->assertSee('class="sb-veil" x-show="offline" >', false)
            ->assertSee('class="sb-dim"', false);

        // No point probing /info when /health already said it's down.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/info'));
    }

    public function test_processes_endpoint_reports_offline_when_the_container_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $res = $this->getJson('/ui/api/sandbox/processes')->assertOk();

        $this->assertSame([], $res->json('processes'));
        $this->assertFalse($res->json('reachable'));   // drives the live veil
        $this->assertStringContainsString('offline', $res->json('error'));
    }

    public function test_processes_endpoint_separates_a_sandbox_error_from_an_offline_sandbox(): void
    {
        // The sandbox answered, it just failed — that's a message, not a reason
        // to veil the whole page.
        Http::fake(['*/processes*' => Http::response(['error' => 'boom'], 500)]);

        $res = $this->getJson('/ui/api/sandbox/processes')->assertOk();

        $this->assertTrue($res->json('reachable'));
        $this->assertSame('boom', $res->json('error'));
    }

    public function test_console_exec_reports_offline_when_the_container_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $res = $this->postJson('/sandbox/exec', ['job' => 'console', 'cmd' => 'echo hi'])
            ->assertStatus(502);

        $this->assertStringContainsString('offline', $res->json('error'));
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
            ->assertJsonPath('processes.0.port', 8090)
            ->assertJsonPath('reachable', true);
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

    /**
     * A directory URL — the common case — only resolves to index.html WITH the
     * trailing slash, and the test client's prepareUrlForRequest() trims it off
     * every uri it's given. So drive the kernel directly to keep the slash.
     */
    private function getKeepingTrailingSlash(string $uri): Response
    {
        return $this->app->make(HttpKernel::class)->handle(Request::create($uri, 'GET'));
    }

    public function test_preview_serves_a_workspace_file_with_its_own_content_type(): void
    {
        Http::fake(['*/preview/*' => Http::response('<h1>Coming Soon</h1>', 200, ['Content-Type' => 'text/html'])]);

        $res = $this->getKeepingTrailingSlash('/sandbox/preview/my-job/');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('<h1>Coming Soon</h1>', $res->getContent());
        $this->assertStringContainsString('text/html', $res->headers->get('Content-Type'));
        // Agent-written HTML is untrusted and this route is same-origin with the
        // dashboard — the opaque origin is what keeps it away from /sandbox/exec.
        $this->assertSame('sandbox allow-scripts', $res->headers->get('Content-Security-Policy'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/preview/my-job/'));
    }

    public function test_preview_forwards_a_nested_asset_path(): void
    {
        Http::fake(['*/preview/*' => Http::response('body{}', 200, ['Content-Type' => 'text/css'])]);

        $this->get('/sandbox/preview/my-job/assets/style.css')->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/preview/my-job/assets/style.css'));
    }

    public function test_preview_without_a_trailing_slash_redirects_to_one(): void
    {
        // Relative assets in the page would otherwise resolve a directory too
        // high — and the redirect must not point back at itself.
        $this->get('/sandbox/preview/my-job')
            ->assertRedirect('/sandbox/preview/my-job/');
    }

    public function test_preview_explains_itself_when_the_workspace_has_no_index(): void
    {
        Http::fake(['*/preview/*' => Http::response(['error' => 'not found in this workspace'], 404)]);

        $res = $this->getKeepingTrailingSlash('/sandbox/preview/my-job/');

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('index.html', $res->getContent());
        $this->assertStringContainsString('Open workspace console', $res->getContent());
    }

    public function test_preview_reports_an_offline_sandbox_instead_of_500(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $this->get('/sandbox/preview/my-job/index.html')
            ->assertStatus(502)
            ->assertSee('docker compose up -d sandbox');
    }
}
