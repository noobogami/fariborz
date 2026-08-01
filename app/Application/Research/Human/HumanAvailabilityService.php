<?php

namespace App\Application\Research\Human;

use App\Domain\Research\Enums\HumanStatus;
use App\Models\Human;

/**
 * Knows who can answer right now and picks the best responder for a question.
 *
 * Designed for the multi-human future from the start: routing is by expertise
 * tags + availability, so adding humans with different skills/permissions needs
 * no orchestration changes.
 */
class HumanAvailabilityService
{
    /** The single human ("Father"). Prefer the configured name; fall back to any row. */
    public function primary(): ?Human
    {
        $name = config('research.human.name', 'Father');

        return Human::where('name', $name)->first() ?? Human::query()->first();
    }

    /** The human's name, e.g. "Father" (from the record, or config if none exists). */
    public function name(): string
    {
        return $this->primary()?->name ?? config('research.human.name', 'Father');
    }

    /**
     * Pick an available human, preferring the best expertise match for the
     * given tags. Returns null when nobody is available.
     *
     * @param  array<int,string>  $tags
     */
    public function pickResponder(array $tags = []): ?Human
    {
        $candidates = Human::where('status', HumanStatus::Available->value)->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sortByDesc(fn (Human $h) => $this->expertiseScore($h, $tags))
            ->sortByDesc(fn (Human $h) => optional($h->last_seen_at)?->timestamp ?? 0)
            ->first();
    }

    /** How many of the question's tags this human lists as expertise. */
    private function expertiseScore(Human $human, array $tags): int
    {
        if (empty($tags) || empty($human->expertise)) {
            return 0;
        }

        return count(array_intersect(
            array_map('strtolower', $tags),
            array_map('strtolower', $human->expertise),
        ));
    }

    /** Human-readable one-liner for prompts and traces, e.g. "Father is available". */
    public function summary(): string
    {
        $human = $this->primary();

        return $human ? "{$human->name} is {$human->status->value}" : 'no human configured';
    }
}
