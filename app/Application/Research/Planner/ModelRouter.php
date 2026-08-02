<?php

namespace App\Application\Research\Planner;

use App\Models\ResearchJob;

/**
 * Per-task model routing without hardcoding a model per role.
 *
 * Each job carries a capability TIER (light / standard / hard — see
 * config('research.llm.tiers')). A worker's tier is the one the supervisor
 * assigned to its task when planning; solo jobs and a supervisor's own
 * planning/review turns fall back to `default_tier`. This resolves that tier to
 * a model id and pins it into config('research.llm.model') for THIS turn, so the
 * active LLM client (Ollama / Anthropic / the OpenAI-compatible gateway)
 * transparently sends the right model — it reads config at call time and knows
 * nothing about tiers. Via the gateway (LiteLLM) a tier's model can be a local
 * Ollama model or a cloud one, so a task's difficulty routes it offline or to the
 * cloud.
 *
 * A tier whose model is blank falls back to the global `research.llm.model`, so
 * with every tier unset the behaviour is identical to before (one model).
 */
class ModelRouter
{
    /**
     * Resolve the tier for this job and pin its model into the live config for
     * the current turn. Returns the model id actually applied (for tracing), or
     * null when the tier adds no override (global model is used as-is).
     */
    public function apply(ResearchJob $job): ?string
    {
        $tier = $this->tierFor($job);
        config(['research.llm.active_tier' => $tier]);

        $model = trim((string) config("research.llm.tiers.$tier.model", ''));
        if ($model === '') {
            return null;   // blank tier → keep the global model
        }

        config(['research.llm.model' => $model]);

        return $model;
    }

    /** The tier name applied to this job, always a key that exists in config. */
    public function tierFor(ResearchJob $job): string
    {
        $tiers = (array) config('research.llm.tiers', []);
        $requested = $job->config['tier'] ?? null;

        if (is_string($requested) && isset($tiers[$requested])) {
            return $requested;
        }

        $default = (string) config('research.llm.default_tier', 'standard');
        if (isset($tiers[$default])) {
            return $default;
        }

        // Last resort: whatever the first configured tier is (or a safe literal).
        return (string) (array_key_first($tiers) ?? 'standard');
    }
}
