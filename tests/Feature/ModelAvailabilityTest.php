<?php

namespace Tests\Feature;

use App\Application\Research\Llm\ModelAvailability;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelAvailabilityTest extends TestCase
{
    private function availability(): ModelAvailability
    {
        return app(ModelAvailability::class);
    }

    public function test_it_classifies_availability_failures_vs_approach_failures(): void
    {
        $a = $this->availability();

        // Availability (the model/gateway failed) — must route to another model.
        $this->assertTrue($a->isAvailabilityFailure('litellm.RateLimitError: 429 too many requests'));
        $this->assertTrue($a->isAvailabilityFailure('upstream timed out (504)'));
        $this->assertTrue($a->isAvailabilityFailure('the model returned an empty completion'));

        // Approach (the worker did the work wrong) — must go to FailureDiagnosis.
        $this->assertFalse($a->isAvailabilityFailure('wrote only 2 paragraphs instead of 5'));
        $this->assertFalse($a->isAvailabilityFailure('left scaffolding text in the file'));
    }

    public function test_a_marked_model_is_in_cooldown_until_it_expires(): void
    {
        config(['research.supervisor.model_unavailable_cooldown' => 120]);
        $a = $this->availability();

        $this->assertFalse($a->inCooldown('gpt-4o'));
        $a->markUnavailable('gpt-4o', 'rate limit');
        $this->assertTrue($a->inCooldown('gpt-4o'));
        $this->assertStringContainsString('rate limit', (string) $a->cooldownReason('gpt-4o'));
    }

    public function test_pick_replacement_skips_cooldown_and_the_avoided_model(): void
    {
        // Health probe reports every candidate up; availability is decided by cooldown.
        Http::fake(['*/health*' => Http::response(['unhealthy_count' => 0])]);
        $a = $this->availability();
        $a->markUnavailable('local-standard', 'timeout');

        // 'cloud' is the model we're leaving; 'local-standard' is in cooldown; 'gemini' is free.
        $this->assertSame('gemini', $a->pickReplacement(['cloud', 'local-standard', 'gemini'], 'cloud'));
        $this->assertNull($a->pickReplacement(['cloud'], 'cloud'), 'no candidate but the avoided one → null');
    }

    public function test_a_model_reported_unhealthy_is_not_confirmed_available(): void
    {
        Http::fake(['*/health*' => Http::response(['unhealthy_count' => 1])]);
        $this->assertFalse($this->availability()->confirmAvailable('down-model'));
    }

    public function test_health_probe_fails_open_when_the_gateway_is_unreachable(): void
    {
        // A gateway error must not block handovers — we can't disprove availability.
        Http::fake(['*/health*' => Http::response('boom', 500)]);
        $this->assertTrue($this->availability()->confirmAvailable('unknown-model'));
    }
}
