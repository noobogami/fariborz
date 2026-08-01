<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/**
 * Start a long-lived web server the RIGHT way. run_command is for commands that
 * finish — it kills the process tree at its timeout, so a foreground `node
 * server.js` always dies. This tool detaches the server so it keeps running,
 * then reports whether the port is ACTUALLY listening (and the log if it
 * crashed), so the agent never again mistakes "it printed 'listening'" for a
 * working server.
 */
class StartServerTool implements Tool
{
    public function __construct(private SandboxClient $sandbox) {}

    public function name(): string
    {
        return 'start_server';
    }

    public function description(): string
    {
        return 'Start a long-lived server (web app, API, dev server) in the sandbox and '
            .'keep it running. Give the command and the port it listens on; it returns the '
            .'URL and whether the port is really up — or the crash log if it failed. Use '
            .'this for ANY process that does not exit (do NOT use run_command for servers). '
            .'Install dependencies with run_command FIRST — a server that imports an '
            .'uninstalled package will crash.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'The command that starts the server, e.g. "node server.js" or "python3 -m http.server 8090".'],
                'port' => ['type' => 'integer', 'description' => 'The port the server listens on. MUST be a free PUBLISHED port (8090-8099 — see sandbox_info); other ports (8000/3000/5000) are NOT reachable from the host. Bind the server to 0.0.0.0 on this port.'],
                'cwd' => ['type' => 'string', 'description' => 'Working dir relative to the workspace (default ".").'],
            ],
            'required' => ['command'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $port = $args->int('port') ?: null;

        // Only the PUBLISHED port pool (e.g. 8090-8099) is reachable from the host.
        // A server on any other port (8000/3000/5000…) is listening INSIDE the
        // container but the user can never open it — refuse before even starting,
        // so the agent rebinds instead of falsely reporting success.
        [$lo, $hi] = $this->publishedRange();
        if ($port !== null && ($port < $lo || $port > $hi)) {
            return ToolResult::fail(
                "Port {$port} is NOT published to the host, so the user could not reach it. "
                ."Bind the server to a FREE PUBLISHED port in the range {$lo}-{$hi} instead "
                .'(call sandbox_info to see which are free), then start_server on that port.'
            );
        }

        try {
            $r = $this->sandbox->serve($ctx->workspaceId(), $args->string('command'), $port, $args->string('cwd', '.'));
        } catch (SandboxException $e) {
            return ToolResult::fail($e->getMessage());
        }

        $pid = $r['pid'] ?? null;
        $tail = trim((string) ($r['log_tail'] ?? ''));

        // Up and listening on a PUBLISHED port → genuinely reachable success.
        if (($r['listening'] ?? false) === true) {
            return ToolResult::ok(
                "✅ Server is UP and reachable at {$r['url']} (pid {$pid}). It will keep running; "
                ."logs are in {$r['log']}. The user can open {$r['url']}.",
                $r
            );
        }

        // A port was requested but nothing is listening → it crashed or bound elsewhere.
        if ($port !== null) {
            return ToolResult::ok(
                "⚠️ Started (pid {$pid}) but nothing is listening on port {$port} — it likely "
                ."crashed or bound a different port. Read the log and fix it, then start again:\n\n"
                .($tail !== '' ? "--- {$r['log']} ---\n{$tail}" : '(no output logged yet)'),
                $r
            );
        }

        // No port given — report liveness + log.
        $state = ($r['alive'] ?? false) ? 'running' : 'exited';

        return ToolResult::ok(
            "Started pid {$pid} ({$state}). Recent log:\n"
            .($tail !== '' ? "--- {$r['log']} ---\n{$tail}" : '(no output logged yet)'),
            $r
        );
    }

    /** The host-published port pool, from config e.g. "8090-8099". */
    private function publishedRange(): array
    {
        $range = (string) config('research.sandbox.app_ports', '8090-8099');
        $parts = array_map('intval', explode('-', $range));

        return [$parts[0] ?? 8090, $parts[1] ?? ($parts[0] ?? 8099)];
    }
}
