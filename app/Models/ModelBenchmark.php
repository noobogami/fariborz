<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The persisted result of ModelBenchmark's probe suite for ONE gateway model —
 * see App\Application\Research\Llm\ModelBenchmark for what actually produces
 * these numbers. `model` is unique: this is a current-state row (upserted),
 * not a history log.
 */
class ModelBenchmark extends Model
{
    protected $guarded = [];

    protected $casts = [
        'probes' => 'array',
        'low_confidence' => 'boolean',
        'ran_at' => 'datetime',
    ];

    /**
     * Ratings for the named models, keyed by model name — the ONE read path for
     * the two hot callers (GatewayHealth::expectedBaselineMs and
     * ModelAvailability::orderByBenchmark).
     *
     * It swallows database trouble on purpose. Benchmarks are an OPTIONAL
     * calibration input: both callers already treat "nothing rated" as a valid
     * state and fall back to their pre-benchmark behaviour. But they are read on
     * the hot path of every model call and every supervisor turn, so a missing
     * table (code deployed before `php artisan migrate` — this migration is
     * newer than the queue worker holding the old code) or a momentarily
     * unreachable DB would otherwise throw INSIDE the orchestrator and fail
     * every job, to gain a number we are happy to live without. Same reasoning
     * as SettingsService::apply()'s "never let settings loading break boot".
     *
     * @param  list<string>  $names
     * @return Collection<string, self>
     */
    public static function ratedBy(array $names): Collection
    {
        $names = array_values(array_filter(array_map(fn ($n) => trim((string) $n), $names)));
        if (empty($names)) {
            return new Collection;
        }

        try {
            return static::query()->whereIn('model', $names)->get()->keyBy('model');
        } catch (Throwable) {
            return new Collection;
        }
    }
}
