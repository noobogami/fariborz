@extends('dashboard.layout')
@section('title', 'Tools & Ollama')

@section('header')
<div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;width:100%">
    <div>
        <h1 style="margin:0;font-size:19px;font-weight:600;letter-spacing:-.012em;color:#f2f5f6">Tools &amp; Ollama</h1>
        <div class="fz-mono" style="font-size:10.5px;color:#8a9499;margin-top:4px">{{ count($tools) }} registered tools · {{ count($skills) }} agent-built · driver {{ $llmDriver }}</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
        <button onclick="location.reload()" class="fz-mono" style="font-size:11.5px;padding:8px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#c8d0d3;cursor:pointer"
                onmouseover="this.style.background='rgba(255,255,255,.07)';this.style.color='#fff'" onmouseout="this.style.background='rgba(255,255,255,.03)';this.style.color='#c8d0d3'">RE-SCAN</button>
    </div>
</div>
@endsection

@section('content')
<style>
    @keyframes breathe{0%,100%{opacity:.55}50%{opacity:1}}
    .to-cols{display:flex;gap:22px;align-items:flex-start;flex-wrap:wrap}
    .to-main{flex:1 1 560px;min-width:0}
    .to-side{flex:0 0 340px;display:flex;flex-direction:column;gap:14px}
    @media (max-width:1080px){ .to-side{flex-basis:100%} }
    .to-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(258px,1fr));gap:11px}
</style>

