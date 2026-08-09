@extends('dashboard.layout')
@section('title', 'Sandbox')

@php
    $reachable = (bool) ($status['reachable'] ?? false);
    $isRoot = (bool) ($info['is_root'] ?? false);
    $freePorts = $info['free_app_ports'] ?? [];
    $inUsePorts = $info['in_use_app_ports'] ?? [];
    $publishedPorts = $info['published_app_ports'] ?? '—';
@endphp

@section('header')
<div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;width:100%">
    <div>
        <h1 style="margin:0;font-size:19px;font-weight:600;letter-spacing:-.012em;color:#f2f5f6">Sandbox</h1>
        <div class="fz-mono" style="font-size:10.5px;color:#8a9499;margin-top:4px">{{ $status['base_url'] ?? 'sandbox' }} · {{ $isRoot ? 'root' : 'unprivileged' }} · app ports {{ $publishedPorts }}</div>
    </div>
</div>
@endsection

@section('content')
<style>
    @keyframes breathe{0%,100%{opacity:.55}50%{opacity:1}}
    @keyframes caret{0%,49%{opacity:1}50%,100%{opacity:0}}
    .sb-cols{display:flex;gap:22px;align-items:flex-start;flex-wrap:wrap}
    .sb-main{flex:1 1 620px;min-width:0}
    .sb-side{flex:0 0 300px;display:flex;flex-direction:column;gap:14px}
    @media (max-width:1080px){ .sb-side{flex-basis:100%} }

    /* Offline: the whole page body is frozen behind a blurred scrim. It lives
       inside the content column, so the sidebar stays sharp and navigable. */
    .sb-dim{max-height:calc(100vh - 190px);overflow:hidden}
    .sb-veil{position:absolute;inset:-10px;z-index:5;display:flex;align-items:center;justify-content:center;
             border-radius:16px;background:rgba(20,25,27,.55);
             backdrop-filter:blur(7px) saturate(.55);-webkit-backdrop-filter:blur(7px) saturate(.55)}
    .sb-veil-card{max-width:430px;margin:0 22px;padding:26px 28px;text-align:center;border-radius:15px;
                  border:1px solid rgba(242,182,97,.3);background:linear-gradient(180deg,#23282c,#1b2021);
                  box-shadow:0 24px 60px rgba(0,0,0,.5)}
</style>

<div x-data="sandboxPage()" x-init="start()" style="position:relative">

{{-- Driven by `offline`, not just the server-rendered state, so the veil also
     appears if the container dies while the page is sitting open.
     `inert` keeps the blurred console/inputs from being typed into or tabbed to. --}}
<div :class="{ 'sb-dim': offline }" :aria-hidden="offline" x-effect="$el.inert = offline"
     @class(['sb-dim' => ! $reachable])>

    {{-- Environment hero --}}
    @php
        $heroBd = $reachable ? 'rgba(62,207,142,.22)' : 'rgba(242,182,97,.3)';
        $heroGlow = $reachable ? 'rgba(62,207,142,.13)' : 'rgba(242,182,97,.13)';
        $heroDot = $reachable ? '#3ecf8e' : '#f2b661';
        $heroFg = $reachable ? '#5fdda5' : '#f5c987';
        $heroLbl = $reachable ? 'ENVIRONMENT REACHABLE' : 'ENVIRONMENT UNREACHABLE';
    @endphp
    <section style="position:relative;overflow:hidden;border-radius:15px;border:1px solid {{ $heroBd }};background:radial-gradient(900px 260px at 8% -55%,{{ $heroGlow }},transparent 60%),linear-gradient(180deg,#23282c,#1b2021);padding:18px 20px">
        <div class="flex items-center" style="gap:9px;margin-bottom:15px">
            <div style="width:8px;height:8px;border-radius:50%;background:{{ $heroDot }};animation:breathe 2.2s ease-in-out infinite;box-shadow:0 0 14px {{ $heroGlow }}"></div>
            <span class="fz-mono" style="font-size:10.5px;letter-spacing:.14em;color:{{ $heroFg }}">{{ $heroLbl }}</span>
            <span class="fz-mono" style="font-size:10.5px;color:#8a9499" x-text="'last probe ' + probe"></span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(158px,1fr));gap:1px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.07);border-radius:11px;overflow:hidden">
            <template x-for="s in stats" :key="s.k">
                <div style="background:#1c2124;padding:11px 13px">
                    <div class="fz-mono" style="font-size:9px;letter-spacing:.11em;color:#8a9499" x-text="s.k"></div>
                    <div class="fz-mono" style="font-size:15.5px;margin-top:3px" :style="{ color: s.fg }" x-text="s.v"></div>
                </div>
            </template>
        </div>
    </section>

    <div class="sb-cols" style="margin-top:22px">

        <section class="sb-main">
            {{-- Running processes --}}
            <div class="flex items-center" style="gap:12px;margin-bottom:13px">
                <h2 style="margin:0;font-size:13px;font-weight:600;color:#e4e9ea">Running processes</h2>
                <span class="fz-mono" style="font-size:10.5px;color:#8a9499" x-text="processes.length + ' live'"></span>
                {{-- Fixed width + a static label: a text swap on every 5s poll would shift the header. --}}
                <button @click="refresh(true)" :disabled="loading" class="fz-mono" style="margin-left:auto;font-size:10px;width:78px;padding:4px 0;border-radius:7px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#a3adb1;cursor:pointer" :style="{ opacity: loading ? .5 : 1 }">↻ refresh</button>
            </div>

            <div x-show="error" x-cloak class="fz-mono" style="margin-bottom:12px;border-radius:9px;border:1px solid rgba(242,182,97,.35);background:rgba(242,182,97,.1);padding:9px 12px;font-size:11px;color:#f5c987" x-text="error"></div>

            <div style="border:1px solid rgba(255,255,255,.07);border-radius:13px;overflow:hidden;background:#1a1f21">
                <div style="overflow-x:auto">
                    <div style="min-width:640px">
                        <div class="fz-mono" style="display:grid;grid-template-columns:70px minmax(0,1fr) 90px 110px 66px;gap:12px;padding:10px 15px;background:rgba(255,255,255,.025);border-bottom:1px solid rgba(255,255,255,.07);font-size:9.5px;letter-spacing:.12em;color:#8a9499">
                            <div>PID</div><div>COMMAND</div><div>PORT</div><div>JOB</div><div style="text-align:right">KILL</div>
                        </div>
                        <template x-for="p in processes" :key="p.pid">
                            <div style="display:grid;grid-template-columns:70px minmax(0,1fr) 90px 110px 66px;gap:12px;padding:11px 15px;border-bottom:1px solid rgba(255,255,255,.05);align-items:center">
                                <div class="fz-mono" style="font-size:11.5px;color:#c8d0d3" x-text="p.pid"></div>
                                <div class="fz-mono" style="font-size:11.5px;color:#c8d0d3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="p.command || '—'"></div>
                                <div>
                                    <a :href="'http://localhost:' + p.port" target="_blank" class="fz-mono" style="font-size:10px;padding:2px 7px;border-radius:5px;background:rgba(234,99,140,.12);border:1px solid rgba(234,99,140,.3);color:#ffb3c4;text-decoration:none" x-text="':' + p.port"></a>
                                </div>
                                <div>
                                    <template x-if="p.job"><a :href="'/jobs/' + p.job" class="fz-mono" style="font-size:10.5px;color:#96a0a5" x-text="p.job"></a></template>
                                    <template x-if="!p.job"><span class="fz-mono" style="font-size:10.5px;color:#8a9499">—</span></template>
                                </div>
                                <div style="text-align:right">
                                    <button @click="kill(p)" :disabled="p.killing" class="fz-mono" style="font-size:9.5px;padding:4px 9px;border-radius:6px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#a3adb1;cursor:pointer"
                                            onmouseover="this.style.color='#ff9b9b';this.style.borderColor='rgba(255,107,107,.4)'" onmouseout="this.style.color='#a3adb1';this.style.borderColor='rgba(255,255,255,.1)'" x-text="p.killing ? '…' : '✕'"></button>
                                </div>
                            </div>
                        </template>
                        {{-- Gated on `loaded`, never on `loading`: keying it to the in-flight
                             poll made this row (and the panel) collapse every 5 seconds. --}}
                        <div x-show="loaded && !processes.length" x-cloak style="padding:28px 15px;text-align:center;color:#8a9499;font-size:13px">No background servers running.</div>
                        <div x-show="!loaded" class="fz-mono" style="padding:28px 15px;text-align:center;color:#8a9499;font-size:11px">loading…</div>
                    </div>
                </div>
            </div>

            {{-- Console --}}
            <div class="flex flex-wrap items-center" style="gap:12px;margin:22px 0 13px">
                <h2 style="margin:0;font-size:13px;font-weight:600;color:#e4e9ea">Console</h2>
                <span class="fz-mono" style="font-size:10.5px;color:#8a9499">30s timeout · cd persists · ↑/↓ history</span>
                <div class="flex items-center" style="margin-left:auto;gap:6px">
                    <span class="fz-mono" style="font-size:10px;color:#8a9499">workspace</span>
                    <input x-model="job" @change="boot()" class="fz-mono" style="width:130px;padding:5px 9px;border-radius:7px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);color:#e4e9ea;font-size:11px" placeholder="workspace slug or 'console'">
                    <a :href="'{{ url('/sandbox/preview') }}/' + encodeURIComponent(safeJob()) + '/'" target="_blank" rel="noopener"
                       title="Serve this workspace's index.html in the browser — no server needed"
                       class="fz-mono" style="font-size:9.5px;letter-spacing:.05em;padding:5px 10px;border-radius:7px;background:rgba(62,207,142,.1);border:1px solid rgba(62,207,142,.3);color:#5fdda5;text-decoration:none">▶ PREVIEW</a>
                </div>
            </div>

            <div style="border-radius:13px;border:1px solid rgba(255,255,255,.09);background:#0e1214;overflow:hidden">
                <div class="flex items-center" style="gap:8px;padding:9px 13px;border-bottom:1px solid rgba(255,255,255,.07);background:rgba(255,255,255,.02)">
                    <span style="width:7px;height:7px;border-radius:50%;background:#ea638c"></span>
                    <span class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499" x-text="safeJob() + ' — bash'"></span>
                    <button @click="clear()" class="fz-mono" style="margin-left:auto;font-size:9.5px;color:#8a9499;background:none;border:none;cursor:pointer">clear</button>
                </div>
                <div x-ref="out" class="fz-scroll" style="height:280px;overflow:auto;padding:13px 15px">
                    <template x-for="(l, i) in lines" :key="i">
                        <div class="fz-mono" style="white-space:pre-wrap;font-size:11.5px;line-height:1.75" :style="{ color: lineColor(l.type) }" x-text="l.text"></div>
                    </template>
                    <div x-show="running" class="fz-mono" style="font-size:11.5px;color:#8a9499">…running</div>
                </div>
                <div class="flex items-center" style="gap:9px;padding:11px 15px;border-top:1px solid rgba(255,255,255,.07);background:rgba(255,255,255,.02)">
                    <span class="fz-mono" style="font-size:12px;color:#ea638c" x-text="prompt()"></span>
                    <input x-ref="cmd" x-model="input" :disabled="running" @keydown.enter="run()" @keydown.arrow-up.prevent="histUp()" @keydown.arrow-down.prevent="histDown()"
                           class="fz-mono" style="flex:1;min-width:0;background:transparent;border:none;color:#eef1f2;font-size:12px;outline:none" placeholder="type a command and press ⏎" autocomplete="off" spellcheck="false">
                    <button @click="run()" class="fz-mono" style="font-size:11px;padding:7px 14px;border-radius:8px;border:1px solid rgba(255,217,218,.3);background:linear-gradient(145deg,#ea638c,#89023e);color:#fff;cursor:pointer">RUN</button>
                </div>
            </div>
        </section>

        <aside class="sb-side">
            {{-- Exposed services --}}
            <div style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#1a1f21;padding:15px">
                <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499;margin-bottom:12px">EXPOSED SERVICES</div>
                <div class="flex flex-col" style="gap:8px">
                    <template x-for="p in processes.filter(x => x.port)" :key="p.pid">
                        <a :href="'http://localhost:' + p.port" target="_blank" class="flex items-center" style="gap:9px;padding:9px 11px;border-radius:9px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);text-decoration:none"
                           onmouseover="this.style.borderColor='rgba(234,99,140,.35)'" onmouseout="this.style.borderColor='rgba(255,255,255,.06)'">
                            <span style="width:6px;height:6px;flex:0 0 6px;border-radius:50%;background:#3ecf8e"></span>
                            <span class="fz-mono" style="font-size:11.5px;color:#c8d0d3" x-text="'localhost:' + p.port"></span>
                            <span class="fz-mono" style="margin-left:auto;font-size:9.5px;color:#5fdda5">live</span>
                        </a>
                    </template>
                    <div x-show="!processes.filter(x => x.port).length" class="fz-mono" style="font-size:11px;color:#8a9499">No exposed ports.</div>
                </div>
            </div>

            {{-- Environment --}}
            <div class="fz-mono flex flex-col" style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#1a1f21;padding:15px;gap:10px;font-size:10.5px">
                <div style="letter-spacing:.13em;color:#8a9499;font-size:9.5px">ENVIRONMENT</div>
                <div class="flex justify-between" style="gap:10px"><span style="color:#8a9499">endpoint</span><span style="color:#a3adb1;text-align:right;min-width:0;overflow:hidden;text-overflow:ellipsis">{{ $status['base_url'] ?? '—' }}</span></div>
                <div class="flex justify-between"><span style="color:#8a9499">user</span><span style="color:#a3adb1">{{ $isRoot ? 'root' : 'unprivileged' }}</span></div>
                <div class="flex justify-between"><span style="color:#8a9499">app ports</span><span style="color:#a3adb1">{{ $publishedPorts }}</span></div>
                <div class="flex justify-between"><span style="color:#8a9499">free</span><span style="color:#5fdda5">{{ count($freePorts) }}</span></div>
                <div class="flex justify-between"><span style="color:#8a9499">in use</span><span style="color:{{ count($inUsePorts) ? '#f5c987' : '#a3adb1' }}">{{ count($inUsePorts) }}</span></div>
            </div>

            {{-- Quick commands --}}
            <div style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#1a1f21;padding:15px">
                <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499;margin-bottom:11px">QUICK COMMANDS</div>
                <div class="flex flex-wrap" style="gap:6px">
                    <template x-for="c in quickCmds" :key="c">
                        <button @click="quick(c)" class="fz-mono" style="font-size:10.5px;padding:5px 10px;border-radius:7px;cursor:pointer;border:1px solid rgba(234,99,140,.26);background:rgba(234,99,140,.08);color:#ffb3c4"
                                onmouseover="this.style.background='rgba(234,99,140,.22)';this.style.color='#ffd9da'" onmouseout="this.style.background='rgba(234,99,140,.08)';this.style.color='#ffb3c4'" x-text="c"></button>
                    </template>
                </div>
            </div>

            @if (trim($info['diagnostics'] ?? ''))
                <div style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#1a1f21;padding:15px">
                    <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499;margin-bottom:10px">TOOLCHAINS</div>
                    <pre class="fz-mono fz-scroll" style="margin:0;font-size:10.5px;color:#a3adb1;white-space:pre-wrap;max-height:150px;overflow:auto">{{ trim($info['diagnostics']) }}</pre>
                </div>
            @endif
        </aside>
    </div>
