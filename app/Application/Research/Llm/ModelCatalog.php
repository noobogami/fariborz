<?php

namespace App\Application\Research\Llm;

use App\Models\ModelBenchmark;
use Illuminate\Support\Facades\Cache;

/**
 * The authority on "which model names actually exist right now" — and the
 * resolver that keeps Fariborz off a name that doesn't.
 *
 * A model name is NOT a constant. Every name is an ALIAS the operator creates in
 * Settings ▸ Tools ▸ Gateway models, so it can be renamed or deleted at any
 * moment. Anything that pins a literal (a config default like "local-standard", a
 * tier setting saved months ago) silently rots the instant that happens: the
 * gateway 400s on an unknown model and every turn of every job fails. So no model
 * id reaches the wire without going through resolve() first, and the Settings UI
 * offers only names the gateway really serves.
 *
 * Two rules make this safe:
 *  - "unreadable" ≠ "empty". A gateway that is down or rejecting the master key
 *    tells us nothing, so we FAIL OPEN and use the configured name unchanged — an
 *    infra blip must never rewrite the operator's choice. Only a catalogue we
 *    genuinely read can declare a name missing.
 *  - the stand-in is predictable: a model the operator already picked somewhere
 *    and that still exists, before any arbitrary catalogue entry.
 *
 * The list is short-cached because this sits on the hot path (once per planner
 * turn); adding or removing a model in the UI busts it via forget().
 */
class ModelCatalog
{
    private const CACHE_KEY = 'research:gateway:catalog';

    public function __construct(private GatewayManager $gateway) {}

    /**
     * Live model names the gateway serves, or NULL when the catalogue could not be
     * read (down / bad key). An empty array is a real answer: a gateway with no
     * models configured yet.
     *
     * @return list<string>|null
     */
    public function names(): ?array
    {
        $ttl = max(0, (int) config('research.llm.catalog_ttl', 30));

        if ($ttl > 0 && is_array($hit = Cache::get(self::CACHE_KEY))) {
            return $hit;
        }

        $c = $this->gateway->catalog();
        if (! ($c['ok'] ?? false)) {
            return null;   // never cached — the gateway may be seconds from coming back
        }

        $names = array_values(array_map('strval', (array) $c['models']));
        if ($ttl > 0) {
            Cache::put(self::CACHE_KEY, $names, $ttl);
        }

        return $names;
    }

    /** Drop the cached catalogue — call after adding or removing a gateway model. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * What to SHOW as "the model" on pages that merely display it (page header,
     * job detail). Deliberately does NO I/O: with the default model left blank,
     * the honest answer comes from the catalogue, but a page header is not worth a
     * 4-second gateway timeout, so it reads only an already-cached catalogue and
     * otherwise says so.
     */
    public function displayModel(): string
    {
        $configured = trim((string) config('research.llm.model', ''));
        if ($configured !== '') {
            return $configured;
        }

        $cached = Cache::get(self::CACHE_KEY);

        return $this->pickFallback(is_array($cached) ? $cached : null) ?? 'auto · gateway';
    }

    /** Does the gateway serve this name? An unreadable catalogue answers yes. */
    public function has(string $model): bool
    {
        $names = $this->names();

        return $names === null || in_array(trim($model), $names, true);
    }

    /**
     * The model to actually SEND for a configured name: the name itself while the
     * gateway still serves it, otherwise a live stand-in — so a renamed or deleted
     * model degrades to a working one instead of 400-ing every call. Returns ''
     * only when there is genuinely nothing to use.
     */
    public function resolve(string $configured): string
    {
        return $this->pick($configured, $this->names());
    }

    /**
     * The stand-in used when a configured name is missing or blank: a model the
     * operator already pointed something at (the default model, then the tiers in
     * order) and that still exists, else the gateway's first model. Null when the
     * catalogue is unreadable or empty.
     */
    public function fallback(): ?string
    {
        return $this->pickFallback($this->names());
    }

    /**
     * Configured names the gateway no longer serves — what the Settings UI flags.
     * Empty when the catalogue is unreadable (we cannot call anything stale then).
     *
     * @return list<string>
     */
    public function stale(): array
    {
        return $this->staleAgainst($this->names());
    }

    /**
     * Every model name the config currently points at, default first.
     *
     * @return list<string>
     */
    public function configured(): array
    {
        $out = [trim((string) config('research.llm.model', ''))];
        foreach ((array) config('research.llm.tiers', []) as $tier) {
            $out[] = trim((string) ($tier['model'] ?? ''));
        }

        return array_values(array_unique(array_filter($out, fn ($m) => $m !== '')));
    }

    /**
     * GatewayManager::status() for the dashboard, enriched with what the app will
     * ACTUALLY use: the effective model after resolution, the raw configured name,
     * and any configured names that have gone missing. Derived from the same read,
     * so the panel can never disagree with the router about the live model.
     */
    public function gatewayStatus(): array
    {
        $status = $this->gateway->status();
        $names = ($status['reachable'] ?? false) ? array_values((array) ($status['models'] ?? [])) : null;

        $configured = trim((string) config('research.llm.model', ''));
        $status['configured_model'] = $configured;
        $status['active_model'] = $this->pick($configured, $names);
        $status['stale_models'] = $this->staleAgainst($names);

        return $status;
    }

    /** @param  list<string>|null  $names */
    private function pick(string $configured, ?array $names): string
    {
        $configured = trim($configured);

        if ($names === null) {
            return $configured;   // catalogue unknown — trust config
        }
        if ($configured !== '' && in_array($configured, $names, true)) {
            return $configured;
        }

        return $this->pickFallback($names) ?? $configured;
    }

    /** @param  list<string>|null  $names */
    private function pickFallback(?array $names): ?string
    {
        if (! $names) {
            return null;
        }
        foreach ($this->configured() as $model) {
            if (in_array($model, $names, true)) {
                return $model;
            }
        }

        // Last resort: nothing the operator configured survived, so prefer a
        // PROVEN model over whichever one happened to be listed first — see the
        // benchmark-wiring spec §D. Falls back to $names[0] unchanged when
        // nothing has been benchmarked, so this is a pure enhancement.
        return $this->bestRated($names) ?? $names[0];
    }

    /**
     * The highest-scored model among $names that isn't rated `broken`. Iterates
     * in $names order so a score tie always resolves to the same model
     * regardless of row insertion order in the DB. Null when nothing is rated —
     * the caller's own $names[0] fallback then applies unchanged.
     *
     * @param  list<string>  $names
     */
    private function bestRated(array $names): ?string
    {
        $rated = ModelBenchmark::ratedBy($names);

        $best = null;
        foreach ($names as $name) {
            $row = $rated->get($name);
            if ($row === null || $row->rating === 'broken' || $row->score === null) {
                continue;
            }
            if ($best === null || (int) $row->score > (int) $best->score) {
                $best = $row;
            }
        }

        return $best?->model;
    }

    /**
     * @param  list<string>|null  $names
     * @return list<string>
     */
    private function staleAgainst(?array $names): array
    {
        if ($names === null) {
            return [];
        }

        return array_values(array_filter($this->configured(), fn ($m) => ! in_array($m, $names, true)));
    }
}
