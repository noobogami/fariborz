<?php

namespace App\Http\Controllers;

use App\Application\Research\StartResearch;
use App\Application\Research\Tracing\ResearchTraceReader;
use App\Domain\Research\Contracts\ResearchJobRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResearchJobController extends Controller
{
    public function __construct(private ResearchJobRepository $jobs) {}

    /** POST /api/research — start a new autonomous research job. */
    public function store(Request $request, StartResearch $start): JsonResponse
    {
        $data = $request->validate([
            'goal' => ['required', 'string', 'min:5'],
            'config' => ['sometimes', 'array'],
            'config.allowed_tools' => ['sometimes', 'array'],
            'config.limits' => ['sometimes', 'array'],
        ]);

        $job = $start->handle($data['goal'], $data['config'] ?? []);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status->value,
            'trace' => route('research.show', $job->id),
        ], 201);
    }

    /** GET /api/research/{id} — status + full timeline (the trace). */
    public function show(string $id, ResearchTraceReader $reader, Request $request): JsonResponse
    {
        return response()->json(
            $reader->build($id, withPayloads: $request->boolean('full'))
        );
    }

    /** POST /api/research/{id}/cancel — a supervisor stops the run. */
    public function cancel(string $id): JsonResponse
    {
        $job = $this->jobs->find($id);
        $this->jobs->markCancelled($job);

        return response()->json(['id' => $job->id, 'status' => $job->status->value]);
    }
}