</div>{{-- /sb-dim --}}

    {{-- Cloaked only when the page rendered online, so an offline page paints the
         veil immediately instead of flashing the dead UI until Alpine boots. --}}
    <div class="sb-veil" x-show="offline" @if ($reachable) x-cloak @endif>
        <div class="sb-veil-card">
            <div style="width:11px;height:11px;margin:0 auto 14px;border-radius:50%;background:#f2b661;box-shadow:0 0 20px rgba(242,182,97,.55);animation:breathe 2.2s ease-in-out infinite"></div>
            <h2 style="margin:0;font-size:16px;font-weight:600;letter-spacing:-.01em;color:#f5c987">Sandbox is offline</h2>
            <p style="margin:9px 0 0;font-size:12.5px;line-height:1.6;color:#a3adb1">
                Nothing is answering at
                <span class="fz-mono" style="color:#c8d0d3">{{ $status['base_url'] ?? 'the sandbox' }}</span>,
                so the console and process list are unavailable.
            </p>
            <div class="fz-mono" style="margin-top:15px;padding:10px 13px;border-radius:9px;text-align:left;background:#0e1214;border:1px solid rgba(255,255,255,.08);font-size:11.5px;color:#ffd9da;overflow-x:auto;white-space:nowrap">docker compose up -d sandbox</div>
            <div class="flex items-center justify-center" style="gap:9px;margin-top:16px">
                <button @click="recheck()" :disabled="checking" class="fz-mono" style="font-size:11px;padding:7px 16px;border-radius:8px;border:1px solid rgba(255,217,218,.3);background:linear-gradient(145deg,#ea638c,#89023e);color:#fff;cursor:pointer"
                        :style="{ opacity: checking ? .6 : 1 }" x-text="checking ? 'checking…' : 'Retry'"></button>
                <span class="fz-mono" style="font-size:10.5px;color:#8a9499">auto-retrying every 5s</span>
            </div>
        </div>
    </div>
