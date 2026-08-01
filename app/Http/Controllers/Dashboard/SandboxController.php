<?php

namespace App\Http\Controllers\Dashboard;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SandboxController extends Controller
{
    public function __construct(private SandboxClient $sandbox) {}

    public function index()
    {
        $info = [];
        try {
            $info = $this->sandbox->info('sandbox'); // pool/free ports are job-independent
        } catch (SandboxException) {
        }

        return view('dashboard.sandbox', [
            'status' => $this->sandbox->status(),
            'info' => $info,
        ]);
    }

    /** Live list of running background servers (polled by the page). */
    public function processesJson(): JsonResponse
    {
        try {
            return response()->json($this->sandbox->processes());
        } catch (SandboxException $e) {
            return response()->json(['processes' => [], 'error' => $e->getMessage()]);
        }
    }

    public function kill(Request $request): JsonResponse
    {
        $data = $request->validate(['pid' => ['required', 'integer', 'min:2']]);

        try {
            $this->sandbox->kill((int) $data['pid']);

            return response()->json(['ok' => true]);
        } catch (SandboxException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
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
                $this->sandbox->exec($data['job'], $data['cmd'], $data['cwd'] ?: '.', 120)
            );
        } catch (SandboxException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }
}
