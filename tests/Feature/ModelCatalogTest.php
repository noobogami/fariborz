<?php

namespace Tests\Feature;

use App\Application\Research\Llm\ModelCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gateway model names are ALIASES the operator creates, renames and deletes at
 * will, so the app can never assume a configured name still exists. These cover
 * the three states that matter: the name is live, the name is gone, and the
 * catalogue can't be read at all.
 */
class ModelCatalogTest extends TestCase
{
    /** Model names the fake gateway serves; null = unreachable. */
    private ?array $served = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'research.llm.model' => 'local-standard',
            'research.llm.tiers' => [
                'light' => ['model' => '', 'hint' => 'x'],
                'standard' => ['model' => '', 'hint' => 'y'],
                'hard' => ['model' => 'claude', 'hint' => 'z'],
            ],
        ]);

        Http::preventStrayRequests();
        Http::fake(fn () => $this->served === null
            ? Http::response('down', 500)
            : Http::response(['data' => array_map(fn ($n) => ['id' => $n], $this->served)]));
    }

    private function gatewayServes(string ...$names): void
    {
        $this->served = $names;
        Cache::flush();
    }

    private function catalog(): ModelCatalog
    {
        return app(ModelCatalog::class);
    }

    public function test_a_live_name_resolves_to_itself(): void
    {
        $this->gatewayServes('local-standard', 'claude');

        $this->assertSame('local-standard', $this->catalog()->resolve('local-standard'));
        $this->assertTrue($this->catalog()->has('claude'));
        $this->assertSame([], $this->catalog()->stale());
    }

    public function test_a_renamed_model_falls_back_to_a_configured_one_that_still_exists(): void
    {
        // "local-standard" was renamed to "qwen3-normal"; "claude" (the hard tier)
        // survived, so it wins over an arbitrary catalogue entry.
        $this->gatewayServes('qwen3-normal', 'claude');

        $this->assertSame('claude', $this->catalog()->resolve('local-standard'));
        $this->assertFalse($this->catalog()->has('local-standard'));
        $this->assertSame(['local-standard'], $this->catalog()->stale());
    }

    public function test_with_nothing_configured_still_live_it_takes_the_first_gateway_model(): void
    {
        $this->gatewayServes('qwen3-normal', 'qwen3-thinking');

        $this->assertSame('qwen3-normal', $this->catalog()->resolve('local-standard'));
    }

    public function test_a_blank_configured_name_resolves_off_the_gateway(): void
    {
        $this->gatewayServes('qwen3-normal');

        $this->assertSame('qwen3-normal', $this->catalog()->resolve(''));
    }

    public function test_an_empty_gateway_resolves_to_nothing(): void
    {
        $this->gatewayServes();

        $this->assertSame([], $this->catalog()->names());
        $this->assertNull($this->catalog()->fallback());
        // Nothing to swap in — the configured name is handed back untouched and the
        // client raises a clear "gateway serves no models" error.
        $this->assertSame('local-standard', $this->catalog()->resolve('local-standard'));
    }

    public function test_an_unreadable_catalogue_never_rewrites_the_configured_model(): void
    {
        // Gateway down / master key rejected — we know nothing, so we change nothing.
        $this->assertNull($this->catalog()->names());
        $this->assertTrue($this->catalog()->has('anything-at-all'));
        $this->assertSame('local-standard', $this->catalog()->resolve('local-standard'));
        $this->assertSame([], $this->catalog()->stale());
    }

    public function test_the_catalogue_is_cached_and_bustable(): void
    {
        $this->gatewayServes('a');
        $this->assertSame(['a'], $this->catalog()->names());

        $this->served = ['a', 'b'];
        $this->assertSame(['a'], $this->catalog()->names());   // served from cache

        $this->catalog()->forget();
        $this->assertSame(['a', 'b'], $this->catalog()->names());
    }

    public function test_a_failed_read_is_not_cached(): void
    {
        $this->assertNull($this->catalog()->names());

        $this->served = ['a'];   // gateway came back — no forget() needed
        $this->assertSame(['a'], $this->catalog()->names());
    }

    public function test_gateway_status_reports_the_effective_model(): void
    {
        $this->gatewayServes('qwen3-normal');

        $status = $this->catalog()->gatewayStatus();
        $this->assertSame('local-standard', $status['configured_model']);
        $this->assertSame('qwen3-normal', $status['active_model']);
        $this->assertSame(['local-standard', 'claude'], $status['stale_models']);
    }
}
