# LLM ↔ Runtime redesign — analysis, options, recommendation

Status: **discussion document. Nothing here is implemented.**
Scope: the 10 topics in the refactoring brief, judged against the code as it stands today.

---

## 0. Verdict up front

The brief's framing is *"the LLM should invoke methods on a runtime instead of generating JSON."*
I agree with the goal and disagree with where the brief puts the weight.

The unreliability in this system does not come from "JSON" as a data format. It comes from **three
distinct leaks**, and the brief's ten topics attack them very unevenly:

| # | Leak | What it costs today | Which brief topic fixes it |
|---|------|--------------------|---------------------------|
| **L1** | **Channel leak** — the *envelope* (`{"action":"tool","tool":…}`) is authored by the model inside free text, instead of being carried by the provider's typed tool-call channel or a constrained grammar. | `DecisionParser::parseLenient` + `salvageArguments`, the `{"action":"review_task"}` slip in the handoff notes, `max_parse_failures` killing jobs, `LlmPlanner::partialThought` (a 40-line escape-state machine hand-scanning partial JSON), the "thinking must be ON or the envelope breaks" footgun. | **Not on the list.** Topic 5 addresses the *description*, not the channel. |
| **L2** | **Type leak at execution** — schema validation happens once in `DecisionParser`, then all type information is discarded. `execute()` re-reads an untyped array through `ToolArguments::string()`, which silently coerces and defaults to `''`. | Every tool re-validates by hand. `SubmitReviewTool::execute` re-checks `verdict ∈ {accept,revise}` even though its schema already declares that enum. `PlanTasksTool` accepts any string as `tier` and silently nulls it. | Topics 2 + 4. |
| **L3** | **Policy leak** — role permissions live as string arrays *inside* `ToolRegistry::definitions()` (`$supervisorExtras`, `$reviewerAllow`, `$supervisorHidden`), not on the tools. | CLAUDE.md claims "register a tool = one line in `ResearchServiceProvider::TOOLS`". That is false for any tool a reviewer or supervisor should see — you must also edit `ToolRegistry`. Adding a tool silently gives it to workers only. | Topic 2 (permissions). |

**L1 is worth more than topics 1, 2, 3 and 5 combined**, and it is the one topic the brief does not
name. If the decision envelope moves into the provider's typed channel (or a grammar-constrained
decode), an entire category of failure stops existing rather than being handled more gracefully.

And one **anti-recommendation**: topic 1 as written — a Runtime that owns *"tool registry, validation,
execution, permissions, retries, state, memory, error handling, and the execution loop"* — is a
description of `ResearchOrchestrator` (948 lines) plus a rename. That is the main risk in this brief.
The seam worth introducing is narrow: **a typed call boundary**, not a god object.

---

## Grounding: what the code actually does today

Worth stating precisely, because several brief items are already built.

