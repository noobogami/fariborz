<?php

namespace Database\Seeders;

use App\Domain\Research\Enums\EventType;
use App\Models\HumanQuestion;
use App\Models\ResearchEvent;
use App\Models\ResearchJob;
use App\Models\ToolExecution;
use Illuminate\Database\Seeder;

/**
 * Builds one realistic, finished research job with a full timeline so the
 * dashboard has something meaningful to display for demos/screenshots.
 */
class DemoResearchSeeder extends Seeder
{
    public function run(): void
    {
        $job = ResearchJob::create([
            'goal' => 'Determine whether Company X is a good supplier',
            'status' => 'completed',
            'config' => ['limits' => config('research.limits')],
            'iteration' => 4,
            'tool_call_count' => 3,
            'confidence' => 0.88,
            'final_report' => "Company X appears to be a reliable supplier.\n\n"
                ."• Revenue: ~$1.2B (2024, corporate filings)\n"
                ."• Employees: ~3,400 (LinkedIn / company site)\n"
                ."• Reviews: mostly positive (4.3/5 across trade directories)\n"
                ."• Legal: no material litigation found\n\n"
                .'Recommendation: proceed, pending confirmation of prior ERP purchase history.',
            'started_at' => now()->subMinutes(6),
            'finished_at' => now()->subMinutes(2),
            'deadline_at' => now()->addHour(),
        ]);

        ToolExecution::create([
            'research_job_id' => $job->id, 'tool_name' => 'google_search',
            'arguments' => ['query' => 'Company X revenue'], 'fingerprint' => str_repeat('a', 64),
            'status' => 'success', 'observation' => 'Revenue ~$1.2B', 'attempts' => 1, 'duration_ms' => 312,
        ]);

        $q = HumanQuestion::create([
            'research_job_id' => $job->id,
            'question' => 'Can you check our ERP for prior purchases from Company X?',
            'tags' => ['erp', 'procurement'], 'status' => 'queued', 'priority' => 7,
        ]);

        $timeline = [
            [EventType::JobStarted,          0, 'Research started for goal: Determine whether Company X is a good supplier', null],
            [EventType::Thought,             1, 'LLM produced a decision', 840],
            [EventType::ToolSelected,        1, 'Chose google_search: need company revenue first', null],
            [EventType::ToolSucceeded,       1, 'google_search returned: Company X posts $1.2B in 2024 revenue…', 312],
            [EventType::Observation,         1, 'Observation from google_search: 1. Company X 2024 revenue $1.2B…', null],
            [EventType::Thought,             2, 'LLM produced a decision', 690],
            [EventType::ToolSelected,        2, 'Chose google_search: now look for customer reviews', null],
            [EventType::ToolSucceeded,       2, 'google_search returned: 4.3/5 across trade directories…', 280],
            [EventType::Observation,         2, 'Observation from google_search: reviews mostly positive…', null],
            [EventType::HumanQuestionQueued, 3, 'Queued (no human available): "Can you check our ERP for prior purchases from Company X?"', null],
            [EventType::Thought,             3, 'LLM produced a decision', 720],
            [EventType::Observation,         3, 'No human available — continuing via other sources', null],
            [EventType::Finished,            4, 'Research complete (confidence: 0.88)', null],
        ];

        $seq = 1;
        foreach ($timeline as [$type, $iter, $summary, $dur]) {
            ResearchEvent::create([
                'research_job_id' => $job->id, 'seq' => $seq++, 'iteration' => $iter,
                'type' => $type, 'level' => $type->level(), 'summary' => $summary,
                'duration_ms' => $dur, 'occurred_at' => now()->subMinutes(6)->addSeconds($seq * 20),
            ]);
        }
    }
}
