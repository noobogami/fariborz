<?php

namespace App\Http\Controllers;

use App\Domain\Research\Contracts\HumanQuestionRepository;
use App\Events\HumanAvailabilityChanged;
use App\Events\HumanQuestionAnswered;
use App\Models\Human;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The human's side of the async loop. A human (or a Slack/Teams webhook) posts
 * answers and availability changes here; the events do the rest.
 */
class HumanInteractionController extends Controller
{
    public function __construct(private HumanQuestionRepository $questions) {}

    /** POST /api/humans/{human}/status — Available|Busy|Away|Offline. */
    public function updateStatus(Request $request, string $humanId): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:available,busy,away,offline']]);
        $human = Human::findOrFail($humanId);

        $human->update(['status' => $data['status'], 'last_seen_at' => now()]);

        // Coming online triggers queue reconciliation across active jobs.
        event(new HumanAvailabilityChanged($human->id, $data['status']));

        return response()->json(['id' => $human->id, 'status' => $data['status']]);
    }

    /** POST /api/questions/{question}/answer — a human answers a queued question. */
    public function answer(Request $request, string $questionId): JsonResponse
    {
        $data = $request->validate(['answer' => ['required', 'string']]);
        $question = $this->questions->find($questionId);

        $this->questions->recordAnswer($question, $data['answer'], source: 'human');

        // Injects the answer into memory and resumes the loop if it was waiting.
        event(new HumanQuestionAnswered($question->id));

        return response()->json(['id' => $question->id, 'status' => $question->fresh()->status->value]);
    }
}