- `Tool` = `name()` + `description()` + `schema()` (hand-written JSON Schema array) + `execute(ToolArguments, ResearchContext): ToolResult`. PHP *is* already the single source of truth for schemas (topic 2's stated goal is half-achieved) — they are just hand-written and unvalidated.
- `DecisionParser` already validates arguments against the tool's JSON Schema with `justinrainbow/json-schema` and throws `InvalidDecisionException`, which the orchestrator converts into a corrective observation bounded by `max_parse_failures`. **Topic 4 is ~60% built.** The missing parts are: typed errors (everything is one exception class carrying a string), permissions (never checked at call time — only at catalogue time), and the *recoverable vs fatal* distinction.
- `HumanAvailabilityService`, `QuestionQueueManager`, `HumanQuestionRepository`, priority on questions, dedup, queueing, `ReconcileQueueOnAvailability` — **topic 7 is ~70% built.** What's missing is multi-human routing and batching, which nothing currently needs (there is exactly one human).
- Guardrails already run as a two-phase pipeline populated by container tags (`GuardrailPipeline`) — **topic 8's "replaceable stages" exists for the pre-execution half.** It does not exist around execution itself (`ToolRunner` hard-codes retry + trace).
- `ModelRouter` + tiers + `ModelAvailability` cooldown already give per-task model routing and failover — **topic 10's "multiple providers" is largely solved at the *model* layer** by delegating to LiteLLM. What is *not* solved is capability negotiation: `LlmClient` is `complete(string $system, array $messages): string`. A string in, a string out. That interface cannot express tool calls, token usage, finish reasons, images, or "this model supports X".

So the honest gap list is shorter than the brief implies: **the channel (L1), the execute-side typing (L2), declarative permissions (L3), and the `LlmClient` interface**. Most of the rest is polish or YAGNI.

---

## Cross-cutting ideas worth putting on the table now

These recur through the topics below; naming them once keeps the rest short.

### A. Capability IR + renderers (one definition, N wire formats)

Define a tool **once** as a structured value (`ToolDescriptor`: namespaced name, summary, parameter
tree, return shape, permissions, side-effect class, cost hint). Then *render* it into whichever
format the current model's channel accepts:

- OpenAI/Anthropic **native `tools` payload** (JSON Schema — free, the IR already is one);
- a **JSON-schema grammar** for constrained decoding (`response_format: json_schema` → Ollama `format`);
- a **signature-style doc block** for models with neither (this is what topic 5 asks for — it is *one renderer*, not the architecture);
- an **MCP tool descriptor** (`Tool` is already nearly isomorphic to one).

This is the actual answer to topics 2, 3, 5 and 10 simultaneously. The brief treats "prompt as API
docs" as the destination; it is one output of the IR, and the *least* preferred one.

### B. Affordance narrowing (dynamic toolsets)

Today the toolset is a function of **role**. Make it a function of **role + current job state**.

A supervisor with zero tasks has exactly one legal move: `plan_tasks`. A reviewer that has already
called `submit_review` has none. A supervisor with one task awaiting review and no reviewer agent
available has exactly one legal target. Instead of the prompt *asking* for the right choice
("always prefer reviewing an awaiting task"; "► REVIEW NOW: task #3 — do NOT review any task not in
this list"), **present only the legal calls**.

This is the logical conclusion of the project's own "blueprint first, model second" rule, it is
cheap, and it deletes prompt text rather than adding it. For a weak model, *removing options* is the
highest-yield reliability lever available.

### C. Injected parameters (DI for tool arguments)

Any argument the runtime can determine should not be in the model-facing schema at all. `review_task(task: int)` when exactly one task is awaiting review is a pure invitation to error — and the code already fights it with a pinned "REVIEW NOW" block in the prompt *and* a defensive branch in `ReviewTaskTool::execute` *and* an anti-livelock park in the orchestrator (`5a-bis`). Bind the parameter and all three disappear.

Same principle as a Laravel controller action: the framework supplies what it knows, the caller supplies only what it must choose.

### D. Value ranges belong in the schema, not the prose

Live examples of things that are *stated in English* but *expressible as types*:

- `PlanTasksTool` `tier` is `['type' => 'string']` with the valid names listed only in the description, then `execute()` silently discards an unrecognised value. It should be an `enum` built from `config('research.llm.tiers')` keys.
- `start_server` port: the prompt spends five lines on "8090–8099 only", `StartServerTool` refuses others at runtime, and the model still gets it wrong. It should be an `enum: [8090..8099]` — structurally unable to be wrong.

Both are one-line fixes available *today*, independent of any refactor, and they are exactly the "invalid enum values" rejection topic 4 asks for.

### E. Events for observation, never for control

`ResearchAdvanced` / `ResearchCompleted` / `ResumeSupervisorOnChildDone` already exist. Keep events
as the *observation* and *wake-up* substrate. Do not move decision flow onto them. This project has
already paid for one non-deterministic control flow; an event-driven runtime would reintroduce it in
a form that is much harder to read in a trace.

---

## 1. Runtime abstraction

**Motivation.** There is no single place that owns "a model wants to make a call → the call happens
or fails with a reason." That logic is smeared across `LlmPlanner` (build prompt, call model),
`DecisionParser` (parse + validate), `ResearchOrchestrator` (guardrails, trace, memory, reschedule)
and `ToolRunner` (retry, persist). Testing "does an invalid permission get rejected" requires driving
the whole orchestrator.

**Advantages of extracting one.** A single seam to fake in tests. One place to add permissions,
metrics, rate limits. A second agent type (not research) could reuse it without inheriting the
supervisor logic.

**Disadvantages.** As specified in the brief — registry + validation + execution + permissions +
retries + state + memory + errors + the loop — it is a god object, and it is a *rename* of the
orchestrator rather than a decomposition. It also invites a rewrite of code that currently works and
whose every branch was paid for with live debugging (the delegate-thrash loop, the empty-completion
crash, the `num_ctx` truncation). A big-bang runtime risks re-earning those.

**Alternatives.**

1. *Nothing* — keep the orchestrator; add only the typed call boundary. Cheapest.
2. **Kernel / Dispatcher / Session split** (recommended): `AgentKernel` owns the loop (this *is* the orchestrator, unchanged); `CallDispatcher` is the new narrow seam — `dispatch(Call, ToolContext): CallOutcome`, running the middleware pipeline (topic 8) and nothing else; `AgentSession` is the per-turn state (this *is* `ResearchContext`, narrowed). Memory and state stay in repositories where they are.
3. Full framework extraction into a package with its own domain. Correct destination if this becomes a product; wrong first move.

**Future limitations.** A synchronous `dispatch()` cannot express a long-running or streaming tool
(a build that takes 20 minutes, a subscription). The current answer is `ToolResult::pause()` +
event wake-up, which works but is bespoke per case. If remote/async tools become common, `dispatch()`
must be able to return a *pending handle* the kernel parks on — design the return type with that in
mind even if only the sync case is implemented.

**Recommendation.** Option 2. Introduce `CallDispatcher` and leave `ResearchOrchestrator` as the
kernel. Do not create a class called `Runtime` that owns everything. Judge the split by one test:
*can I unit-test "reviewer calls write_file → PermissionDenied" without touching the orchestrator?*
If yes, the seam is in the right place.

**Brainstorm — answers to the posed questions.**

- *Service?* Yes, container-resolved and stateless; per-turn state travels in `ToolContext`.
- *Event-driven?* Emit events; never dispatch on them. See idea E.
- *Fluent API?* Not model-facing. Fluent *builders* are valuable for tests: `Runtime::fake()->allows('fs.write')->expects('sandbox.run')`.
- *Multiple runtimes?* No. What you actually want is **agent profiles**: `(role, toolset, policy, prompt fragments, channel, budget)` as declarative data. Then "supervisor", "worker", "reviewer" — and a future "triage agent" — are *config*, not subclasses. That is what makes this "a framework for many autonomous systems" rather than one research agent, and it's cheaper than N runtimes.
- *Composable?* Composing runtimes is a distributed-systems problem you do not have. Composing *middleware* and *profiles* gives the same flexibility at a fraction of the cost.

---

## 2. Tool registry

**Motivation.** Three real problems, not one:

1. Schemas are hand-written PHP arrays — nothing checks they are valid JSON Schema, nothing checks the keys `execute()` reads exist in them. A typo in a property name is invisible until a model calls it.
2. Permissions live in `ToolRegistry::definitions()` as literal string arrays. Adding a tool a reviewer needs requires editing `$reviewerAllow`. This directly contradicts the documented "one line to add a tool".
3. Names are a flat namespace of 22 strings, with no grouping the model can use to reason ("everything under `sandbox.` touches the container").

**Advantages of attribute/reflection-derived descriptors.** Schema cannot drift from the signature.
Permissions and side-effect class become declarative and greppable. A `php artisan agent:tools` dump
becomes possible. Contract tests can auto-discover and validate every tool.

**Disadvantages.** Indirection — a reader can no longer see the schema by reading the class. More
magic to debug. Some schemas are *dynamic* (`PlanTasksTool` builds its `tier` help from config at
call time; a future `run_custom_tool` may enumerate saved tools) and cannot be fully static. Twenty
extra input-DTO classes is real weight in a codebase that currently keeps tools to ~60 lines each.

**Alternatives.**

| Option | Verdict |
|---|---|
| Attributes + reflection over `execute()`'s **signature** (`execute(string $path, string $content, ToolContext $ctx)`) | Best ratio. No DTO classes for the ~15 tools with ≤4 scalar params. `#[Param('Path relative to workspace')]` supplies descriptions. |
| Attributes + a typed **input DTO** per tool | Necessary for nested structures (`plan_tasks` takes an array of task objects). Use where it earns its keep, not everywhere. |
| Keep hand-written `schema()`, add a **validation test** that every schema is well-formed and every key read by `execute()` is declared | The 10% solution that captures much of the value in a day. Worth doing *first* regardless. |
| Code generation (build-time manifest file) | Correct for production cost, wrong as the authoring model. |
| Auto-discovery by directory scan vs the explicit `TOOLS` const | Keep the explicit list. It is a feature: a tool cannot be enabled by accident, and the list is a readable inventory. Auto-discovery saves one line and costs auditability. |

**Future limitations.** Reflection cost is negligible here (a 1–2 minute LLM turn dwarfs it), but a
cached manifest keyed by a hash of the tool set will be wanted eventually — and that manifest hash is
also the right thing to stamp on a job so an old trace can be read against the definitions in force
at the time. Renaming tools to a namespace (`fs.write`) **invalidates**: `ToolExecution::fingerprint`
(so `DuplicateActionGuardrail` stops matching history), every `tool_executions.tool_name` row, every
stored transcript, and the `$reviewerAllow`-style lists. If you namespace, ship an alias map and keep
old rows readable.

**Recommendation.** Sequence it:

1. **Now, cheap:** a contract test that auto-discovers every registered tool and asserts the schema is valid JSON Schema, has `additionalProperties: false`, has non-empty descriptions, and that its declared `required` keys are the ones `execute()` actually reads. Fix `tier` and `port` to be enums (idea D).
2. **Then:** move permissions onto the tool — `#[AvailableTo(JobRole::Reviewer, JobRole::Supervisor)]` or a `visibility(): ToolVisibility` method — and delete the three arrays in `ToolRegistry`. This is small, high-value, and unblocks affordance narrowing (idea B).
3. **Then, if still wanted:** reflection-derived schemas. Do it for new tools first; migrate old ones opportunistically. Both styles can coexist behind the descriptor IR.
4. Namespaces: adopt as *metadata* (`group: 'sandbox'`) used for prompt grouping, and defer renaming the wire names until there is a reason worth the migration.

**Brainstorm.** *Attributes vs interfaces*: attributes for metadata, interfaces for behaviour — the current `ControlTool` marker is a permission expressed as a type, which is why it's rigid; make it an attribute. *Versioning*: don't version tools individually; version the manifest and record its hash per job. *Contract tests* are the highest-leverage "easy to add tools" feature in the whole brief — a new tool automatically inherits a suite.

---

## 3. Runtime API (`browser.search(query)` style)

**Motivation.** A pretty-printed JSON Schema catalogue for ~18 tools is injected into *every* worker
turn (`PromptBuilder::workerSystem` → `json_encode($ctx->toolDefs, JSON_PRETTY_PRINT)`). On a 16k
context that is a large, repeated, low-density block. Signature-style rendering carries the same
information in roughly a third of the tokens, and reads like something the model has seen a million
examples of.

**Advantages.** Token savings go straight into transcript budget — which is the binding constraint
here (see the `num_ctx` footgun). Grouping by namespace gives the model a mental model of the
surface. Return types tell it what to expect without a paragraph of prose.

**Disadvantages.** A pseudo-language is *not* a language: the model may produce something that looks
like a call (`browser.search("x", limit=5)`) which you must then parse — you have traded a JSON
parser for a worse parser. **If the prompt shows call syntax, the model will eventually emit call
syntax.** Also, a hand-rolled notation has no training-data prior; OpenAI-shaped tool JSON has an
enormous one.

**Alternatives.**

1. **Native tool calling** — the model never sees a catalogue in the prompt at all; the API carries it. Strictly better where supported. *This is the real version of topic 3.*
2. Signature-style docs **+ JSON output** (docs are documentation only; the reply is still JSON/constrained). Recommended for the fallback channel.
3. Signature-style docs **+ pseudo-call output** (the literal reading of the brief). I recommend against: it re-creates L1 in a new syntax.
4. TypeScript declaration blocks (`declare function search(query: string): SearchResult[]`) — a well-known prior, and free from the same IR.

**Future limitations.** A single-call-per-turn API cannot express parallel calls, and native tool
APIs *do* return arrays of calls — you will need an explicit "take the first / set `parallel_tool_calls: false`" policy. Also, once the prompt describes an API, versioning it becomes a real
concern: a task resumed after a tool signature changed will have a transcript describing the old one.

**Recommendation.** Adopt the API *presentation* (grouped, signature-style, return types) as the
renderer for the text/constrained channel, and keep the *response* structured — JSON produced by the
API's tool channel or by a grammar. Never parse a pseudo-language. Concretely:

```
sandbox.write(path: string, content: string) -> { path, bytes }
sandbox.run(command: string, timeout?: int = 120) -> { exit_code, stdout, stderr, timed_out }
sandbox.serve(command: string, port: 8090|8091|…|8099) -> { reachable, url, log }
web.search(query: string) -> SearchResult[]   // { title, url, snippet }
human.ask(question: string, priority?: 1..10) -> Deferred
```

**Brainstorm.** Optional params with defaults shown inline are worth the tokens — they remove a whole
class of "what do I pass for timeout" wobble. Return types matter more than you'd expect for a weak
model: `-> { exit_code, stdout, stderr }` tells it that reading `exit_code` is a thing to do, which is
currently ten lines of English in the worker prompt. Async: express as a return type (`-> Deferred`)
rather than a keyword — `ask_human` is already exactly this and the prompt spends a paragraph
explaining it.

---

## 4. Strongly typed tool calls

**Motivation.** Validation exists but stops at the boundary. Past `DecisionParser`, everything is an
untyped array again, so tools defensively re-validate (`SubmitReviewTool` re-checks its own enum) or
silently accept garbage (`PlanTasksTool` nulls an unknown tier; `ToolArguments::string()` returns `''`
for a missing key). Permissions are never checked at *call* time at all — only at catalogue time,
which means the check is "did we tell it about this tool", not "is it allowed to run it".

**Advantages of closing it.** Tools become total functions over valid input: no defensive branches,
no silent coercion. Static analysis works. Permission violations become detectable and *traceable* as
a distinct event rather than an unexplained absence.

**Disadvantages.** More classes/annotations. And an important subtlety: **stricter rejection is not
automatically better for a weak model.** `parseLenient` and `salvageArguments` exist because a
rejection costs a whole 1–2 minute iteration. Tightening validation without first removing the cause
(L1) would make the agent *slower and more failure-prone*, not less. Order matters.

**Alternatives.**

- JSON Schema validation (today) + typed binding into the tool signature (add).
- Laravel's `Validator` — familiar, but its rules are not renderable back into a schema for the model. Rejected: the schema must be the single source.
- Grammar-constrained decoding — makes structural invalidity *impossible* rather than *rejected*. Strictly stronger than validation for the shape; does nothing for semantics (wrong tool, wrong file path).
- Optimistic execution with compensating errors (what happens today).

**Error model.** Replace the single `InvalidDecisionException` + stringly `ToolResult::fail()` with a
small hierarchy carrying *machine* fields, and one renderer that turns them into the model-facing
observation:

| Class | Recoverable | Runtime response |
|---|---|---|
| `UnknownTool` | yes | list the legal calls (already done, keep) |
| `InvalidArguments` | yes | field-level errors: `path: required`, `tier: must be one of light/standard/hard` |
| `PermissionDenied` | yes, but **do not re-offer** the tool | tell it what it *can* do instead; ideally the tool was never visible (idea B) |
| `PreconditionFailed` | yes | "task #3 is not awaiting review; #5 is" — this is `ReviewTaskTool`'s existing redirect, promoted to a type |
| `TransientFailure` | retried in-runtime | invisible to the model unless retries exhaust (today's `RetryableToolException`) |
| `FatalToolFailure` | no | fails the tool, agent must route around |
| `BudgetExceeded` | no | terminal, orchestrator finalizes |

The key design rule: **an error the runtime can fix should never reach the model**, and an error that
reaches the model must say *what to do next*, not just what went wrong. The existing corrective
observations already do this well in places — this just makes it uniform and typed.

**Future limitations.** Field-level errors get expensive on nested structures (`plan_tasks` with 30
tasks, one bad tier). Cap the error payload and prefer "reject the one bad item, accept the rest,
report what was dropped" for list-shaped inputs — currently `PlanTasksTool` silently skips invalid
tasks, which is the worst of both.

**Recommendation.** Do the error hierarchy and the typed binding — but **after** the channel work,
not before, for the reason above. Keep the lenient paths in `DecisionParser` until the channel makes
them dead code, then delete them with evidence rather than on principle.

**Brainstorm.** Retry strategy should be per error class, not global (`tool_attempts: 3` currently
applies uniformly). A `PermissionDenied` should never be retried; a `TransientFailure` should be
retried without ever consuming an agent iteration; an `InvalidArguments` should be retried *by the
model* but ideally within the same turn if the channel is cheap enough — a "repair turn" that re-asks
with the error attached and doesn't count against `max_iterations` is worth prototyping.

---

## 5. Prompt generation

**Motivation.** `PromptBuilder` is 532 lines and holds three near-duplicate heredocs. The response
contract is restated three times, each with its own hand-written WRONG/RIGHT examples. Prose and
tool catalogue are interleaved with no separation of concerns, and a large fraction of the text is
compensating for problems fixed elsewhere in code.

**Advantages of a documentation generator.** Tool docs can never drift from the tools. Role prompts
become composed fragments (shared contract block + role block + policy block) instead of copies.
Token cost drops.

**Disadvantages.** Prompt wording here is *load-bearing and hard-won* — several blocks exist because
a specific weak-model failure was observed live. Regenerating prompts wholesale is the single most
likely way to silently regress this system, and the regression won't show up in `phpunit`; it shows
up two hours into a supervised run. Any change here needs an A/B replay harness (see topic 8) or
it's a coin flip.

**Alternatives.** Markdown; PHPDoc-style; OpenAPI-ish; TypeScript declarations; or **no tool docs at
all** because the API carries them (native tool calling).

**Future limitations — the important one.** *The value of topic 5 is inversely proportional to the
success of the channel work.* If native tool calling lands for the models you actually use, the tool
catalogue leaves the prompt entirely and this topic shrinks to "compose the three role prompts from
shared fragments." Building an elaborate documentation generator first, then removing its output from
the prompt, would be wasted work. **Sequence topic 5 after the channel decision.**

**Recommendation.** Split `PromptBuilder` into `PromptComposer` (fragments: contract, role, policy,
state) + `ToolDocRenderer` (a renderer over the descriptor IR, used only on the text/constrained
channel). Do the *fragment* split now — it's mechanical, safe, and removes triplicated text. Defer
the doc renderer until the channel is decided.

**Brainstorm.** Whatever the format, group tools by namespace and put the *policy* about a tool next
to its signature rather than in a distant RULES block — the "use `sandbox.serve`, never
`sandbox.run`, for servers" advice belongs three lines from `sandbox.serve`'s signature, not 40 lines
away. Better still, make it unnecessary (see topic 9).

---

## 6. Runtime state

**Motivation.** The brief says "move logic from prompts into deterministic code". Much of this is
already done — and done well. `DuplicateActionGuardrail`, `MaxIterations`, `Timeout`,
`RepeatedFailure`, the stall breaker, `ModelAvailability` cooldown, `ArtifactChecks`, deterministic
delegation, deterministic finish. The remaining question is not "should rules move to code" but
"**should the state machine be explicit?**"

Today the state machine is implicit: `JobStatus` × `TaskStatus` × `activity` string × a Redis stall
counter × `attempts` × `parse_failures`. Legal transitions are enforced by branch order inside
`advance()` — which is why that method is ~200 lines with numbered comments (`1`, `1a2`, `1b`, `5a`,
`5a-bis`) acting as an informal state diagram.

**Advantages of making it explicit.** A transition table can be tested exhaustively, drawn, and used
to answer "how did this job get here" directly from the trace. `5a-bis` (the anti-livelock park)
becomes a named transition rather than a comment-documented special case.

**Disadvantages.** A workflow engine is a large dependency and a new mental model for a system whose
state already survives crashes correctly via the DB + queue design. Event sourcing in particular
would be a big rewrite for a benefit (`research_events` is already an append-only timeline) you have
90% of.

**Alternatives.** (a) Leave it; (b) extract the transition rules into a `SupervisorPolicy` class with
a table, called from `advance()` — no new dependency, testable in isolation; (c) a real state-machine
package; (d) a workflow engine (Temporal-style); (e) event sourcing.

**Future limitations.** The stall counter lives in `Cache` (Redis), not the DB — it does not survive
a Redis flush and is not visible in a trace. Minor, but it is state that behaves differently from
everything else and will confuse someone at 2am.

**Recommendation.** Option (b) only. Extract `SupervisorPolicy::nextAction(JobSnapshot): Action`
returning an enum (`Delegate`, `DispatchReviews`, `Park`, `Plan`, `Finish`, `Extend`) — pure, no I/O,
exhaustively testable — and let `advance()` execute the returned action. That converts the numbered
comments into a real, tested decision table without adopting a workflow engine. Move the stall counter
to the jobs table while you're there. **Skip event sourcing and workflow engines.**

**Brainstorm.** Checkpoints/recovery are already solved by "the queue is the loop" — don't re-solve
it. The genuinely missing observability piece is a *why* channel: a trace event per policy decision
("parked: 2 workers in flight, 0 awaiting review") so a supervisor reading a trace days later sees
the reason and not just the effect. That is cheap and worth more than a state-machine library.

---

## 7. Human resource

**Motivation.** The brief asks for a Human Manager owning availability, priority, queue, batching,
answer history, expertise, permissions. Availability, priority, queue, dedup, history and
resume-on-answer already exist. Expertise, batching and multi-human do not — and there is exactly one
human by design ("Father").

**Advantages of building the rest.** Multi-human routing is genuinely useful *if* this becomes a
product with a team behind it. Batching (ask three queued questions at once) reduces the human's
context-switch cost.

**Disadvantages.** Building expert routing for a population of one is speculative generality. It also
competes for effort with L1/L2/L3, which affect every single run.

**Alternatives.** Leave as is; add a thin `HumanPolicy` (should we ask now, queue, or refuse?) without
touching the storage model; full multi-human with routing.

**Future limitations.** The one thing that *will* bite: `AskHumanTool` decides policy inline (dedup →
availability → assign or queue). Any policy change (rate limits, "no more than N questions per job",
"never ask before 10 tool calls") means editing the tool. That is the same L3 shape as permissions —
policy embedded in a leaf.

**Recommendation.** Extract `HumanPolicy` (a decision object: `Ask | Queue | Refuse(reason)`) out of
`AskHumanTool`, keep everything else. Defer multi-human and expertise until there is a second human.
Note that the *prompt* currently enforces "last resort" with English; a policy object can enforce it
with a rule (e.g. refuse before N tool calls in this job) — that is a real topic-9 win.

**Brainstorm.** If multi-human ever lands, model it as a **capability-matching problem reusing the
tier machinery** (`ModelAvailability` is already "pick an available responder with the right
capability" — humans are the same shape as models). One mechanism, two populations, is much better
than two parallel routing systems.

---

## 8. Tool execution pipeline

**Motivation.** Cross-cutting concerns are hard-coded: `ToolRunner` does retry + persistence + trace
in one method; guardrails run in the orchestrator before it; permissions run nowhere; there is no
place to add metrics, rate limiting, cost accounting, or result normalization without editing
`ToolRunner`.

**Advantages of a middleware pipeline.** Each concern is testable alone and composable per profile
(a `reviewer` profile could add a "read-only enforcement" middleware; a future untrusted profile
could add sandbox egress checks). Laravel already ships `Illuminate\Pipeline`, so this is nearly free.

**Disadvantages.** Pipelines obscure control flow in stack traces — debugging "why did this call get
blocked" means reading a list of middleware. Order becomes load-bearing and implicit. Over-decomposed
pipelines (12 one-line stages) are worse than the current explicit method.

**Alternatives.** Middleware pipeline; decorators around `Tool`; event hooks (rejected — see idea E,
you cannot *block* a call from an event listener cleanly); keep it explicit and add only the two
missing stages.

**Future limitations.** The pipeline must be able to *短-circuit* with a typed error and must carry
the same `ToolExecution` row through, or the audit trail fragments. Design the pipeline's passed
object as the execution record itself, not just the arguments.

**Recommendation.** A 5-stage pipeline, no more: `Authorize → Validate/Bind → Execute(retry) →
Normalize → Record`. Guardrails stay where they are (they are *pre-decision*, about the loop, not
about the call). Explicitly register the order in one array so it's readable.

**Brainstorm — the highest-value item in this whole document that isn't L1.** Because every prompt and
raw completion is already traced (`research.trace.store_prompts`), you can build:

```
php artisan agent:replay <job-id> --turn=7 --channel=native --model=gpt-4o
```

Re-run a *recorded* turn against a different channel/model/prompt and diff the resulting decision.
That turns "should we adopt native tool calling?" and "did this prompt change help?" from opinions
into measurements, using data you already store. Build this **before** topics 1–5, not after.

---

## 9. Prompt vs runtime responsibilities

Every substantive rule currently in `PromptBuilder`, judged. "Move" means it can be enforced
deterministically; "keep" means it requires judgment.

| Rule in prompt today | Verdict | Reason |
|---|---|---|
| Response contract + `"action"` is literally `"tool"` (×3 roles, with WRONG/RIGHT examples) | **Delete** | Belongs to the channel (L1). Under native/constrained decoding it is unrepresentable. This is the single largest deletable block. |
| "Do NOT repeat a tool call with the same arguments" | **Keep, one line** | `DuplicateActionGuardrail` enforces it, but enforcement costs a wasted 1–2 min iteration. The cheapest form of the prompt rule prevents the waste; the guardrail message does the teaching. |
| "ask_human is a LAST RESORT" | **Move** | `HumanPolicy` can refuse early asks (topic 7). |
| WEB RESEARCH 4-step procedure (search → read → follow link → repeat) | **Move — into a tool** | A multi-step *procedure* is the weakest thing to ask a weak model to follow. Collapse it into one call: `web.investigate(question) -> Evidence[]` that internally does search→read→follow. One decision instead of eight. |
| BUILDING SOFTWARE debug loop (read error → inspect → hypothesize → isolate → fix → verify) | **Keep the principle, move the mechanics** | The 4-step diagnosis is judgment. But "if a command timed out, kill the pid before retrying" is a rule the runtime can apply. |
| "Use `start_server`, NEVER `run_command`, for servers" (~10 lines) | **Move** | `RunCommandTool` can detect server-shaped commands (`node server.js`, `npm start`, `http.server`, `flask run`, trailing `&`) and return a `PreconditionFailed` redirect. Deterministic; deletes ten lines. |
| "Bind to a published port 8090–8099" (~5 lines) | **Move to the type** | `enum` on the port parameter (idea D). Already enforced at runtime — make it unrepresentable instead. |
| "Playwright is preinstalled — do NOT `npx playwright install`" | **Move** | Environment facts belong in `sandbox_info`'s *output*, not in every system prompt. Optionally a `run_command` precondition that rejects the install command with the explanation. |
| "NEVER invent tool results / statistics / quotes" | **Keep** | Unenforceable in code. |
| "A placeholder stub is NOT a deliverable" | **Both** | Keep in prompt; `ArtifactChecks` already checks exists+non-empty — add a placeholder-marker heuristic to the pre-gate. |
| "Corroborate surprising claims with a second source" | **Keep** | Judgment. |
| TODAY'S DATE IS … | **Keep** | Context, not a rule. Correctly placed. |
| Supervisor "COUNT WHAT THE USER COUNTED" (~12 lines about agent-count vs content-count) | **Move — into `GoalComprehension`'s typed output** | This block re-teaches, every turn, a distinction that the comprehension step should decide *once* and store as structured fields (`agent_floor: int?`, `content_targets: [...]`). The supervisor then reads a fact, not an essay. |
| Supervisor "► REVIEW NOW: task #N — do NOT review any task not in this list" | **Move — bind the parameter** | Idea C. The runtime knows the target. Removing the argument removes the failure and lets you delete the anti-livelock branch `5a-bis`. |
| Supervisor "you have only three jobs / delegation is automatic / there is no delegate tool" | **Mostly delete** | Explaining the *absence* of a capability is a smell. Under affordance narrowing (idea B) the legal calls are the explanation. |
| "KNOW YOUR LIMITS — size tasks to one worker run" | **Keep** | Genuine judgment, and the most valuable block in the supervisor prompt. |
| "Only finish when every task is done" | **Delete** | Finishing is already deterministic (`supervisorAllSettled`). The model cannot finish; telling it when to is dead text. |

**Where the boundary belongs.** A useful test, in priority order:

1. **Can the runtime make it unrepresentable?** (types, enums, bound parameters, hidden tools) → do that. Best case: the rule vanishes.
2. **Can the runtime detect and correct it deterministically?** → guardrail/precondition, with the message as the teaching surface.
3. **Is it a fixed multi-step procedure?** → make it *one tool*, not prompt steps.
4. **Does it require judgment about content quality, scale, or truth?** → prompt.

Rough estimate applying this: the worker system prompt loses ~45% of its lines, the supervisor ~35% —
and most of what goes is text that a weak model was being asked to hold in working memory *every
single turn* alongside the actual task.

---

## 10. Future-proof architecture

**Motivation.** `LlmClient::complete(string $system, array $messages, ?callable $onProgress): string`
is the ceiling on everything else. String in, string out cannot express: tool calls, tool results as
first-class messages, token usage/cost, finish reason, images, cached prefixes, or "does this model
support tools". Every future provider feature has to be smuggled through prompt text.

**Advantages of a typed model interface.** Native tool calling becomes possible (which is L1).
Capability negotiation becomes possible (topic 10's stated goal). Cost/token accounting becomes
possible at all. `FakeLlmClient` stops scripting raw JSON strings and starts scripting typed
decisions — which makes every orchestrator test clearer.

**Disadvantages.** It touches `LlmPlanner`, `OpenAiCompatibleClient`, `FailureDiagnosis`,
`GoalComprehension`, `FakeLlmClient` and the streaming preview at once. The `partialThought`
character-scanner in `LlmPlanner` is coupled to "the answer is JSON with `thought` first" and will
need rework (under native tool calling, reasoning arrives on `content`/`reasoning` while the call
arrives on `tool_calls` — *simpler*, but different code).

**Concrete gateway warning.** `services/litellm/config.yaml` sets `drop_params: true` gateway-wide.
That silently discards parameters a backend doesn't support. If `tools` is sent to a model whose
LiteLLM path doesn't support function calling, the likely outcome is not an error — it is a model
that **sees no tools at all** and free-associates. So: **probe capabilities explicitly per gateway
model and store the result; never send `tools` on faith.** Verify against your installed LiteLLM and
Ollama versions before committing to this path — treat it as a spike, not an assumption.

**Alternatives for the decision channel** (pick per model via capability, not globally):

| Channel | Envelope errors | Works on qwen3:8b via Ollama? | Notes |
|---|---|---|---|
| **Native tool calls** (`tools` + `tool_calls`) | Impossible | Needs verification — depends on the model's Ollama template and LiteLLM's `ollama_chat` path | Strongest. Also the MCP-compatible shape. |
| **Constrained JSON** (`response_format: json_schema` → Ollama `format: <schema>`) | Impossible (structurally) | Yes, if your Ollama supports schema-format | Encode the whole decision as a discriminated union of the currently-legal calls. Pairs beautifully with affordance narrowing. |
| **JSON mode** (`json_object`) — today | Possible | Yes | Only guarantees "an object", not *which* object. |
| **Text envelope** — the fallback | Possible | Yes | Keep for models with nothing else. |

Note the second row: **grammar-constrained decoding + affordance narrowing means the model
physically cannot emit an illegal call for the current state.** That is what "the LLM invokes methods
on a runtime" actually looks like in practice — and it is more achievable than the pseudo-language
version of the idea.

**Future limitations.** Capability negotiation needs a cache with an invalidation story (a gateway
model can be re-pointed at a different backend from the LiteLLM UI without the app knowing). Tie the
capability cache to the model's gateway config hash, or expire it aggressively and re-probe. Also,
none of this constrains *semantics*: a perfectly typed call to the wrong tool with the wrong path is
still wrong. Types eliminate a category of failure; they do not eliminate weak reasoning.

**Recommendation.** This is the foundation — do it first, in this shape:

```php
interface ModelClient {
    public function capabilities(ModelRef $m): ModelCapabilities;  // tools? json_schema? streaming? vision?
    public function send(ModelRequest $r): ModelResponse;          // typed in, typed out
}
```

`ModelResponse` carries: `content`, `reasoning`, `toolCalls: ToolCall[]`, `finishReason`, `usage`.
`ModelRequest` carries: messages (with a real tool-result message type), `tools: ToolDescriptor[]`,
`responseFormat`, `stream`. Then `DecisionChannel` implementations (native / constrained / text) sit
between the planner and the client, selected by capability with a config override. `DecisionParser`
survives as the text channel's implementation and nothing else.

**Brainstorm.** MCP fits here almost for free: an MCP client adapter registers *remote* tools into the
same `ToolRegistry` as local ones, because both produce the same descriptor IR — remote workers and
third-party tool servers with no changes to the orchestrator. The reverse is also cheap and possibly
more valuable: **expose Fariborz's sandbox tools as an MCP server**, so Claude Code / any MCP client
can drive this sandbox directly. Plugin architecture: prefer *profiles + tags* (what Laravel's
container already gives you) over a bespoke plugin system.

---

## Recommended sequence

Ordered by (value ÷ risk), not by the brief's numbering.

| Phase | Work | Why here |
|---|---|---|
| **0** | Classify failures: tag every `InvalidLlmResponse` event with a reason (envelope / unknown tool / bad args / empty completion). Build `agent:replay`. Fix `tier` + `port` to be enums. Add the tool contract test. | A few days. Turns the rest of this document from architecture opinion into measured decisions. **Do not skip.** |
| **1** | `ModelClient` typed request/response + capabilities. `DecisionChannel` strategy with native / constrained / text. Measure with phase 0's replay harness. | Fixes L1 — the largest single source of failure — and unblocks 3, 5, 10. |
| **2** | Declarative permissions on tools (kill the arrays in `ToolRegistry`). Affordance narrowing. Injected parameters. | Fixes L3, deletes the `review_task` failure class and the `5a-bis` branch. Small and safe. |
| **3** | `CallDispatcher` + 5-stage pipeline + typed error hierarchy. Typed argument binding into tools. | Fixes L2. Do it after 1, so lenient paths can be deleted with evidence. |
| **4** | `PromptComposer` fragments; apply the topic-9 table; `ToolDocRenderer` **only if** a text channel survives phase 1. | Value depends on phase 1's outcome. |
| **5** | `SupervisorPolicy` decision table. `HumanPolicy` extraction. `web.investigate` compound tool. | Cleanups, valuable, not urgent. |
| **Never (for now)** | Multiple/composable runtimes; workflow engine; event sourcing; multi-human expert routing. | Speculative generality against known-single-user constraints. |

## What I would push back on hardest

1. **Don't build "Runtime" as specified.** It is the orchestrator with a new name and all the current risk concentrated in one class. Build the narrow call boundary; keep the kernel.
2. **Don't let the model emit call *syntax*.** "Prompt looks like API docs" is right; "model replies in a pseudo-language" replaces a JSON parser with a worse parser. Docs in, structured calls out.
3. **The missing topic is the channel.** Native tool calling / constrained decoding does more for determinism than topics 1, 2, 3 and 5 combined, and it deletes code instead of adding it.
4. **Topics 7 and parts of 4/6/8 are already built.** Effort there is mostly re-arranging working code — the least valuable thing to do in a system whose remaining failures are concentrated somewhere else entirely.
5. **Measure before rewriting prompts.** The prompt text encodes hard-won fixes. `agent:replay` costs days and protects months.
