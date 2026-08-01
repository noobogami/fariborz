# CLAUDE.md — Fariborz

Orientation for a fresh chat. Read this, then go straight to the task. Don't re-read the whole codebase to learn "what is what" — it's mapped below.

## What this is

**Fariborz** is an **autonomous AI research/build agent** built in **Laravel**. You give it a goal; it iterates on its own — analyze state → pick ONE next action → execute → store result → repeat until done. It is NOT a chatbot. The **LLM only decides**; Laravel does the executing, storing, limiting, resuming, and tracing. Everything is designed to be **traceable/debuggable** so a supervisor can reconstruct exactly what path the agent took days later.

Default model is a **local, offline Ollama model (`qwen3:8b`)** — so the architecture must make **weak models reliable**, not assume a strong one. A stronger model should only mean *faster*, not *more capable outcomes*.

## The loop (core mechanic)

One planner iteration = **one queued job** (`AdvanceResearchJob`) that re-dispatches itself until the job finishes. The **queue IS the loop** → crash-safe & resumable because all state lives in the DB, never in a long-lived worker. See `app/Application/Research/ResearchOrchestrator.php::advance()`.

Per iteration: build `ResearchContext` (fresh from DB) → preflight guardrails (stop on limit/timeout/cancel) → `Planner->decide()` (LLM returns a `Decision`: a `ToolCall` or `FinishDecision`) → execute tool via `ToolRunner` → store observation → reschedule.

## Supervisor / worker hierarchy (for big tasks)

A job has a **role**: `solo` (original single loop), `supervisor` (decomposes a goal into a task list, delegates), or `worker` (does ONE task, reports back). Started `supervised` from the UI or `StartResearch::handle(..., JobRole::Supervisor)`.

**Control flow is DETERMINISTIC ("blueprint first, model second") — this is the most important design rule.** A weak model is bad at "what should I do next" and will thrash/infinite-loop. So:
- The **orchestrator auto-delegates** every ready task (deps met), independents in parallel. There is **no `delegate_task` tool for the supervisor** — the model never chooses to delegate.
- The supervisor LLM is asked for **only three bounded things**: `plan_tasks`, `review_task` (verify ONE artifact + accept/revise), `finish`. When workers run and nothing awaits review, it **parks with no LLM call**.
- A finished worker fires an event → `ResumeSupervisorOnChildDone` wakes the parent (works recursively for sub-supervisors).
- **Shared workspace**: every job in a project tree shares ONE sandbox workspace = `root_job_id` (see `ResearchContext::workspaceId()`), so files combine and a served app can be assembled/deployed in one place. This *is* the "blackboard/knowledge doc" — a task can write `notes.md` and later tasks read it.
- **Tasks have `depends_on`** (seq numbers, default = previous task). A task starts only when its deps are **verified (Done)**. Independents run in parallel.
- **Stall breaker**: a blocked/no-progress action advances the iteration + a stall counter; after `config('research.limits.max_stalls')` (default 6) it stops with a partial report. Supervisors are exempt from the duplicate/repeated-failure guardrails (their control tools legitimately repeat).

## Where things live

- `app/Application/Research/`
  - `ResearchOrchestrator.php` — the loop + supervisor deterministic driver (`autoDelegateReadyTasks`, stall breaker, auto-extend).
  - `StartResearch.php` (`handle`, `spawnWorker`), `ContinueResearch.php` (follow-up guidance).
  - `Planner/` — `LlmPlanner` (calls LLM, streams "thinking"), `PromptBuilder` (system + per-turn prompts; **supervisor vs worker prompts branch here**), `DecisionParser`.
  - `Tools/ToolRegistry.php` — role-gates which tools each role sees. `ToolRunner.php` — executes with retries/tracing.
  - `Guardrails/` — preflight (limits/timeout/cancel) + action (duplicate/repeated-failure).
  - `Sandbox/SandboxClient.php`, `Browser/BrowserClient.php`, `Tracing/` (`ResearchTraceReader`, `DbTraceRecorder`).
