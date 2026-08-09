<?php

namespace App\Console\Commands;

use App\Application\Research\Llm\LiteLLMAdminClient;
use App\Application\Research\Llm\ModelCatalog;
use Illuminate\Console\Command;

/**
 * Seed Fariborz's model catalog (config/litellm.php) into the LiteLLM gateway's
 * database. This replaces hand-maintaining a `model_list` in the gateway's YAML.
 *
 *   php artisan litellm:seed                 # create every catalog model (skip existing)
 *   php artisan litellm:seed local-standard  # create just these
 *   php artisan litellm:seed --delete local-fast
 *   php artisan litellm:seed --force         # recreate even if already present
 */
class SeedLiteLLMModelsCommand extends Command
{
    protected $signature = 'litellm:seed
        {names?* : specific default model names (default: all)}
        {--delete : delete the named models from the gateway instead of creating}
        {--force : recreate a model even if it already exists}';

    protected $description = "Seed Fariborz's default LOCAL models (config/litellm.php) into the gateway (DB-backed)";

    public function handle(LiteLLMAdminClient $client, ModelCatalog $catalog): int
    {
        $defaults = collect((array) config('litellm.defaults', []));
        $names = (array) $this->argument('names');
        $targets = $names ? $defaults->whereIn('name', $names) : $defaults;

        if ($targets->isEmpty()) {
            $this->error('No matching default models.'.($names ? ' Known: '.$defaults->pluck('name')->implode(', ') : ''));

            return self::FAILURE;
        }

        $existing = $client->names();
        $ids = ($this->option('delete') || $this->option('force')) ? $client->idsByName() : [];
        $ok = 0;

        foreach ($targets as $m) {
            $name = $m['name'];

            if ($this->option('delete')) {
                if (! isset($ids[$name])) {
                    $this->line("  <fg=gray>—</> {$name} (not in gateway)");

                    continue;
                }
                $r = $client->delete($ids[$name]);
                $this->outcome($name, $r);
                $ok += $r['ok'] ? 1 : 0;

                continue;
            }

            if (in_array($name, $existing, true) && ! $this->option('force')) {
                $this->line("  <fg=gray>•</> {$name} already exists — skipped");

                continue;
            }
            if ($this->option('force') && isset($ids[$name])) {
                $client->delete($ids[$name]);   // replace in place
            }

            $params = $client->buildParams($m['provider'] ?? 'ollama', (string) ($m['model'] ?? ''), [
                'num_ctx' => $m['num_ctx'] ?? null,
                'think' => $m['think'] ?? false,
            ]);
            $r = $client->create($name, $params);
            $this->outcome($name, $r);
            $ok += $r['ok'] ? 1 : 0;
        }

        $catalog->forget();   // the gateway's catalogue just changed

        $this->newLine();
        $this->info(($this->option('delete') ? 'Deleted' : 'Created')." {$ok} model(s). Serving now: ".implode(', ', $client->names()));

        return self::SUCCESS;
    }

    private function outcome(string $name, array $r): void
    {
        $r['ok']
            ? $this->line("  <fg=green>ok</> {$name} — {$r['message']}")
            : $this->line("  <fg=red>xx</> {$name} — {$r['message']}");
    }
}