</div>{{-- /x-data --}}

@push('scripts')
<script>
function sandboxPage() {
    const REACHABLE = @json($reachable);
    const BASE_URL = @json($status['base_url'] ?? '—');
    const IS_ROOT = @json($isRoot);
    const FREE = @json(count($freePorts));
    const PUBLISHED = @json((string) $publishedPorts);

    return {
        // ── process list ────────────────────────────────────────────────────
        processes: [],
        loading: false,   // a manual refresh only — background polls stay invisible
        loaded: false,    // first poll has landed; gates the empty state so it can't flicker
        checking: false,  // an explicit "Retry" from the offline veil
        offline: !REACHABLE,
        error: '',
        secs: 0,
        quickCmds: ['ps aux', 'df -h', 'ls -la', 'pip list', 'python --version', 'netstat -tlnp'],

        // ── console state ───────────────────────────────────────────────────
        // Deep-linkable: /sandbox?job=<workspace-slug> opens straight into a job's workspace.
        job: new URLSearchParams(location.search).get('job') || 'console',
        cwd: '',
        input: '',
        lines: [],
        running: false,
        history: [],
        hi: -1,

        start() {
            this.refresh();
            this.procTimer = setInterval(() => this.refresh(), 5000);
            this.clock = setInterval(() => { this.secs++; }, 1000);
            this.boot();
        },

        get probe() { return (this.secs % 5) + 's ago'; },
        get stats() {
            return [
                { k: 'STATUS',    v: REACHABLE ? 'reachable' : 'unreachable', fg: REACHABLE ? '#5fdda5' : '#f5c987' },
                { k: 'ENDPOINT',  v: BASE_URL, fg: '#e4e9ea' },
                { k: 'USER',      v: IS_ROOT ? 'root' : 'unpriv', fg: '#e4e9ea' },
                { k: 'APP PORTS', v: PUBLISHED, fg: '#ffd9da' },
                { k: 'FREE',      v: String(FREE), fg: '#5fdda5' },
                { k: 'PROCESSES', v: String(this.processes.length), fg: '#e4e9ea' },
            ];
        },

        async refresh(manual = false) {
            if (manual) this.loading = true;
            try {
                const r = await fetch('{{ route('ui.sandbox.processes') }}', { headers: { Accept: 'application/json' } });
                const data = await r.json();
                this.error = data.error || '';
                this.offline = data.reachable === false;
                // Patch rows in place and keep the array identity stable — replacing
                // the whole list every 5s made unchanged rows re-render and twitch.
                this.merge(data.processes || []);
            } catch (e) { this.error = 'Could not reach the sandbox service.'; this.offline = true; }
            finally {
                this.loading = false;
                this.loaded = true;
                // The page rendered offline and the sandbox just came back — the
                // server-rendered half (env, ports, toolchains) is stale, so reload.
                if (!REACHABLE && !this.offline) location.reload();
            }
        },

        /** "Retry" on the offline veil — same probe, with button feedback. */
        async recheck() {
            this.checking = true;
            try { await this.refresh(); } finally { this.checking = false; }
        },

        /** Reconcile the polled list into the existing one, by pid. */
        merge(incoming) {
            const byPid = new Map(this.processes.map(p => [p.pid, p]));
            const next = incoming.map(p => {
                const existing = byPid.get(p.pid);
                if (!existing) return { ...p, killing: false };
                Object.assign(existing, p);   // keeps `killing` and the DOM node
                return existing;
            });
            // Only touch the bound array when the set of rows actually changed.
            const same = next.length === this.processes.length
                && next.every((p, i) => p === this.processes[i]);
            if (!same) this.processes = next;
        },
        async kill(p) {
            if (!confirm(`Kill PID ${p.pid} (port ${p.port})?`)) return;
            p.killing = true;
            const res = await window.postJson('{{ route('sandbox.kill') }}', { pid: p.pid });
            if (res && res.error) { this.error = res.error; p.killing = false; return; }
            setTimeout(() => this.refresh(), 400);
        },

        // ── console ─────────────────────────────────────────────────────────
        lineColor(t) { return { prompt:'#ffd9da', out:'#a3adb1', err:'#ff9b9b', meta:'#f5c987' }[t] || '#a3adb1'; },
        boot() { this.lines = []; this.cwd = this.root(); this.push('meta', `workspace: ${this.root()}`); },
        safeJob() { return (this.job || 'console').replace(/[^a-zA-Z0-9._-]/g, '_'); },
        root() { return `/workspace/${this.safeJob()}`; },
        display() {
            const r = this.root();
            if (this.cwd === r) return '~';
            if (this.cwd.startsWith(r + '/')) return '~' + this.cwd.slice(r.length);
            return this.cwd || '~';
        },
        prompt() { return `${this.safeJob()}:${this.display()}$`; },
        push(type, text) {
            (text || '').split('\n').forEach(t => this.lines.push({ type, text: t }));
            this.$nextTick(() => { const o = this.$refs.out; if (o) o.scrollTop = o.scrollHeight; });
        },
        clear() { this.lines = []; },
        quick(c) { this.input = c; this.$nextTick(() => this.$refs.cmd?.focus()); },
        histUp() {
            if (!this.history.length) return;
            this.hi = this.hi < 0 ? this.history.length - 1 : Math.max(0, this.hi - 1);
            this.input = this.history[this.hi];
        },
        histDown() {
            if (this.hi < 0) return;
            this.hi = this.hi + 1;
            if (this.hi >= this.history.length) { this.hi = -1; this.input = ''; }
            else this.input = this.history[this.hi];
        },
        async run() {
            const raw = this.input.trim();
            if (!raw || this.running) return;
            this.push('prompt', `${this.prompt()} ${raw}`);
            this.history.push(raw); this.hi = -1; this.input = '';
            if (raw === 'clear') { this.clear(); return; }
            const q = (s) => `'${String(s).replace(/'/g, `'\\''`)}'`;
            const wrapped =
                `cd ${q(this.cwd)} 2>/dev/null || cd ${q(this.root())}\n` +
                `${raw}\n__rc=$?\nprintf '\\n__CWD__%s__END__' "$(pwd -P 2>/dev/null)"\n( exit $__rc )`;
            this.running = true;
            try {
                const res = await this.send(wrapped);
                if (!res || typeof res !== 'object') { this.push('err', 'No response from the server (is it running?).'); return; }
                if (res.error) { this.push('err', res.error); return; }
                let out = res.stdout || '';
                const m = out.match(/\n?__CWD__(.*?)__END__/s);
                if (m) { out = out.replace(m[0], ''); const abs = m[1].trim(); if (abs) this.cwd = abs; }
                out = out.replace(/\n+$/, '');
                const err = (res.stderr || '').replace(/\n+$/, '');
                let printed = false;
                if (out) { this.push('out', out); printed = true; }
                if (err) { this.push('err', err); printed = true; }
                if (res.timed_out) { this.push('meta', '⏱ timed out (120s) — long-lived? start it with nohup … &'); printed = true; }
                if (res.exit_code) { this.push('meta', `exit ${res.exit_code}`); printed = true; }
                if (!printed) this.push('meta', '(no output · exit 0)');
            } catch (e) { this.push('err', 'Could not reach the server — reload the page or restart it.'); }
            finally { this.running = false; this.$nextTick(() => this.$refs.cmd?.focus()); }
        },
        async send(cmd) {
            const r = await fetch('{{ route('sandbox.exec') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ job: this.job, cmd, cwd: '.' }),
            });
            const text = await r.text();
            try { return JSON.parse(text); }
            catch { return { error: `Server returned HTTP ${r.status} (not JSON) — the /sandbox/exec route may be missing. Restart the app.` }; }
        },
    };
}
</script>
@endpush
@endsection
