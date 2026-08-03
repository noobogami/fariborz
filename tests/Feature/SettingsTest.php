<?php

namespace Tests\Feature;

use App\Application\Research\Tools\ToolRegistry;
use App\Application\Settings\SettingsService;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Don't hit a real gateway during rendering tests.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 500)]);
    }

    public function test_settings_page_renders(): void
    {
        $this->get('/settings')->assertOk()
            ->assertSee('Settings')
            ->assertSee('Gateway URL')
            ->assertSee('Tavily API key');
    }

    public function test_model_fields_become_a_dropdown_of_the_gateways_live_models(): void
    {
        // Gateway reachable and serving a model catalogue.
        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'local-standard'], ['id' => 'gpt-4o'], ['id' => 'claude'],
        ]])]);

        $html = $this->get('/settings')->assertOk()->getContent();

        // The tier/default model fields are rendered as a <select> whose options
        // come from the gateway — not a free-text box.
        $this->assertStringContainsString('"options":["', $html);
        $this->assertStringContainsString('gpt-4o', $html);
        $this->assertStringContainsString('claude', $html);
    }

    public function test_model_fields_fall_back_to_text_when_the_gateway_is_down(): void
    {
        // Gateway unreachable → no options → fields stay as string inputs (type not 'select').
        Http::fake(['*/models' => Http::response('down', 500)]);

        $html = $this->get('/settings')->assertOk()->getContent();
        // The default-model field is present but was NOT upgraded to a select.
        $this->assertStringContainsString('research.llm.model', $html);
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