- `app/Infrastructure/Research/Tools/` — one class per tool (search, sandbox: write/read/list/run_command/**start_server**/list_processes/kill_process, control: plan/delegate/review, self-authored "skills"). Register a tool by adding it to `ResearchServiceProvider::TOOLS`.
- `app/Domain/Research/` — `Contracts/` (interfaces incl. `LlmClient`, `Tool`, `ControlTool`), `Enums/` (`JobRole`, `TaskStatus`, `JobStatus`, `EventType`, `StepType`), `ValueObjects/` (`ResearchContext`, `Decision`, `ToolResult`, `GuardrailVerdict`).
- `app/Models/` — `ResearchJob` (role/parent/root, `supervisorShouldWait`, `depth`), `ResearchTask` (`depends_on`, `isReady`), `ResearchEvent` (append-only timeline), `ToolExecution`, `Human`/`HumanQuestion`, `Setting`, `CustomTool`.
- `app/Http/Controllers/Dashboard/` — `DashboardController` (jobs + flow), `SandboxController`, `OllamaController`, `SettingsController`, `HumanController`.
- `resources/views/dashboard/` — dark theme (charcoal + dark-reddish-pink accent), Tailwind CDN + Alpine, no build step. `show.blade.php` = the job "flow of thinking" chart; `sandbox.blade.php` = sandbox console/processes; `index.blade.php` = job list + new-job form (SOLO/SUPERVISED toggle).
- `services/sandbox/` — isolated Node/Express container the agent builds/runs code in (`server.js`): `/exec /serve /write /read /list /processes /ps /kill /info`. Playwright+Chromium preinstalled. Published host ports **8090–8099** (a server on any other port is NOT reachable from the host).
- `services/browser/` — Playwright browser microservice for the agent's web tools.
- `config/research.php` — all tunables (llm, limits, supervisor, sandbox, human, trace). `database/migrations/` — schema.

## Services & running

Docker Compose: `mysql`, `redis`, `ollama` (+`ollama-pull`), `sandbox`, `browser`, `app`, `nginx`, `worker`, `scheduler`. In dev the app is often run on the host via `php artisan serve` and the queue via `php artisan queue:work --queue=research` (run **2–3 workers** so parallel sub-agents actually run concurrently).

- Start a job (CLI): `php artisan research:start "<goal>"`. Trace it: `php artisan research:trace <job-id>`.
- LLM driver/model, API keys, limits are all **configurable at runtime via the Settings UI** (`settings` table overrides `config()`), applied per-iteration.

## Testing & conventions

- `vendor/bin/phpunit` (fast, uses MySQL test DB + `RefreshDatabase`). `vendor/bin/pint` before finishing.
- LLM in tests = `Tests\Support\FakeLlmClient` (scripted decisions). Any test that would dispatch `AdvanceResearchJob` must use `Queue::fake()` or it runs the real loop.
- Keep the suite green. Match surrounding code style (constructor DI, small classes, interfaces for swappable pieces).

## Footguns (hard-won — don't relearn these)

- **After changing tools/prompts/orchestrator, restart the queue worker** (`php artisan queue:restart` then relaunch `queue:work`) — a running worker holds old code.
- **Sandbox servers must bind a published port (8090–8099)**; `start_server` refuses others. `run_command` is for commands that FINISH (it hard-kills the process tree at timeout) — use **`start_server`** for anything long-lived.
- **Don't let the (weak) model own control flow.** If you're adding supervisor behavior, make the orchestrator decide and give the LLM a bounded sub-task. Free-form "pick the next tool" caused infinite loops.
- **A `write_file` may contain the model's own prompt scaffolding** ("CURRENT STATE"…); `WriteFileTool` strips it. The **supervisor must read the actual artifact** (`read_file`/`list_files`) before accepting a task — don't trust a worker's self-report.
- Keyless web search is unreliable (SERPs block bots); Tavily/Brave/SerpAPI keys make it dependable (Settings UI). Wikipedia is the reliable keyless seed.
- Redis may need a password in this env (`REDIS_PASSWORD`). `CACHE_STORE`/`QUEUE_CONNECTION` are redis.

## Human = "Father"

There is exactly one human, referred to as **Father**. `ask_human` is async and NEVER blocks the loop; questions surface in the "Father & Questions" UI. It's a last resort — exhaust the research/build loop first.
