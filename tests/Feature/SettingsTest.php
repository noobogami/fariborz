<?php

namespace Tests\Feature;

use App\Application\Research\Tools\ToolRegistry;
use App\Application\Settings\SettingsService;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    /** Model names the fake gateway serves; null = unreachable. */
    private ?array $served = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Don't hit a real gateway during rendering tests. One dispatching stub
        // rather than per-test Http::fake() calls: stubs are matched in the order
        // they're registered, so a later fake can never override an earlier '*'.
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/models')) {
                return $this->served === null
                    ? Http::response('down', 500)
                    : Http::response(['data' => array_map(fn ($n) => ['id' => $n], $this->served)]);
            }

            return Http::response([], 500);   // sandbox/browser/admin — irrelevant here
        });
    }

    public function test_settings_page_renders(): void
    {
        $this->get('/settings')->assertOk()
            ->assertSee('Settings')
            ->assertSee('Gateway URL')
            ->assertSee('Tavily API key');
    }

    /** The model fields, as the page's Alpine payload actually describes them. */
    private function modelFields(): array
    {
        $html = $this->get('/settings')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/const data = (.*);/', $html);
        preg_match('/const data = (.*);\n/', $html, $m);

        $out = [];
        foreach (json_decode($m[1], true)['groups'] as $group) {
            foreach ($group['fields'] as $f) {
                if (str_contains($f['key'], '.model')) {
                    $out[$f['key']] = $f;
                }
            }
        }
        $this->assertNotEmpty($out);

        return $out;
    }

    public function test_model_fields_become_a_dropdown_of_the_gateways_live_models(): void
    {
        $this->served = ['local-standard', 'gpt-4o', 'claude'];

        $default = $this->modelFields()['research.llm.model'];

        // A real <select> of live names — not a free-text box, and not the
        // segmented button row the view uses for other short option lists.
        $this->assertSame('select', $default['type']);
        $this->assertSame('select', $default['ui']);
        $this->assertSame(['', 'local-standard', 'gpt-4o', 'claude'], $default['options']);
        $this->assertSame('', $default['notice']);
    }

    public function test_every_model_field_can_render_its_empty_value_with_a_label(): void
    {
        // The empty value is a real setting ("nothing pinned — use the gateway's"),
        // and it is the shipped default. A <select> with no option for the current
        // value renders as a blank box, so the control must always carry a LABELLED
        // empty option — never an unexplained gap.
        $this->served = ['qwen3-normal'];
        config(['research.llm.model' => '']);

        foreach ($this->modelFields() as $key => $f) {
            $this->assertContains('', $f['options'], "{$key} has no option for the empty value");
            $this->assertNotSame('', trim($f['blankLabel']), "{$key} renders its empty option blank");
        }
    }

    public function test_model_fields_fall_back_to_text_when_the_gateway_is_down(): void
    {
        // Unreachable → we can't know what exists, so no dropdown and no rewriting;
        // the field stays typeable and says why.
        $default = $this->modelFields()['research.llm.model'];

        $this->assertSame('string', $default['type']);
        $this->assertStringContainsString('Gateway unreachable', $default['notice']);
    }

    public function test_an_empty_gateway_renders_and_says_no_models_are_available(): void
    {
        // A fresh gateway that serves nothing is a legitimate state, not a broken
        // page: the model fields stay usable and explain what to do about it.
        $this->served = [];
        config(['research.llm.model' => 'local-standard']);

        $default = $this->modelFields()['research.llm.model'];

        $this->assertStringContainsString('The gateway serves no models yet', $default['notice']);
        $this->assertStringContainsString('local-standard', $default['notice']);
        // "Nothing to pick" has to be readable off the control itself, not inferred
        // from an empty-looking dropdown.
        $this->assertSame('— no models available on the gateway —', $default['blankLabel']);
        $this->assertSame(['', 'local-standard'], $default['options']);
    }

    public function test_a_model_renamed_on_the_gateway_is_flagged_with_its_stand_in(): void
    {
        // The saved name no longer exists (renamed in the gateway UI).
        $this->served = ['qwen3-normal'];
        config(['research.llm.model' => 'local-standard']);

        $default = $this->modelFields()['research.llm.model'];

        $this->assertStringContainsString('is not on the gateway', $default['notice']);
        $this->assertStringContainsString('qwen3-normal', $default['notice']);
        // It stays selectable and flagged, so rendering the form can't silently
        // rewrite the saved setting behind the operator's back.
        $this->assertSame('local-standard', $default['stale']);
        $this->assertContains('local-standard', $default['options']);
    }

    public function test_saving_a_plain_setting_applies_to_config(): void
    {
        $this->post('/settings', ['settings' => [
            'research.llm.model' => 'qwen3:14b',
            'research.limits.max_iterations' => '12',
        ]])->assertRedirect(route('settings'));

        $this->assertDatabaseHas('settings', ['config_key' => 'research.llm.model', 'value' => 'qwen3:14b']);

        app(SettingsService::class)->apply();
        $this->assertSame('qwen3:14b', config('research.llm.model'));
        $this->assertSame(12, config('research.limits.max_iterations')); // cast to int
    }

    public function test_secret_is_stored_encrypted_and_decrypts_on_apply(): void
    {
        app(SettingsService::class)->save(['services.tavily.key' => 'tvly-secret-123']);

        $row = Setting::find('services.tavily.key');
        $this->assertTrue($row->secret);
        $this->assertNotSame('tvly-secret-123', $row->value); // encrypted at rest

        app(SettingsService::class)->apply();
        $this->assertSame('tvly-secret-123', config('services.tavily.key'));
    }

    public function test_blank_secret_keeps_existing_value(): void
    {
        app(SettingsService::class)->save(['services.brave.key' => 'brave-123']);
        app(SettingsService::class)->save(['services.brave.key' => '']); // blank = keep

        app(SettingsService::class)->apply();
        $this->assertSame('brave-123', config('services.brave.key'));
    }

    public function test_setting_a_search_key_enables_its_tool_live(): void
    {
        $this->assertFalse(app(ToolRegistry::class)->has('tavily_search'));

        app(SettingsService::class)->save(['services.tavily.key' => 'tvly-x']);
        app(SettingsService::class)->apply();

        // Registry is rebuilt per resolution → the tool is now available.
        $this->assertTrue(app(ToolRegistry::class)->has('tavily_search'));
    }
}
