<?php

namespace App\Http\Controllers\Dashboard;

use App\Application\Research\Sandbox\SandboxClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SandboxController extends Controller
{
    public function __construct(private SandboxClient $sandbox) {}

    public function index()
    {
        // The sandbox is an optional container — when it's down the page must
        // still render (as "unreachable"), never 500. status() already swallows
        // transport errors; only probe /info when it says the service is up so
        // a dead container can't stall the page on a request timeout either.
        $status = $this->sandbox->status();

        $info = [];
        if ($status['reachable'] ?? false) {
            try {
                $info = $this->sandbox->info('sandbox'); // pool/free ports are job-independent
            } catch (Throwable) {
            }
        }

        return view('dashboard.sandbox', [
            'status' => $status,
            'info' => $info,
        ]);
    }

    /**
     * Live list of running background servers (polled by the page).
     *
     * Also carries `reachable`, which is how the page learns the sandbox died
     * while it was open — it distinguishes "the container is gone" (veil the
     * page) from "the sandbox answered with an error" (just show the message).
     */
    public function processesJson(): JsonResponse
    {
        try {
            return response()->json($this->sandbox->processes() + ['reachable' => true]);
        } catch (ConnectionException $e) {
            return response()->json(['processes' => [], 'reachable' => false, 'error' => $this->reason($e)]);
        } catch (Throwable $e) {
            return response()->json(['processes' => [], 'reachable' => true, 'error' => $e->getMessage()]);
        }
    }

    public function kill(Request $request): JsonResponse
    {
        $data = $request->validate(['pid' => ['required', 'integer', 'min:2']]);

        try {
            $this->sandbox->kill((int) $data['pid']);

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return response()->json(['error' => $this->reason($e)], 502);
        }
    }

    /** Interactive console: run one command in a job's workspace and return its output. */
    public function exec(Request $request): JsonResponse
    {
        $data = $request->validate([
            'job' => ['required', 'string', 'max:64'],
            'cmd' => ['required', 'string', 'max:8000'],
            'cwd' => ['nullable', 'string', 'max:1024'],
        ]);

        try {
            return response()->json(
                $this->sandbox->exec($data['job'], $data['cmd'], ($data['cwd'] ?? '') ?: '.', 120)
            );
        } catch (Throwable $e) {
            return response()->json(['error' => $this->reason($e)], 502);
        }
    }

    /**
     * Serve a job's workspace file straight to the browser, so a job whose whole
     * deliverable is an index.html is viewable without the agent writing a
     * server for it. Proxied through here rather than linking the sandbox's own
     * port because that port is container-internal when the app runs in Docker
     * (research.sandbox.base_url is http://sandbox:3000 there) — the browser
     * can't reach it, but we can.
     *
     * The page is agent-written, i.e. untrusted, and this route is same-origin
     * with the dashboard — so it's served under `CSP: sandbox`, which drops the
     * document into an opaque origin. Its scripts then can't read the dashboard
     * or reach privileged endpoints like /sandbox/exec with the user's session.
     */
    public function preview(Request $request, string $job, string $path = '')
    {
        // "/preview/<job>" without the trailing slash makes a page's relative
        // assets resolve one directory too high; normalize before serving.
        // Built by hand because redirect()/url() run the target through the URL
        // generator, which trims the very slash we're adding — that redirects
        // the request to itself, forever.
        if ($path === '' && ! str_ends_with($request->getPathInfo(), '/')) {
            $query = $request->getQueryString();

            return response('', 302, ['Location' => $request->getPathInfo().'/'.($query ? '?'.$query : '')]);
        }

        try {
            $file = $this->sandbox->preview($job, $path);
        } catch (Throwable $e) {
            return response($this->reason($e), 502)->header('Content-Type', 'text/plain');
        }

        if ($file['status'] >= 400) {
            return response()->view('dashboard.preview-missing', [
                'job' => $job,
                'path' => $path,
            ], 404);
        }

        return response($file['body'], 200)->withHeaders([
            'Content-Type' => $file['content_type'],
            'Content-Security-Policy' => 'sandbox allow-scripts',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A message worth showing in the UI: a raw cURL/transport dump is noise —
     * what matters is that the sandbox is offline and how to start it.
     */
    private function reason(Throwable $e): string
    {
        if ($e instanceof ConnectionException) {
            return 'Sandbox is offline ('.rtrim((string) config('research.sandbox.base_url'), '/')
                .') — start it with: docker compose up -d sandbox';
        }

        return $e->getMessage();
    }
}
