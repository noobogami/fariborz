<?php

namespace App\Http\Controllers\Dashboard;

use App\Domain\Research\Contracts\HumanQuestionRepository;
use App\Events\HumanAvailabilityChanged;
use App\Events\HumanQuestionAnswered;
use App\Http\Controllers\Controller;
use App\Models\Human;
use App\Models\HumanQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HumanController extends Controller
{
    public function __construct(private HumanQuestionRepository $questions) {}

    public function index()
    {
        $humans = Human::withCount([
            'assignedQuestions as open_count' => fn ($q) => $q
                ->whereIn('status', ['queued', 'asked'])
                ->whereHas('job', fn ($j) => $j->whereIn('status', ['running', 'pending'])),
        ])->get();

        // Only questions from ACTIVE research — a finished/failed/cancelled job's
        // questions are moot and shouldn't clutter Father's queue.
        $open = HumanQuestion::whereIn('status', ['queued', 'asked'])
            ->whereHas('job', fn ($q) => $q->whereIn('status', ['running', 'pending']))
            ->with('job:id,goal')
            ->latest()
            ->get();

        return view('dashboard.humans', ['humans' => $humans, 'open' => $open]);
    }

    /** Set a human's availability (drives the async queue behaviour). */
    public function updateStatus(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:available,busy,away,offline']]);
        $human = Human::findOrFail($id);
        $human->update(['status' => $data['status'], 'last_seen_at' => now()]);

        // Coming online reconciles queued questions across active jobs.
        event(new HumanAvailabilityChanged($human->id, $data['status']));

        return back()->with('status', "{$human->name} is now {$data['status']}.");
    }

    /** Answer a queued/asked question — resumes the research loop. */
    public function answer(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['answer' => ['required', 'string']]);
        $question = $this->questions->find($id);

        $this->questions->recordAnswer($question, $data['answer'], source: 'human');
        event(new HumanQuestionAnswered($question->id));

        if ($request->wantsJson()) {
            return response()->json(['id' => $question->id, 'status' => $question->fresh()->status->value]);
        }

        return back()->with('status', 'Answer recorded; research will resume.');
    }
}
