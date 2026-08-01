<?php

namespace Tests\Feature;

use App\Application\Research\Tools\ToolRegistry;
use App\Application\Settings\SettingsService;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_renders(): void
    {
        $this->get('/settings')->assertOk()
            ->assertSee('Settings')
            ->assertSee('Anthropic API key')
            ->assertSee('Tavily API key');
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
