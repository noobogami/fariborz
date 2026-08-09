{{-- Tools & Gateway — a single operational section rendered inline under Settings,
     after the editable config groups. `display:contents` lets the <section> sit as a
     direct flex child of .set-main so it aligns with the config cards. --}}
<style>
    @keyframes breathe{0%,100%{opacity:.55}50%{opacity:1}}
    .to-cols{display:flex;gap:22px;align-items:flex-start;flex-wrap:wrap}
    .to-main{flex:1 1 520px;min-width:0}
    .to-side{flex:0 0 320px;display:flex;flex-direction:column;gap:14px}
    @media (max-width:1080px){ .to-side{flex-basis:100%} }
    .to-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:11px}
</style>

<div x-data="toolsPanel()" x-init="start()" style="display:contents">
    <section id="tools" style="border-radius:14px;background:#1a1f21;border:1px solid rgba(255,255,255,.07);overflow:hidden;scroll-margin-top:16px">

        {{-- section header --}}
        <div class="flex flex-wrap items-center" style="gap:11px;padding:13px 16px;border-bottom:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.02)">
            <span class="fz-mono" style="font-size:10.5px;letter-spacing:.14em;color:#c8d0d3">TOOLS</span>
            <span class="fz-mono" style="font-size:10px;color:#8a9499"><span x-text="tools.length"></span> tools · {{ count($skills) }} agent-built · <span x-text="gateway.reachable ? 'gateway up' : 'gateway down'" :style="{ color: gateway.reachable ? '#5fdda5' : '#ff9b9b' }"></span></span>
            <div class="flex items-center" style="margin-left:auto;gap:6px;flex-wrap:wrap">
                <template x-for="f in ['ALL','ACTIVE','ATTENTION']" :key="f">
                    <button @click="filter = f" class="fz-mono"
                            :style="{ fontSize:'10px', letterSpacing:'.08em', padding:'5px 11px', borderRadius:'20px', cursor:'pointer', border:'1px solid '+(filter===f?'rgba(234,99,140,.4)':'rgba(255,255,255,.08)'), background:(filter===f?'rgba(234,99,140,.16)':'rgba(255,255,255,.03)'), color:(filter===f?'#ffd9da':'#96a0a5') }"
                            x-text="f"></button>
                </template>
                <button onclick="location.reload()" class="fz-mono" style="font-size:10px;letter-spacing:.08em;padding:5px 11px;border-radius:20px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#96a0a5;cursor:pointer"
                        onmouseover="this.style.color='#fff'" onmouseout="this.style.color='#96a0a5'">RE-SCAN</button>
            </div>
        </div>

        <div style="padding:16px">

            {{-- Web search key warning --}}
            @unless ($searchKeySet)
                <section class="flex flex-wrap" style="align-items:flex-start;gap:14px;border-radius:13px;border:1px solid rgba(242,182,97,.3);background:linear-gradient(90deg,rgba(242,182,97,.1),rgba(27,32,33,.7));padding:15px 17px;margin-bottom:18px">
                    <div class="fz-mono" style="width:26px;height:26px;flex:0 0 26px;border-radius:8px;background:rgba(242,182,97,.16);border:1px solid rgba(242,182,97,.4);display:flex;align-items:center;justify-content:center;font-size:13px;color:#f5c987">!</div>
                    <div style="flex:1 1 340px;min-width:0">
                        <div style="font-size:13.5px;font-weight:600;color:#f5c987">Web search key missing — <span class="fz-mono" style="font-size:12.5px;font-weight:400">SEARCH_API_KEY</span> is not set</div>
                        <div style="font-size:12.5px;line-height:1.6;color:#c4ccce;margin-top:5px;max-width:88ch">The agent falls back to the headless-browser scraper — ~7× slower and rate-limited. Add a free-tier <b>Tavily</b>, <b>Brave</b> or <b>SerpAPI</b> key under <a href="#search-apis" style="color:#f5c987">Search APIs</a> above and the matching search tool turns on automatically.</div>
                    </div>
                </section>
            @endunless

            <div class="to-cols">

                {{-- Tool inventory (main column) --}}
                <section class="to-main">
                    <div class="to-grid">
                        <template x-for="t in filteredTools" :key="t.name">
                            <div @click="t.schema && (openSchema[t.name] = !openSchema[t.name])"
                                 :style="{ borderRadius:'12px', padding:'13px 14px', cursor: t.schema?'pointer':'default', border:'1px solid '+(t.degraded?'rgba(242,182,97,.28)':'rgba(255,255,255,.07)'), background:(t.degraded?'linear-gradient(180deg,rgba(242,182,97,.06),#151a1c)':'#151a1c') }">
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

                {{-- Gateway + runtime aside (vertical stack) --}}
                <aside class="to-side">

                    {{-- LLM gateway (LiteLLM / OpenAI-compatible router) — shown when it's the
                         active driver or reachable. Lists the models it serves = the names a
                         tier can point at. --}}
                    <div x-show="gateway.is_active_driver || gateway.reachable"
                         style="border-radius:13px;border:1px solid rgba(234,99,140,.24);background:radial-gradient(600px 200px at 20% -60%,rgba(234,99,140,.12),transparent 60%),linear-gradient(180deg,#23282c,#1b2021);padding:15px">
                        <div class="flex items-center" style="gap:9px;margin-bottom:11px">
                            <div style="width:7px;height:7px;border-radius:50%" :style="{ background: gateway.reachable ? '#5fdda5' : '#ff9b9b' }"></div>
                            <span class="fz-mono" style="font-size:10px;letter-spacing:.13em;color:#ea638c;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="'GATEWAY · ' + (gateway.base_url || 'localhost:4000')"></span>
                            <span class="fz-mono" style="margin-left:auto;flex:0 0 auto;font-size:9.5px" :style="{ color: gateway.reachable ? '#5fdda5' : '#ff9b9b' }" x-text="gateway.reachable ? 'up' : 'down'"></span>
                        </div>

                        <div class="fz-mono flex items-center" style="gap:8px;font-size:10px;margin-bottom:11px">
                            <span style="color:#8a9499" x-text="(gateway.model_count || 0) + ' models'"></span>
                            <span x-show="gateway.is_active_driver" style="margin-left:auto" :style="{ color: modelDrift ? '#f2b661' : '#5fdda5' }"
                                  x-text="'active · ' + (gateway.active_model || 'none')"></span>
                            <span x-show="!gateway.is_active_driver" style="margin-left:auto;color:#f2b661">driver: <span x-text="gateway.active_driver"></span></span>
                        </div>

                        {{-- The saved model name no longer exists on the gateway (renamed or
                             removed). Jobs keep running — the catalogue routes them to a live
                             model — but the setting is lying, so say so and where to fix it. --}}
                        <template x-if="modelDrift">
                            <div class="fz-mono" style="font-size:10px;line-height:1.55;color:#f5c987;border:1px solid rgba(242,182,97,.3);background:rgba(242,182,97,.07);border-radius:9px;padding:8px 10px;margin-bottom:11px">
                                <span x-text="'“' + gateway.configured_model + '” is not on this gateway — running on “' + gateway.active_model + '”.'"></span>
                                <a href="#llm" style="color:#f2b661">Pick a real one ↑</a>
                            </div>
                        </template>

                        <template x-if="gateway.reachable && (gateway.models || []).length">
                            <div class="flex flex-wrap" style="gap:6px">
                                <template x-for="m in gateway.models" :key="m">
                                    <span class="fz-mono" style="font-size:10px;padding:3px 8px;border-radius:6px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#a3adb1"
                                          :style="{ borderColor: m === gateway.active_model ? 'rgba(95,221,165,.4)' : 'rgba(255,255,255,.08)', color: m === gateway.active_model ? '#5fdda5' : '#a3adb1' }" x-text="m"></span>
                                </template>
                            </div>
                        </template>
                        <div x-show="gateway.reachable && !(gateway.models || []).length" class="fz-mono" style="font-size:11px;line-height:1.55;color:#f5c987">
                            No models on this gateway yet — <b>nothing can run</b> until you add one below.
                        </div>
                        <div x-show="!gateway.reachable" class="fz-mono" style="font-size:11px;color:#ff9b9b">Unreachable — is the <span style="color:#c8d0d3">litellm</span> service up?</div>
                    </div>

                    {{-- Gateway models — add/remove models on the LiteLLM gateway (DB-backed) --}}
                    <div x-show="gateway.is_active_driver || gateway.reachable"
                         style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#151a1c;padding:15px;margin-bottom:14px">
                        <div class="flex items-center" style="gap:9px;margin-bottom:11px">
                            <span class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499">GATEWAY MODELS</span>
                            <button type="button" @click="checkHealth()" :disabled="checkingHealth" title="LiteLLM /health — makes a real call to every model" class="fz-mono"
                                    style="margin-left:auto;font-size:9px;color:#c8d0d3;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:3px 8px;cursor:pointer"
                                    x-text="checkingHealth ? 'checking…' : 'check health'"></button>
                            <a :href="(gateway.base_url||'').replace(/\/v1\/?$/,'') + '/ui'" target="_blank" class="fz-mono" style="font-size:9px;color:#8e9a9f;text-decoration:none">LiteLLM UI ↗</a>
                        </div>

                        {{-- current models — ALSO the live health indicator. Dot: grey =
                             unknown, slow-blinking = checking, green = up, red = down
                             (hover a red chip for the error). Driven by "check health". --}}
                        <div class="flex flex-wrap items-center" style="gap:6px;margin-bottom:13px">
                            <template x-for="m in gatewayModels" :key="m.name">
                                <span class="fz-mono flex items-center" :title="modelHealthError[m.name] || ''"
                                      style="gap:6px;font-size:10px;padding:4px 8px;border-radius:6px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#dbe2e4">
                                    <span style="width:7px;height:7px;border-radius:50%;flex:0 0 7px"
                                          :style="{ background: dotColor(m.name), animation: checkingHealth ? 'breathe 1.1s ease-in-out infinite' : 'none' }"></span>
                                    <span x-text="m.name"></span>
                                    <button type="button" @click="removeModel(m.name)" title="remove" style="background:none;border:none;color:#8a9499;cursor:pointer;font-size:10px;padding:0;line-height:1">✕</button>
                                </span>
                            </template>
                            <span x-show="!gatewayModels.length" class="fz-mono" style="font-size:10px;color:#8a9499">No models yet — add one below.</span>
                            <span x-show="healthMsg" class="fz-mono" style="font-size:9px;color:#8a9499;margin-left:2px" x-text="healthMsg"></span>
                        </div>

                        {{-- add-model form --}}
                        <div style="border-top:1px solid rgba(255,255,255,.07);padding-top:12px;display:flex;flex-direction:column;gap:9px">
                            <div class="fz-mono" style="font-size:9px;letter-spacing:.12em;color:#8a9499">ADD MODEL</div>

                            <label class="fz-mono" style="font-size:9.5px;color:#8a9499;display:block">Provider
                                <div style="position:relative;margin-top:4px">
                                    <select x-model="mform.provider" class="fz-mono" style="width:100%;padding:7px 28px 7px 10px;border-radius:7px;background:#101416;border:1px solid rgba(255,255,255,.1);color:#e4e9ea;font-size:11px;appearance:none;-webkit-appearance:none;cursor:pointer">
                                        <template x-for="(p,key) in gatewayProviders" :key="key"><option :value="key" x-text="p.label" style="background:#101416;color:#e4e9ea"></option></template>
                                    </select>
                                    <span class="fz-mono" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none;font-size:9px;color:#8a9499">▾</span>
                                </div>
                            </label>

                            <label class="fz-mono" style="font-size:9.5px;color:#8a9499;display:block">Model
                                <template x-if="formIsLocal">
                                    <div>
                                        <input x-model="mform.model" placeholder="an installed Ollama tag, e.g. qwen3:8b" class="fz-mono" style="width:100%;margin-top:4px;padding:7px 10px;border-radius:7px;background:#101416;border:1px solid rgba(255,255,255,.1);color:#e4e9ea;font-size:11px">
                                        <div class="fz-mono" style="font-size:8.5px;color:#6f797d;margin-top:3px">the tag as the gateway sees it (`ollama list` on the gateway's Ollama host)</div>
                                    </div>
                                </template>
                                <template x-if="!formIsLocal">
                                    <div>
                                        <input x-model="mform.model" :list="'gwmodels-'+mform.provider" placeholder="exact api id, e.g. gemini-flash-latest" class="fz-mono" style="width:100%;margin-top:4px;padding:7px 10px;border-radius:7px;background:#101416;border:1px solid rgba(255,255,255,.1);color:#e4e9ea;font-size:11px">
                                        <datalist :id="'gwmodels-'+mform.provider">
                                            <template x-for="mm in (formProvider.models||[])" :key="mm"><option :value="mm"></option></template>
                                        </datalist>
                                        <div class="fz-mono" style="font-size:8.5px;color:#6f797d;margin-top:3px">the provider's EXACT id — hyphens, no spaces</div>
                                    </div>
                                </template>
                            </label>

                            <label class="fz-mono" style="font-size:9.5px;color:#8a9499;display:block">Name <span style="color:#6f797d">(what you pick in the tier dropdowns)</span>
                                <input x-model="mform.name" placeholder="e.g. local-standard · my-gpt" class="fz-mono" style="width:100%;margin-top:4px;padding:7px 10px;border-radius:7px;background:#101416;border:1px solid rgba(255,255,255,.1);color:#e4e9ea;font-size:11px">
                            </label>

                            <template x-if="formNeedsKey">
                                <label class="fz-mono" style="font-size:9.5px;color:#8a9499;display:block">API key
                                    <input type="password" x-model="mform.api_key" placeholder="paste key — blank = use the gateway's env key" class="fz-mono" style="width:100%;margin-top:4px;padding:7px 10px;border-radius:7px;background:#101416;border:1px solid rgba(255,255,255,.1);color:#e4e9ea;font-size:11px">
                                </label>
                            </template>

                            <template x-if="formIsLocal">
                                <div class="flex" style="gap:12px;align-items:flex-end">
                                    <label class="fz-mono" style="font-size:9.5px;color:#8a9499;flex:1;display:block">Context (num_ctx)
                                        <input type="number" x-model.number="mform.num_ctx" class="fz-mono" style="width:100%;margin-top:4px;padding:7px 10px;border-radius:7px;background:#101416;border:1px solid rgba(255,255,255,.1);color:#e4e9ea;font-size:11px">
                                    </label>
                                    <label class="fz-mono flex items-center" style="font-size:10px;color:#8a9499;gap:6px;padding-bottom:8px;cursor:pointer">
                                        <input type="checkbox" x-model="mform.think" style="accent-color:#ea638c"> thinking
                                    </label>
                                </div>
                            </template>

                            <div class="flex items-center" style="gap:10px;margin-top:2px">
                                <button type="button" @click="addModel()" :disabled="adding || !mform.name || !mform.model" class="fz-mono"
                                        :style="{ opacity:(adding||!mform.name||!mform.model)?.5:1, cursor:(adding||!mform.name||!mform.model)?'default':'pointer', fontSize:'11px', fontWeight:'600', padding:'8px 15px', borderRadius:'8px', border:'1px solid rgba(255,217,218,.35)', background:'linear-gradient(145deg,#ea638c,#89023e)', color:'#fff' }"
                                        x-text="adding ? 'Adding…' : 'Add model'"></button>
                                <span class="fz-mono" style="font-size:9.5px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :style="{ color: (addMsg.includes('fail')||addMsg.includes('error')||addMsg.includes('exist'))?'#ff9b9b':'#5fdda5' }" x-text="addMsg"></span>
                            </div>
                        </div>
                    </div>

                    {{-- Runtime + services (live/effective facts only; editable values live above) --}}
                    <div style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#151a1c;padding:15px;display:flex;flex-direction:column;gap:11px">
                        <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499">RUNTIME</div>
                        <div class="fz-mono flex flex-col" style="gap:9px;font-size:10.5px">
                            <div class="flex justify-between"><span style="color:#8a9499">driver</span><span style="color:#a3adb1">{{ $llmDriver }}</span></div>
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
                    <div style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#151a1c;padding:15px">
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
                <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499;margin:22px 0 12px">AGENT-BUILT SKILLS · {{ count($skills) }}</div>
                <div class="flex flex-col" style="gap:12px">
                    @foreach ($skills as $s)
                        <div style="border-radius:13px;border:1px solid {{ $s->promoted ? 'rgba(62,207,142,.3)' : 'rgba(255,255,255,.07)' }};background:#151a1c;padding:15px">
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
    </section>
</div>

@push('scripts')
<script>
function toolsPanel() {
    const rawTools = @json(array_values($tools));
    const searchKeySet = @json($searchKeySet);
    const glyph = name => (String(name).split(/[._\s-]+/).map(w => w[0] || '').join('') || name.slice(0,2)).slice(0,2).toUpperCase();

    return {
        browser: @json($browser),
        sandbox: @json($sandbox),
        gateway: @json($gateway),
        gatewayModels: @json($gatewayModels ?? []),
        gatewayProviders: @json($gatewayProviders ?? []),
        mform: { provider: 'ollama', model: '', name: '', api_key: '', num_ctx: {{ (int) config('litellm.ollama_default_num_ctx', 16384) }}, think: false },
        adding: false,
        addMsg: '',
        modelHealth: {},        // name → 'up' | 'down'
        modelHealthError: {},   // name → error string
        healthMsg: '',
        checkingHealth: false,
        filter: 'ALL',
        openSchema: {},
        testQuery: '', testResults: [], testError: '', testing: false,

        start() { this.timer = setInterval(() => this.refresh(), 5000); },
        async refresh() {
            try {
                const d = await (await fetch('{{ route('ui.tools.status') }}')).json();
                this.browser = d.browser; this.sandbox = d.sandbox; this.gateway = d.gateway;
                if (d.gatewayModels) this.gatewayModels = d.gatewayModels;
            } catch (e) {}
        },

        // Model chip health dot: grey (unknown) → green (up) / red (down); it blinks
        // (via the chip's animation binding) while a health check is in flight.
        dotColor(name) {
            if (this.checkingHealth) return '#8a9499';
            const s = this.modelHealth[name];
            return s === 'up' ? '#3ecf8e' : s === 'down' ? '#ff6b6b' : '#5a6367';
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
        get services() {
            const s = [
                { k: 'browser', ok: !!this.browser.reachable, url: this.browser.base_url || '' },
                { k: 'sandbox', ok: !!this.sandbox.reachable, url: this.sandbox.base_url || '' },
            ];
            // Surface the gateway alongside the rest when it's in use or reachable.
            if (this.gateway && (this.gateway.is_active_driver || this.gateway.reachable)) {
                s.splice(0, 0, { k: 'gateway', ok: !!this.gateway.reachable, url: this.gateway.base_url || '' });
            }
            return s;
        },

        async checkHealth() {
            if (this.checkingHealth) return;
            this.checkingHealth = true; this.healthMsg = '';   // chips start blinking grey
            try {
                const d = await window.postJson('{{ route('gateway.health') }}', {});
                const mh = {}, me = {};
                (d.models || []).forEach(h => { mh[h.model] = h.healthy ? 'up' : 'down'; if (h.error) me[h.model] = h.error; });
                this.modelHealth = mh; this.modelHealthError = me;
                this.healthMsg = d.error ? d.error : ((d.healthy || 0) + ' up · ' + (d.unhealthy || 0) + ' down');
            } catch (e) { this.healthMsg = 'request failed'; }
            finally { this.checkingHealth = false; }   // chips settle to green/red
        },
        get formProvider() { return this.gatewayProviders[this.mform.provider] || {}; },
        get formIsLocal() { return !!this.formProvider.local; },
        get formNeedsKey() { return !!this.formProvider.key_env; },
        async addModel() {
            const model = String(this.mform.model).trim();
            const name = this.mform.name.trim();
            if (this.adding || !name || !model) return;
            if (/\s/.test(model)) { this.addMsg = 'model id has a space — use the exact id, e.g. gemini-flash-latest'; return; }
            this.adding = true; this.addMsg = '';
            try {
                const d = await window.postJson('{{ route('gateway.models.create') }}', {
                    name, provider: this.mform.provider, model,
                    api_key: this.mform.api_key, num_ctx: this.mform.num_ctx, think: this.mform.think ? 1 : 0,
                });
                if (d.models) this.gatewayModels = d.models;
                if (d.result && d.result.ok) { this.addMsg = 'added ' + name; this.mform.name = ''; this.mform.model = ''; this.mform.api_key = ''; }
                else if (d.errors) { this.addMsg = Object.values(d.errors)[0][0]; }
                else { this.addMsg = 'failed: ' + (d.result ? d.result.message : (d.message || 'error')); }
            } catch (e) { this.addMsg = 'request failed'; }
            finally { this.adding = false; }
        },
        async removeModel(name) {
            if (!confirm('Remove "' + name + '" from the gateway?')) return;
            this.addMsg = '';
            try {
                const d = await window.postJson('{{ route('gateway.models.delete') }}', { name });
                if (d.models) this.gatewayModels = d.models;
                this.addMsg = (d.result && d.result.ok) ? ('removed ' + name) : ('remove failed: ' + (d.result && d.result.message));
            } catch (e) { this.addMsg = 'request failed'; }
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
