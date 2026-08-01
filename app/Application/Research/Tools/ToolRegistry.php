<?php

namespace App\Application\Research\Tools;

use App\Domain\Research\Contracts\ControlTool;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\Enums\JobRole;
use RuntimeException;

/**
 * Holds every registered Tool, keyed by name. Populated from container-tagged
 * services (see ResearchServiceProvider), so adding a tool never touches this
 * class or the orchestrator.
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /** @param iterable<Tool> $tools */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): Tool
    {
        return $this->tools[$name] ?? throw new RuntimeException("Tool not registered: {$name}");
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * The catalogue handed to the LLM prompt, gated by ROLE:
     *   - Supervisor sees ONLY control tools (plan/delegate/review) + ask_human —
     *     it plans and delegates, it never does the low-level work itself.
     *   - Solo/Worker see every NON-control tool — they do the actual work.
     * Optionally further restricted to a job's allow-list.
     *
     * @param  array<int,string>|null  $allow
     */
    public function definitions(?array $allow = null, ?JobRole $role = null): array
    {
        $tools = $allow ? array_intersect_key($this->tools, array_flip($allow)) : $this->tools;

        // A supervisor's job is bounded: PLAN the tasks, REVIEW what workers produce,
        // and FINISH. The orchestrator does the delegating deterministically, so the
        // model never even sees delegate_task — that free choice is what made a weak
        // model thrash. It also gets read-only tools to VERIFY artifacts before it
        // accepts them. It still cannot write or run code.
        $supervisorExtras = ['ask_human', 'read_file', 'list_files', 'container_logs', 'list_processes'];
        $supervisorHidden = ['delegate_task'];

        $tools = array_filter($tools, function (Tool $t) use ($role, $supervisorExtras, $supervisorHidden) {
            $isControl = $t instanceof ControlTool;

            return $role === JobRole::Supervisor
                ? (($isControl || in_array($t->name(), $supervisorExtras, true)) && ! in_array($t->name(), $supervisorHidden, true))
                : ! $isControl;
        });

        return array_values(array_map(fn (Tool $t) => [
            'name' => $t->name(),
            'description' => $t->description(),
            'schema' => $t->schema(),
        ], $tools));
    }

    public function isControl(string $name): bool
    {
        return isset($this->tools[$name]) && $this->tools[$name] instanceof ControlTool;
    }
}
