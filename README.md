# Autonomous Research Agent

An autonomous AI research agent built on Laravel. You give it **one goal**; it
repeatedly decides what it needs, picks a tool, gathers information, evaluates
progress, and continues until it can produce a final report. It is **not** a
chatbot — there is no back-and-forth after the initial goal.

> **The one rule:** the LLM only ever *decides* the next step. Laravel does all
> the *executing, storing, resuming, limiting, and tracing*.

---

## Table of contents

- [How it works](#how-it-works)
- [Run with Docker (everything included)](#run-with-docker-everything-included)
- [LLM gateway & per-task routing (local + cloud)](#llm-gateway--per-task-routing-local--cloud)
- [Checking LLM connectivity](#checking-llm-connectivity)
- [Quick start](#quick-start)
- [Running fully offline (Ollama)](#running-fully-offline-ollama)
- [Watching what the agent does (tracing)](#watching-what-the-agent-does-tracing)
- [Adding a new tool](#adding-a-new-tool)
- [The human-in-the-loop](#the-human-in-the-loop)
- [Safety limits](#safety-limits)
- [Data model](#data-model)
- [Testing](#testing)
- [Layout](#layout)

---

## How it works

The planner loop is **one queued job per iteration** that re-dispatches itself
until the agent finishes. Nothing important lives in worker memory, so a crash
mid-run loses nothing — the next worker rebuilds the entire state from the
database and continues.

```
StartResearch ─▶ AdvanceResearchJob ─▶ ResearchOrchestrator.advance()
                        ▲                        │
                        │            ┌───────────┼─────────────┐
                        │        guardrails   planner (LLM)   tool runner
                        │            │           │             │
                        │          stop?     Decision       ToolResult
                        │            │           │             │
                        └── re-dispatch ◀── not finished ◀─────┘
```

Each turn the LLM returns exactly one JSON decision:

```json
{ "thought": "...", "action": "tool", "tool": "google_search", "arguments": { "query": "Company X revenue" } }
```
or
```json
{ "thought": "...", "action": "finish", "report": "...", "confidence": 0.91 }
```

Everything else (validating that JSON, running the tool, storing the result,
enforcing limits, recording the trace) is Laravel's job.

---

## Configuration: `.env` vs the Settings UI

Only what's needed to **boot** lives in `.env`: the **database**, **Redis**, the
queue/cache/session drivers, and `APP_KEY`. Everything else the agent needs —
**LLM driver & model, API keys (Anthropic, Tavily, Brave, SerpAPI), Ollama and
browser-service endpoints, the human's name, and safety limits** — is configured
**after startup** on the dashboard's **⚙️ Settings** page and stored in the
database (secrets encrypted).

Settings apply **live**: the web app picks them up on the next request, and the
worker re-applies them at the start of every iteration — so changing the model
or adding a search key takes effect on the next step, **no restart**. The `.env`
values for these are just first-run defaults.

## Run with Docker (everything included)

One command brings up the whole stack — MySQL, Redis, the **LiteLLM gateway**
(the model router), the API, the queue worker (the loop engine), and the
scheduler. **Ollama runs natively on your machine, not in a container**, so it
gets real GPU acceleration (a dockerized Ollama on macOS/Windows is CPU-only);
the containers reach it via `host.docker.internal`.

```bash
# 0. run Ollama natively and expose it to containers, then pull a model
#    macOS: set the host binding once, then restart Ollama.app
launchctl setenv OLLAMA_HOST "0.0.0.0:11434"
ollama pull qwen3:8b

# 1. build + start everything (Ollama is NOT a container by default)
docker compose up -d --build

# 2. verify the LLM wiring (host↔Ollama, gateway, container↔Ollama)
./scripts/check-llm.sh

# 3. start a research job
docker compose exec app php artisan research:start "Determine whether Company X is a good supplier"

# 4. watch the agent's path
docker compose exec app php artisan research:trace <job-id>
docker compose logs -f worker                            # live loop engine
docker compose logs -f litellm                           # gateway / model routing
```

The API is on **http://localhost:8080** (e.g. `POST /api/research`). What each
service does:

| Service | Role |
|---|---|
| `mysql` | all state: jobs, steps, tool executions, memory, **the event timeline**, humans |
| `redis` | queue backend — the planner loop runs on the `research` queue |
| `litellm` | **the LLM gateway/router** — fronts native Ollama + cloud providers behind one OpenAI-compatible endpoint (`:4000`) |
| `init` | one-shot: `composer install`, `key:generate`, `migrate`, seed humans |
| `app` | PHP-FPM serving the API |
| `nginx` | HTTP entrypoint on `:8080` |
| `worker` | `queue:work --queue=research` — executes every iteration |
| `scheduler` | `schedule:work` — re-evaluates the human-question queue every 10 min |
| `ollama` *(opt-in)* | dockerized Ollama, **off by default**; `--profile ollama-docker` for Linux or a container-only setup |

> **Prefer a dockerized Ollama?** `docker compose --profile ollama-docker up -d`
> then set `OLLAMA_BASE_URL=http://ollama:11434` in the compose `x-app-env` /
> `litellm` blocks. Pull into it with
> `docker compose --profile ollama-docker run --rm ollama-pull`. On macOS this is
> CPU-only — native is recommended.

---

## LLM gateway & per-task routing (local + cloud)

The agent talks to a **self-hosted [LiteLLM](https://github.com/BerriAI/litellm)
gateway** (`driver = openai_compatible`), which routes a single OpenAI-compatible
endpoint to **native Ollama AND cloud providers** (OpenAI, Anthropic, Gemini,
DeepSeek). Local models run offline; cloud models activate only when you're
online and that provider's key is set. It's generic OpenAI-compatible, so you can
point it at LocalAI, vLLM, or OpenRouter instead by changing the URL.

Models are named in [`services/litellm/config.yaml`](services/litellm/config.yaml)
— `local-fast` / `local-standard` / `local-hard` (Ollama) and `gpt-4o` / `claude`
/ `gemini` / `deepseek` (cloud). **Edit the local tags to models you've actually
`ollama pull`ed.** Provider keys live in the gateway's environment (the `litellm`
service), not the app; the app only holds the gateway's own key
(`LLM_GATEWAY_KEY`, which must equal `LITELLM_MASTER_KEY`).

**Per-task routing (no role→model hardcoding).** Each capability *tier* —
`light` / `standard` / `hard` — maps to a gateway model name (⚙️ Settings, or
`RESEARCH_LLM_TIER_*`). When a supervisor plans, it tags each task with a tier
("how hard is this?"), and the worker for that task runs on that tier's model —
so a hard task can burst to `claude` while everything else stays on a local
model, per task. A blank tier falls back to the default model. The model + tier
used are recorded in the trace and shown per-turn in the job-flow UI.

The **Tools** tab shows a live **gateway card**: reachable/down, and the exact
list of models it serves (the names a tier can point at).

## Checking LLM connectivity

`scripts/check-llm.sh` verifies the whole path in one command:

```bash
./scripts/check-llm.sh
```

It checks, with clear pass/fail and fix hints:

1. the host can reach **Ollama** and lists its models,
2. Ollama is exposed so **containers** can reach it (`OLLAMA_HOST=0.0.0.0`),
3. the **LiteLLM gateway** is up (`/health`) and lists its models,
4. the **gateway container can reach the host's Ollama** (the `host.docker.internal` hop).

Override endpoints/keys via env, e.g.
`LLM_GATEWAY_KEY=sk-… ./scripts/check-llm.sh`. Container checks are skipped (not
failed) when Docker isn't available.

---

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate

# MySQL + Redis assumed (see .env). Then:
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\HumanSeeder

# Run a queue worker for the "research" queue (this is the loop engine):
php artisan queue:work --queue=research

# In another shell, start a job:
php artisan research:start "Determine whether Company X is a good supplier"
```

You can also start one over HTTP:

```bash
curl -X POST http://localhost/api/research \
  -H 'Content-Type: application/json' \
  -d '{"goal":"Determine whether Company X is a good supplier"}'
```

---

## Running fully offline (Ollama)

The agent runs against a **local, offline** model with zero cloud calls. Only
the LLM adapter changes — the orchestrator, planner, tools, guardrails, and
traces are identical.

```bash
# 1. install Ollama (https://ollama.com), then pull a model:
ollama pull qwen3:30b         # or qwen2.5, mistral-nemo, etc.
ollama serve                  # usually already running on :11434

# 2. point the agent at it (.env):
RESEARCH_LLM_DRIVER=ollama
RESEARCH_LLM_MODEL=qwen3:30b
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_NUM_CTX=16384          # raise for longer research; costs RAM
OLLAMA_FORCE_JSON=true        # constrain output to valid JSON (recommended)
OLLAMA_THINK=false            # Qwen3 is a reasoning model — return JSON only
```

That's it — `research:start` now runs entirely on your machine.

**Notes for local models:**

- `OLLAMA_FORCE_JSON=true` uses Ollama's structured-output mode so even smaller
  models reliably honor the `Decision` JSON contract. The `DecisionParser`
  still validates, and the orchestrator feeds any violation back for a retry.
- Smaller models have smaller context windows. Lower `RESEARCH_TRANSCRIPT_WINDOW`
  (and/or raise `OLLAMA_NUM_CTX`) if you see truncation on long runs.
- Local inference is slower; `OLLAMA_TIMEOUT` (default 300s) covers that, and
  the per-iteration queue job timeout is separate.

This direct `ollama` driver is the simplest offline path (one box, one model).
For **mixing local + cloud models per task**, use the `openai_compatible` driver
in front of the LiteLLM gateway instead — see
[LLM gateway & per-task routing](#llm-gateway--per-task-routing-local--cloud).
Switching to a direct cloud model is still a one-line change:
`RESEARCH_LLM_DRIVER=anthropic`.

---

## Watching what the agent does (tracing)

This is the part built specifically so a supervisor can, **days later**,
reconstruct exactly what happened: *started with question X → used tool Y →
found Z → asked human X′ → …*

Every meaningful action flows through a single `TraceRecorder`, which writes to
**both**:

1. the **`research_events`** table — an append-only, ordered timeline, and
2. the **`research` log channel** — `storage/logs/research-YYYY-MM-DD.log`.

### The trace command

```bash
php artisan research:trace <job-id>
```

produces something like:

```
══════════════════════════════════════════════════════════════════
  RESEARCH TRACE  9f8c1e2a-…
══════════════════════════════════════════════════════════════════
  Goal:   Determine whether Company X is a good supplier
  Status: completed   Iterations: 7   Tool calls: 6   Confidence: 0.88
  Started: 2026-07-29 10:00:01   Finished: 2026-07-29 10:03:12
  Tools used: google_search ×4, ask_human ×1, calculator ×1

  ┌─ iteration 0
  #001 │ ▶ JOB STARTED: Research started for goal: Determine whether…
  ┌─ iteration 1
  #002 │ 🧠 THOUGHT: LLM produced a decision (842ms)
  #003 │ 🎯 TOOL SELECTED: Chose google_search: need company revenue first
  #004 │ ⚙ TOOL STARTED: Running google_search
  #005 │ 🔧 TOOL SUCCEEDED: google_search returned: Search results for "Company X revenue"… (312ms)
  #006 │ 👁 OBSERVATION: Observation from google_search: 1. Company X posts $1.2B…
  ┌─ iteration 2
  …
  #014 │ 📥 HUMAN QUESTION QUEUED: Queued (no human available): "Can you check the ERP for prior purchases?"
  #015 │ 🎯 TOOL SELECTED: Chose google_search: try to answer the ERP question another way
  …
  ┌─ iteration 7
  #027 │ ✅ FINISHED: Research complete (confidence: 0.88)

  FINAL REPORT:
    Company X appears to be a reliable supplier. Revenue ~$1.2B (source: …) …
```

Useful flags:

```bash
php artisan research:trace <job-id> --full            # include full payloads (prompts, raw LLM output, results)
php artisan research:trace <job-id> --iteration=3     # zoom into one iteration
php artisan research:trace <job-id> --type=thought,tool_selected
```

### Live logs

```bash
tail -f storage/logs/research-$(date +%F).log

# everything for one job:
grep '"job":"<job-id>"' storage/logs/research-*.log
```

Every log line carries `job`, `seq`, `iter`, and `event` context, so it is both
human-readable and machine-parseable.

### Over HTTP (for a dashboard)

```bash
curl http://localhost/api/research/<job-id>          # header + stats + timeline
curl http://localhost/api/research/<job-id>?full=1   # with full payloads
```

Because prompts and raw LLM output are stored (toggle via
`RESEARCH_TRACE_STORE_PROMPTS` / `RESEARCH_TRACE_STORE_RAW_LLM`), you can see not
just *what* the agent did but *why* — the exact prompt it saw and the exact text
it produced at every step.

---

## Tools & web search

Every capability is a `Tool`. These are registered by default (all **keyless**):

| Tool | What it does |
|---|---|
| `browser_search` | web search via the Playwright service (keyless backend) |
| `read_webpage` | open a URL in a headless browser, return readable text |
| `wikipedia` | encyclopedic summaries (Wikipedia API) |
| `stackoverflow_search` | programming Q&A (Stack Exchange API) |
| `hackernews_search` | tech news & discussion (HN Algolia API) |
| `arxiv_search` | academic papers (arXiv API) |
| `calculator` | exact arithmetic |
| `ask_human` | async escalation to the single human, **Father** |

### About keyless web search (read this)

There is **no reliable, keyless, general web-search API**. Search engines
actively bot-block headless scraping, and DuckDuckGo's keyless Instant-Answer
API only handles entity/definition queries. So `browser_search` is best-effort:
good for well-known entities, thin for open-ended queries. `wikipedia` is solid
for facts; `read_webpage` works on any URL that isn't itself bot-walled.

**For research-grade search, add one free-tier key on the ⚙️ Settings page.**
Each tool registers **only when its key is set** (an unset key is never offered
to the agent, so it can't waste a turn on a 401), and it appears **live** — no
restart:

| Tool | Provider | Free tier | Get a key |
|---|---|---|---|
| `tavily_search` | Tavily (built for AI agents — recommended) | ~1,000/mo | https://tavily.com |
| `brave_search` | Brave Search API | ~2,000/mo | https://brave.com/search/api |
| `google_search` | SerpAPI (Google) | ~100/mo | https://serpapi.com |

### The Playwright browser service (`browser_search`, `read_webpage`)

```bash
docker compose up -d --build browser      # starts it on :3001
```

Its health, endpoint, and a live test-search box are on the **Tools & Ollama**
page. Native (no Docker):

```bash
cd services/browser && npm install && node server.js    # serves on :3000
# then set BROWSER_SERVICE_URL=http://localhost:3000 in .env
```

> The browser tools drive public search/pages only. They do **not** automate
> login-gated proprietary UIs (e.g. ChatGPT) — that violates those terms and
> requires defeating bot-detection. For a free LLM, use the local Ollama driver.

## Building software (the code sandbox)

The agent can **write code and run/test it** in an isolated container — it never
executes anything on the host. Give it a goal like *"build a CLI that converts
CSV to JSON, with tests"* and it will write files, run the build/tests, read the
failures, fix them, and **iterate until it passes**.

```bash
docker compose up -d --build sandbox     # Node + Python + PHP + git + build tools
```

Tools it uses (each scoped to the job's own `/workspace/<jobId>`):

| Tool | What it does |
|---|---|
| `write_file` | create/overwrite a file |
| `read_file` | read a file back |
| `list_files` | see the project tree |
| `run_command` | run a shell command → **exit code + stdout + stderr** (install, build, test, run) |
| `container_logs` | recent command history from the sandbox |

The loop is prompt-driven: *write → run tests → read exit code + stderr → fix →
re-run*. A non-zero exit is information, not a dead end. The agent only finishes
after it has actually run the program and seen it work.

### Installing packages / other languages

The agent runs commands as **root with network access** in the container, so it
installs its own dependencies — you never intervene:

- **Pre-installed:** Node, Python, PHP, **Rust (`cargo`/`rustc`), Go**, git, build tools.
- **Anything else** it installs on demand: `apt-get install …`, `pip install …`,
  `npm i …`, `cargo add …`, `go get …`. Cargo/Go/pip caches are on persistent
  volumes, so re-installs are fast and survive container restarts.

The "jail" is *only* a filesystem scope for the file tools (`write_file`/`read_file`
stay inside `/workspace/<jobId>`) — it does **not** restrict what `run_command`
can install or execute. Everything happens inside the isolated container, never
on your host, which is the whole point of running it in Docker.

### Self-authored skills

If the agent builds a handy reusable command, it can `save_custom_tool` (name +
description + shell command) and later `run_custom_tool`. Saved skills appear on
the **Tools & Ollama** page, where you can **Promote** the useful ones (a flag +
their exact command) so you can build them into the core as real `Tool` classes.

> **Safety.** The sandbox runs arbitrary code by design — that isolation is the
> point. It's a resource-limited container (`mem_limit`, `pids_limit`) with only
> a persisted `/workspace` volume, no host mounts. It DOES have network (needed
> for `npm`/`pip` installs), so don't put secrets in it or expose it publicly.
> For stronger isolation, run it on a separate host or add `--network` controls.

## Adding a new tool

The entire extension surface is one class + one line.

1. Implement `App\Domain\Research\Contracts\Tool`
   (copy `app/Infrastructure/Research/Tools/CalculatorTool.php` as a template).
2. Add it to the `TOOLS` list in
   `app/Providers/ResearchServiceProvider.php`.

Done — it's now in the catalogue the LLM sees, validated against your JSON
schema, executed with retries, and traced. No orchestrator changes.

---

## The human-in-the-loop (Father)

There is exactly **one** human — **Father** (name configurable via
`RESEARCH_HUMAN_NAME`) — seeded by `HumanSeeder`. `ask_human` is **asynchronous
and never blocks the agent**:

- If Father is `available`, the question is sent to him (event `HumanQuestionAsked`).
- If not, the question is **queued** and the agent is told to keep researching
  and try to answer it from other sources.
- The same question is **never submitted twice** — a repeat returns the existing
  question's status (or its answer, if already answered), no duplicate row.
- When Father answers (`POST /api/questions/{id}/answer`), the answer is injected
  into the job's memory and the loop resumes if it had gone idle.
- When Father comes online (`POST /api/humans/{id}/status`), the queue is
  reconciled: questions already answered elsewhere are dropped, the rest deduped,
  reprioritised, batched, and only the highest-value ones asked.
- A scheduled `ReevaluateHumanQueueJob` does the same every 10 minutes.

The **Father** page lists only questions from **active** research (finished jobs'
questions are hidden). The `humans` table can technically hold more rows, but the
seeder enforces the single Father.

---

## Safety limits

Enforced by the guardrail pipeline (all in `config/research.php`):

| Concern | Guardrail | Behaviour |
|---|---|---|
| Infinite loops | `MaxIterationsGuardrail` | best-effort report at the cap |
| Runaway tool use | `MaxToolCallsGuardrail` | best-effort report at the cap |
| Never-ending jobs | `TimeoutGuardrail` | best-effort report past deadline |
| Supervisor stop | `CancellationGuardrail` | clean stop between iterations |
| Duplicate searches | `DuplicateActionGuardrail` | intercept + nudge the agent |
| Repeated failures | `RepeatedFailureGuardrail` | stop hammering a broken tool |
| Hallucinated JSON | `DecisionParser` | reject + feed the error back; fail job after N |

Tool failures never crash the loop — they become observations the agent routes
around. Add a new guardrail the same way as a tool: one class + the `GUARDRAILS`
list.

---

## Data model

| Table | What it holds |
|---|---|
| `research_jobs` | goal, status, config, counters, final report — all resume state |
| `research_steps` | per-turn thought / action / observation / finish |
| `tool_executions` | every tool call: args, result, fingerprint (dedup), retries, duration |
| `research_messages` | the LLM conversation memory (what we replay each turn) |
| `research_events` | **the timeline** — the ordered story used by `research:trace` |
| `humans` | responders with expertise, permissions, availability |
| `human_questions` | the async ask_human queue with resolution status |

---

## Testing

```bash
php artisan test
```

`tests/Support/FakeLlmClient.php` lets you script the agent's decisions and
drive the whole loop with **no network calls** (see
`tests/Feature/ResearchLoopTest.php`). This is possible because every external
dependency (LLM, repositories, tools, tracing) sits behind an interface.

---

## Layout

```
app/
├── Domain/Research/          # contracts, value objects, enums (no framework deps)
├── Application/Research/      # orchestrator, planner, tools, guardrails, human, tracing
├── Infrastructure/Research/   # LLM client, tool implementations, Eloquent repositories
├── Jobs/  Events/  Listeners/ # queue + event-driven glue
├── Http/Controllers/          # start / trace / cancel / human answer
└── Console/Commands/          # research:start, research:trace
```