<div x-data="ollama()" x-init="start()">

    {{-- Web search key warning --}}
    @unless ($searchKeySet)
        <section class="flex flex-wrap" style="align-items:flex-start;gap:14px;border-radius:13px;border:1px solid rgba(242,182,97,.3);background:linear-gradient(90deg,rgba(242,182,97,.1),rgba(27,32,33,.7));padding:15px 17px;margin-bottom:22px">
            <div class="fz-mono" style="width:26px;height:26px;flex:0 0 26px;border-radius:8px;background:rgba(242,182,97,.16);border:1px solid rgba(242,182,97,.4);display:flex;align-items:center;justify-content:center;font-size:13px;color:#f5c987">!</div>
            <div style="flex:1 1 340px;min-width:0">
                <div style="font-size:13.5px;font-weight:600;color:#f5c987">Web search key missing — <span class="fz-mono" style="font-size:12.5px;font-weight:400">SEARCH_API_KEY</span> is not set</div>
                <div style="font-size:12.5px;line-height:1.6;color:#c4ccce;margin-top:5px;max-width:88ch">The agent falls back to the headless-browser scraper — ~7× slower and rate-limited. Add a free-tier <b>Tavily</b>, <b>Brave</b> or <b>SerpAPI</b> key in Settings and the matching search tool turns on automatically.</div>
            </div>
            <a href="{{ route('settings') }}" class="fz-mono" style="font-size:11.5px;padding:8px 14px;border-radius:8px;border:1px solid rgba(242,182,97,.45);background:rgba(242,182,97,.18);color:#f5c987;text-decoration:none;align-self:center"
               onmouseover="this.style.background='rgba(242,182,97,.3)'" onmouseout="this.style.background='rgba(242,182,97,.18)'">ADD KEY IN SETTINGS →</a>
        </section>
    @endunless

    <div class="to-cols">

        {{-- Tool inventory --}}
        <section class="to-main">
            <div class="flex flex-wrap items-center" style="gap:12px;margin-bottom:13px">
                <h2 style="margin:0;font-size:13px;font-weight:600;color:#e4e9ea">Tool inventory</h2>
                <span class="fz-mono" style="font-size:10.5px;color:#8a9499" x-text="tools.length + ' registered'"></span>
                <div class="flex" style="margin-left:auto;gap:6px">
                    <template x-for="f in ['ALL','ACTIVE','ATTENTION']" :key="f">
                        <button @click="filter = f" class="fz-mono"
                                :style="{ fontSize:'10px', letterSpacing:'.08em', padding:'5px 11px', borderRadius:'20px', cursor:'pointer', border:'1px solid '+(filter===f?'rgba(234,99,140,.4)':'rgba(255,255,255,.08)'), background:(filter===f?'rgba(234,99,140,.16)':'rgba(255,255,255,.03)'), color:(filter===f?'#ffd9da':'#96a0a5') }"
                                x-text="f"></button>
                    </template>
                </div>
            </div>

            <div class="to-grid">
                <template x-for="t in filteredTools" :key="t.name">
                    <div @click="t.schema && (openSchema[t.name] = !openSchema[t.name])"
                         :style="{ borderRadius:'12px', padding:'13px 14px', cursor: t.schema?'pointer':'default', border:'1px solid '+(t.degraded?'rgba(242,182,97,.28)':'rgba(255,255,255,.07)'), background:(t.degraded?'linear-gradient(180deg,rgba(242,182,97,.06),#1a1f21)':'#1a1f21') }">
                        <div class="flex items-center" style="gap:9px">
                            <div class="fz-mono" style="width:28px;height:28px;flex:0 0 28px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700"
                                 :style="{ background:(t.degraded?'rgba(242,182,97,.14)':'rgba(234,99,140,.12)'), border:'1px solid '+(t.degraded?'rgba(242,182,97,.35)':'rgba(234,99,140,.3)'), color:(t.degraded?'#f5c987':'#ea638c') }" x-text="t.glyph"></div>
                            <div class="fz-mono" style="font-size:12.5px;color:#e4e9ea;min-width:0;overflow:hidden;text-overflow:ellipsis" x-text="t.name"></div>
                            <span style="margin-left:auto;width:7px;height:7px;border-radius:50%" :style="{ background:(t.degraded?'#f2b661':'#3ecf8e'), animation:(t.degraded?'breathe 2s ease-in-out infinite':'none') }"></span>
                        </div>
                        <div style="font-size:11.5px;line-height:1.55;color:#a3adb1;margin-top:9px;min-height:34px" x-text="t.description"></div>
                        <div class="fz-mono flex items-center" style="gap:10px;margin-top:10px;padding-top:9px;border-top:1px solid rgba(255,255,255,.06);font-size:9.5px;color:#8a9499">
                            <span x-show="t.schema" x-text="openSchema[t.name] ? 'hide schema ▴' : 'schema ▾'"></span>
                            <span style="margin-left:auto" :style="{ color:(t.degraded?'#f5c987':'#5fdda5') }" x-text="t.degraded ? 'DEGRADED' : 'OK'"></span>
                        </div>
                        <template x-if="openSchema[t.name]">
                            <pre @click.stop class="fz-mono fz-scroll" style="margin:10px 0 0;padding:10px 12px;border-radius:9px;background:#0e1214;border:1px solid rgba(255,255,255,.06);font-size:10.5px;line-height:1.55;color:#9aa8ac;max-height:200px;overflow:auto;white-space:pre-wrap" x-text="JSON.stringify(t.schema, null, 2)"></pre>
                        </template>
                    </div>
                </template>
            </div>
        </section>

        {{-- Ollama + runtime aside --}}
        <aside class="to-side">

            {{-- Ollama --}}
            <div style="border-radius:14px;border:1px solid rgba(234,99,140,.24);background:radial-gradient(600px 200px at 20% -60%,rgba(234,99,140,.16),transparent 60%),linear-gradient(180deg,#23282c,#1b2021);padding:16px">
                <div class="flex items-center" style="gap:9px;margin-bottom:13px">
                    <div style="width:7px;height:7px;border-radius:50%;background:#ea638c;animation:breathe 2s ease-in-out infinite"></div>
                    <span class="fz-mono" style="font-size:10px;letter-spacing:.13em;color:#ea638c" x-text="'OLLAMA · ' + (status.base_url || 'localhost:11434')"></span>
                    <span class="fz-mono" style="margin-left:auto;font-size:9.5px" :style="{ color: status.reachable ? '#5fdda5' : '#ff9b9b' }" x-text="status.reachable ? 'up' : 'down'"></span>
                </div>

                <form method="POST" action="{{ route('ollama.pull') }}" class="flex" style="gap:7px">
                    @csrf
                    <input name="model" required placeholder="model:tag" class="fz-mono"
                           style="flex:1;min-width:0;padding:9px 12px;border-radius:9px;background:rgba(0,0,0,.36);border:1px solid rgba(255,255,255,.1);color:#e4e9ea;font-size:12px">
                    <button type="submit" style="font-size:12px;font-weight:600;padding:9px 15px;border-radius:9px;border:1px solid rgba(255,217,218,.35);background:linear-gradient(145deg,#ea638c,#89023e);color:#fff;cursor:pointer"
                            onmouseover="this.style.filter='brightness(1.1)'" onmouseout="this.style.filter='none'">Pull</button>
                </form>
                <div class="fz-mono" style="font-size:9.5px;color:#8a9499;margin-top:7px">Large models take a few minutes; the list below refreshes as it lands.</div>

                <div style="margin-top:15px;padding-top:13px;border-top:1px solid rgba(255,255,255,.07)">
                    <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499;margin-bottom:10px">RESIDENT MODELS</div>
                    <div class="flex flex-col" style="gap:7px">
                        <template x-for="m in residentModels" :key="m.name">
                            <div class="flex items-center" style="gap:9px;padding:8px 10px;border-radius:9px" :style="{ background: m.bg, border: '1px solid '+m.bd }">
                                <span style="width:6px;height:6px;flex:0 0 6px;border-radius:50%" :style="{ background: m.dot }"></span>
                                <span class="fz-mono" style="font-size:11.5px;min-width:0;overflow:hidden;text-overflow:ellipsis" :style="{ color: m.fg }" x-text="m.name"></span>
                                <span class="fz-mono" style="margin-left:auto;font-size:9.5px;color:#96a0a5" x-text="m.size"></span>
                                <span class="fz-mono" style="font-size:9px;padding:2px 6px;border-radius:5px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:#a3adb1" x-text="m.tag"></span>
                            </div>
                        </template>
                        <div x-show="!residentModels.length" class="fz-mono" style="font-size:11px;color:#8a9499">No models installed.</div>
                    </div>
                </div>
            </div>

            {{-- Runtime + services --}}
            <div style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#1a1f21;padding:15px;display:flex;flex-direction:column;gap:11px">
                <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499">RUNTIME</div>
                <div class="fz-mono flex flex-col" style="gap:9px;font-size:10.5px">
                    <div class="flex justify-between"><span style="color:#8a9499">version</span><span style="color:#a3adb1" x-text="status.version || '—'"></span></div>
                    <div class="flex justify-between"><span style="color:#8a9499">driver</span><span style="color:#a3adb1" x-text="status.active_driver || '{{ $llmDriver }}'"></span></div>
                    <div class="flex justify-between"><span style="color:#8a9499">context</span><span style="color:#a3adb1">{{ config('research.llm.ollama.num_ctx', config('research.llm.num_ctx', '—')) }}</span></div>
                    <div class="flex justify-between"><span style="color:#8a9499">keep-alive</span><span style="color:#a3adb1">{{ config('research.llm.ollama.keep_alive', '30m') }}</span></div>
                </div>
                <div style="padding-top:11px;border-top:1px solid rgba(255,255,255,.06);display:flex;flex-direction:column;gap:8px">
                    <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499">SERVICES</div>
                    <template x-for="svc in services" :key="svc.k">
                        <div class="fz-mono flex items-center" style="gap:8px;font-size:10.5px">
                            <span style="width:6px;height:6px;border-radius:50%" :style="{ background: svc.ok ? '#3ecf8e' : '#ff6b6b' }"></span>
                            <span style="color:#a3adb1" x-text="svc.k"></span>
                            <span style="margin-left:auto;color:#8a9499" x-text="svc.url"></span>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Free-search quick test --}}
            <div style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#1a1f21;padding:15px">
                <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499;margin-bottom:11px">FREE SEARCH TEST</div>
                <div class="flex" style="gap:7px">
                    <input x-model="testQuery" @keydown.enter="testBrowser()" placeholder="try a query…" class="fz-mono"
                           style="flex:1;min-width:0;padding:8px 11px;border-radius:8px;background:rgba(0,0,0,.34);border:1px solid rgba(255,255,255,.1);color:#e4e9ea;font-size:11.5px">
                    <button @click="testBrowser()" :disabled="testing" class="fz-mono" style="font-size:11px;padding:8px 13px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);color:#c8d0d3;cursor:pointer" x-text="testing ? '…' : 'Search'"></button>
                </div>
                <template x-if="testError"><p style="margin:8px 0 0;font-size:11px;color:#ff9b9b" x-text="testError"></p></template>
                <div x-show="testResults.length" class="flex flex-col" style="gap:4px;margin-top:9px">
                    <template x-for="r in testResults" :key="r.url">
                        <a :href="r.url" target="_blank" style="display:block;font-size:11px;text-decoration:none">
                            <span style="color:#ffb3c4" x-text="r.title"></span>
                            <span class="fz-mono" style="color:#8a9499;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="r.url"></span>
                        </a>
                    </template>
                </div>
            </div>
        </aside>
    </div>

    {{-- Agent-built skills --}}
    @if (count($skills))
        <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499;margin:26px 0 12px">AGENT-BUILT SKILLS · {{ count($skills) }}</div>
        <div class="flex flex-col" style="gap:12px">
            @foreach ($skills as $s)
                <div style="border-radius:13px;border:1px solid {{ $s->promoted ? 'rgba(62,207,142,.3)' : 'rgba(255,255,255,.07)' }};background:#1a1f21;padding:15px">
                    <div class="flex" style="justify-content:space-between;gap:12px;align-items:flex-start">
                        <div style="min-width:0">
                            <span class="fz-mono" style="font-size:12.5px;color:#ffb3c4">{{ $s->name }}</span>
                            @if ($s->promoted)<span class="fz-mono" style="margin-left:8px;font-size:9px;padding:2px 6px;border-radius:5px;background:rgba(62,207,142,.12);color:#5fdda5;border:1px solid rgba(62,207,142,.3)">to build into core</span>@endif
                            <span class="fz-mono" style="margin-left:8px;font-size:9.5px;color:#8a9499">used {{ $s->run_count }}×</span>
                            <p style="font-size:12.5px;color:#a3adb1;margin:6px 0 0">{{ $s->description }}</p>
                            <pre class="fz-mono fz-scroll" style="margin:9px 0 0;padding:10px 12px;border-radius:9px;background:#0e1214;border:1px solid rgba(255,255,255,.06);font-size:11px;color:#9aa8ac;overflow-x:auto">{{ $s->command }}</pre>
                        </div>
                        <div class="flex flex-col shrink-0" style="gap:6px">
                            <form method="POST" action="{{ route('skills.promote', $s->id) }}">@csrf
                                <button class="fz-mono" style="width:100%;font-size:10px;padding:5px 10px;border-radius:7px;cursor:pointer;border:1px solid {{ $s->promoted ? 'rgba(255,255,255,.12)' : 'rgba(62,207,142,.4)' }};background:rgba(255,255,255,.03);color:{{ $s->promoted ? '#a3adb1' : '#5fdda5' }}">{{ $s->promoted ? 'Unmark' : 'Promote' }}</button>
                            </form>
                            <form method="POST" action="{{ route('skills.delete', $s->id) }}" onsubmit="return confirm('Delete skill?')">@csrf @method('DELETE')
                                <button class="fz-mono" style="width:100%;font-size:10px;padding:5px 10px;border-radius:7px;cursor:pointer;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#8a9499">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@push('scripts')
