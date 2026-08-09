<?php

namespace App\Application\Research\Sandbox;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * HTTP client for the code sandbox service (services/sandbox). Every call is
 * scoped to a research job's workspace so builds never collide, and nothing
 * ever runs on the host — only inside the sandbox container.
 */
class SandboxClient
{
    public function __construct(private Http $http) {}

    private function baseUrl(): string
    {
        return rtrim((string) config('research.sandbox.base_url'), '/');
    }

    public function status(): array
    {
        try {
            $res = $this->http->timeout(4)->get($this->baseUrl().'/health');

            return ['reachable' => $res->successful(), 'base_url' => $this->baseUrl()];
        } catch (Throwable $e) {
            return ['reachable' => false, 'base_url' => $this->baseUrl(), 'error' => $e->getMessage()];
        }
    }

    /** Environment self-diagnosis (permissions, published port, toolchains, listeners). */
    public function info(string $job): array
    {
        return $this->get('/info', ['job' => $job]);
    }

    /** Running background servers the agent started (pid, port, command, job). */
    public function processes(): array
    {
        return $this->get('/processes', []);
    }

    /** All running processes, longest-running first (pid, elapsed, cpu, command). */
    public function ps(): array
    {
        return $this->get('/ps', []);
    }

    /** Terminate a process by pid. */
    public function kill(int $pid): array
    {
        return $this->post('/kill', ['pid' => $pid]);
    }

    /**
     * Start a long-lived server (detached; survives the call). Returns whether
     * the port is actually listening + a log tail, so a crash is visible.
     *
     * @return array{pid:int,alive:bool,port:?int,listening:?bool,url:?string,log:string,log_tail:string}
     */
    public function serve(string $job, string $cmd, ?int $port = null, string $cwd = '.'): array
    {
        return $this->post('/serve', array_filter([
            'job' => $job,
            'cmd' => $cmd,
            'port' => $port,
            'cwd' => $cwd,
        ], fn ($v) => $v !== null));
    }

    /** @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int,killed:bool,timed_out:bool} */
    public function exec(string $job, string $cmd, string $cwd = '.', ?int $timeout = null): array
    {
        return $this->post('/exec', [
            'job' => $job,
            'cmd' => $cmd,
            'cwd' => $cwd,
            'timeout' => $timeout ?? (int) config('research.sandbox.command_timeout', 180),
        ]);
    }

    public function write(string $job, string $path, string $content): array
    {
        return $this->post('/write', ['job' => $job, 'path' => $path, 'content' => $content]);
    }

    public function read(string $job, string $path): array
    {
        return $this->get('/read', ['job' => $job, 'path' => $path]);
    }

    public function list(string $job, string $path = '.'): array
    {
        return $this->get('/list', ['job' => $job, 'path' => $path]);
    }

    public function logs(string $job): array
    {
        return $this->get('/logs', ['job' => $job]);
    }

    /**
     * Raw bytes of one file in a job's workspace, with the content type the
     * sandbox inferred — enough to render a static page in a browser. Unlike
     * read(), this doesn't decode to JSON or truncate, so images and fonts
     * survive. A directory resolves to its index.html.
     *
     * Returns the sandbox's own status rather than throwing: a missing file is
     * an ordinary 404 to hand back to the browser, not a client failure.
     *
     * @return array{status:int,content_type:string,body:string}
     */
    public function preview(string $job, string $path = ''): array
    {
        $segments = array_map('rawurlencode', array_filter(explode('/', $path), fn ($s) => $s !== ''));

        $res = $this->http->timeout((int) config('research.sandbox.request_timeout', 300))
            ->withOptions(['allow_redirects' => false])
            ->get($this->baseUrl().'/preview/'.rawurlencode($job).'/'.implode('/', $segments));

        return [
            'status' => $res->status(),
            'content_type' => $res->header('Content-Type') ?: 'application/octet-stream',
            'body' => $res->body(),
        ];
    }

    private function post(string $path, array $body): array
    {
        $res = $this->http->timeout((int) config('research.sandbox.request_timeout', 300))
            ->asJson()->post($this->baseUrl().$path, $body);

        if ($res->failed()) {
            throw new SandboxException($res->json('error') ?? "sandbox returned HTTP {$res->status()}");
        }

        return $res->json() ?? [];
    }

    private function get(string $path, array $query): array
    {
        $res = $this->http->timeout((int) config('research.sandbox.request_timeout', 300))
            ->get($this->baseUrl().$path, $query);

        if ($res->failed()) {
            throw new SandboxException($res->json('error') ?? "sandbox returned HTTP {$res->status()}");
        }

        return $res->json() ?? [];
    }
}