<script>
function ollama() {
    const rawTools = @json(array_values($tools));
    const searchKeySet = @json($searchKeySet);
    const glyph = name => (String(name).split(/[._\s-]+/).map(w => w[0] || '').join('') || name.slice(0,2)).slice(0,2).toUpperCase();

    return {
        status: @json($status),
        models: @json($models),
        running: @json($running),
        browser: @json($browser),
        sandbox: @json($sandbox),
        filter: 'ALL',
        openSchema: {},
        testQuery: '', testResults: [], testError: '', testing: false,

        start() { this.timer = setInterval(() => this.refresh(), 5000); },
        async refresh() {
            try {
                const d = await (await fetch('{{ route('ui.ollama.status') }}')).json();
                this.status = d.status; this.models = d.models; this.running = d.running; this.browser = d.browser; this.sandbox = d.sandbox;
            } catch (e) {}
        },

        get tools() {
            return rawTools.map(t => ({
                name: t.name, description: t.description, schema: t.schema, glyph: glyph(t.name),
                degraded: !searchKeySet && /search/i.test(t.name),
            }));
        },
        get filteredTools() {
            const f = this.filter;
            return this.tools.filter(t => f === 'ALL' ? true : f === 'ACTIVE' ? !t.degraded : t.degraded);
        },
        get residentModels() {
            const running = new Set((this.running || []).map(r => r.name));
            return (this.models || []).map(m => {
                const on = running.has(m.name);
                return {
                    name: m.name, size: m.size,
                    tag: on ? 'active' : (m.parameter_size || m.quantization || 'model'),
                    dot: on ? '#3ecf8e' : '#5a6367',
                    fg: on ? '#ffd9da' : '#c8d0d3',
                    bg: on ? 'rgba(62,207,142,.07)' : 'rgba(255,255,255,.03)',
                    bd: on ? 'rgba(62,207,142,.24)' : 'rgba(255,255,255,.06)',
                };
            });
        },
        get services() {
            return [
                { k: 'ollama',  ok: !!this.status.reachable,  url: this.status.base_url || '' },
                { k: 'browser', ok: !!this.browser.reachable, url: this.browser.base_url || '' },
                { k: 'sandbox', ok: !!this.sandbox.reachable, url: this.sandbox.base_url || '' },
            ];
        },

        async testBrowser() {
            if (!this.testQuery.trim() || this.testing) return;
            this.testing = true; this.testError = ''; this.testResults = [];
            try {
                const d = await window.postJson('{{ route('browser.test') }}', { query: this.testQuery });
                if (d.ok) this.testResults = d.results || []; else this.testError = d.error || 'search failed';
            } catch (e) { this.testError = 'request failed'; }
            finally { this.testing = false; }
        },
    };
}
</script>
@endpush
@endsection
