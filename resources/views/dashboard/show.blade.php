@extends('dashboard.layout')
@section('title', 'Job detail')

@php
    $roleExtras = $initial['role_extras'] ?? ['role' => 'solo', 'max_iterations' => (int) config('research.limits.max_iterations', 40)];
    $role = $roleExtras['role'] ?? 'solo';
    $maxIter = (int) ($roleExtras['max_iterations'] ?? config('research.limits.max_iterations', 40));
    $llmModel = config('research.llm.model');
    $llmTemp = config('research.llm.temperature');
    $roleLabel = strtoupper($role);
    $roleBadge = [
        'supervisor' => ['bg' => 'rgba(234,99,140,.14)', 'bd' => 'rgba(234,99,140,.34)', 'fg' => '#ffb3c4'],
        'worker'     => ['bg' => 'rgba(255,255,255,.05)', 'bd' => 'rgba(255,255,255,.1)', 'fg' => '#96a0a5'],
        'solo'       => ['bg' => 'rgba(255,255,255,.04)', 'bd' => 'rgba(255,255,255,.1)', 'fg' => '#96a0a5'],
    ][$role] ?? ['bg' => 'rgba(255,255,255,.04)', 'bd' => 'rgba(255,255,255,.1)', 'fg' => '#96a0a5'];
@endphp

{{-- ===== frozen header ===== --}}
@section('header')
<div style="color:#eef1f2">
    <div class="flex items-start gap-6">
        <div class="min-w-0 flex-1">
            <div class="fz-mono flex flex-wrap items-center gap-2 mb-2" style="font-size:10.5px;color:#8a9499">
                <a href="{{ route('dashboard') }}" style="color:#8a9499">JOBS</a>
                <span style="color:#394246">/</span>
                <span style="color:#ea638c">job_{{ \Illuminate\Support\Str::substr($jobId, -8) }}</span>
                <span style="color:#394246">/</span>
                <span>ITER {{ $initial['job']['iteration'] ?? 0 }} OF {{ $maxIter }}</span>
                <span style="font-size:8.5px;letter-spacing:.11em;padding:2px 7px;border-radius:5px;background:{{ $roleBadge['bg'] }};border:1px solid {{ $roleBadge['bd'] }};color:{{ $roleBadge['fg'] }}">{{ $roleLabel }}</span>
            </div>
            <h1 style="margin:0;font-size:19px;line-height:1.32;font-weight:600;letter-spacing:-.015em;color:#f2f5f6;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;max-width:92ch">{{ $initial['job']['goal'] }}</h1>
        </div>
        <div class="flex gap-2 shrink-0" x-data="jobHeaderActions()">
            <button x-show="['running','waiting','pending'].includes(status)" @click="stop()"
                    class="fz-mono" style="font-size:12px;letter-spacing:.03em;padding:8px 14px;border-radius:8px;border:1px solid rgba(137,2,62,.5);background:rgba(137,2,62,.22);color:#ffb3c4;cursor:pointer"
                    onmouseover="this.style.background='rgba(137,2,62,.4)';this.style.color='#ffd9da'" onmouseout="this.style.background='rgba(137,2,62,.22)';this.style.color='#ffb3c4'">STOP</button>
            <button x-show="['completed','failed','cancelled'].includes(status)" @click="rerun()"
                    class="fz-mono" style="font-size:12px;letter-spacing:.03em;padding:8px 14px;border-radius:8px;border:1px solid rgba(62,207,142,.4);background:rgba(62,207,142,.14);color:#5fdda5;cursor:pointer"
                    onmouseover="this.style.background='rgba(62,207,142,.26)';this.style.color='#8ff0c4'" onmouseout="this.style.background='rgba(62,207,142,.14)';this.style.color='#5fdda5'">RERUN</button>
            <button x-show="status !== 'running'" @click="del()"
                    class="fz-mono" style="font-size:12px;letter-spacing:.03em;padding:8px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#c8d0d3;cursor:pointer"
                    onmouseover="this.style.background='rgba(255,255,255,.07)';this.style.color='#fff'" onmouseout="this.style.background='rgba(255,255,255,.03)';this.style.color='#c8d0d3'">DELETE</button>
        </div>
    </div>
</div>
@endsection

@section('content')
<style>
    @keyframes orbPulse{0%,100%{box-shadow:0 0 0 0 rgba(234,99,140,.55),0 0 18px 2px rgba(234,99,140,.35)}50%{box-shadow:0 0 0 9px rgba(234,99,140,0),0 0 26px 6px rgba(234,99,140,.18)}}
    @keyframes caret{0%,49%{opacity:1}50%,100%{opacity:0}}
    @keyframes sweep{0%{transform:translateX(-100%)}100%{transform:translateX(300%)}}
    @keyframes breathe{0%,100%{opacity:.55}50%{opacity:1}}
    @keyframes rowIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
    .fz-two{display:flex;gap:22px;align-items:flex-start;flex-wrap:wrap}
    .fz-tl{flex:1 1 560px;min-width:0;display:flex;flex-direction:column;gap:22px}
    .fz-side{flex:0 0 296px;display:flex;flex-direction:column;gap:14px}
    @media (max-width:1024px){ .fz-side{flex-basis:100%} }
</style>

<div x-data="jobDetail()" x-init="start()" style="color:#eef1f2">

    {{-- worker breadcrumb callout --}}
    <template x-if="isWorker && extras.parent">
        <a :href="'/jobs/' + extras.parent.id" class="flex items-center" style="gap:12px;margin-bottom:16px;padding:11px 15px;border-radius:12px;border:1px solid rgba(234,99,140,.26);background:linear-gradient(90deg,rgba(234,99,140,.11),rgba(27,32,33,.6));color:#dbe2e4;text-decoration:none"
           onmouseover="this.style.borderColor='rgba(234,99,140,.5)'" onmouseout="this.style.borderColor='rgba(234,99,140,.26)'">
            <span class="fz-mono" style="font-size:9px;letter-spacing:.11em;padding:3px 7px;border-radius:5px;background:rgba(234,99,140,.16);border:1px solid rgba(234,99,140,.34);color:#ffb3c4;flex:0 0 auto">SUB-AGENT</span>
            <span style="font-size:12.5px;line-height:1.5;min-width:0">part of <span class="fz-mono" style="color:#ffb3c4" x-text="'job_' + String(extras.parent.id).slice(-8)"></span> (supervisor)<template x-if="extras.parent.task_seq"><span> · task #<span x-text="extras.parent.task_seq"></span>: <span x-text="extras.parent.task_title"></span></span></template></span>
            <span class="fz-mono" style="margin-left:auto;font-size:10.5px;color:#96a0a5;flex:0 0 auto">up to supervisor →</span>
        </a>
    </template>

    {{-- ===== HERO ===== --}}
    <section style="position:relative;overflow:hidden;border-radius:16px"
             :style="{ border: '1px solid '+hero.bd, background: hero.bg, boxShadow: hero.shadow }">
        <div x-show="live" style="position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(255,217,218,.7),transparent);width:34%;animation:sweep 3.6s linear infinite"></div>

        <div class="flex flex-wrap" style="gap:28px;padding:22px 26px 20px">
            <div style="flex:1 1 440px;min-width:0">
                <div class="flex flex-wrap items-center gap-2" style="margin-bottom:14px">
                    <div :style="{ width:'9px', height:'9px', borderRadius:'50%', background: hero.accent, animation: (activity.active && !isDone) ? 'orbPulse 1.9s ease-out infinite' : 'none' }"></div>
                    <span class="fz-mono" style="font-size:10.5px;letter-spacing:.14em" :style="{ color: hero.accent }" x-text="heroStatus"></span>
                    <span class="fz-mono" style="font-size:10.5px;color:#8a9499" x-text="(isDone ? 'total ' : 'elapsed ') + elapsed"></span>
                    <template x-if="isDone">
                        <span class="fz-mono" style="font-size:10px;letter-spacing:.07em;padding:2px 9px;border-radius:20px;background:rgba(62,207,142,.12);border:1px solid rgba(62,207,142,.3);color:#5fdda5">GOAL SATISFIED</span>
                    </template>
                </div>

                <div class="flex flex-wrap items-baseline" style="gap:12px">
                    <div class="fz-mono" style="font-size:29px;font-weight:600;letter-spacing:-.02em;word-break:break-word" :style="{ color: hero.titleFg }" x-text="headline"></div>
                    <div style="font-size:13px;color:#98a2a7" x-text="heroSub"></div>
                </div>

                {{-- op line (waiting / review / parsing) --}}
                <template x-if="activity.waiting_for || activity.label">
                    <div class="fz-mono flex items-center gap-2" style="margin-top:12px;padding:9px 12px;border-radius:9px;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.06);font-size:11.5px;color:#a3adb1;max-width:640px;overflow:hidden">
                        <span :style="{ color: opFg }" style="flex:0 0 auto" x-text="opLabel"></span>
                        <span style="color:#39424f;flex:0 0 auto">│</span>
                        <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" x-text="activity.waiting_for || activity.label"></span>
                        <span x-show="activity.since_seconds !== null && !isDone" style="margin-left:auto;flex:0 0 auto" :style="{ color: opFg }" x-text="activity.since_seconds + 's'"></span>
                    </div>
                </template>

                {{-- FINAL ANSWER (completed) --}}
                <template x-if="isDone && job.report">
                    <div style="margin-top:16px;border-radius:11px;border:1px solid rgba(62,207,142,.24);background:linear-gradient(180deg,rgba(62,207,142,.07),rgba(16,20,22,.85));overflow:hidden">
                        <div class="flex items-center" style="gap:8px;padding:8px 12px;border-bottom:1px solid rgba(62,207,142,.16)">
                            <span class="fz-mono" style="font-size:9.5px;letter-spacing:.14em;color:#5fdda5">FINAL ANSWER<span x-show="job.partial" style="color:#f2b661"> · PARTIAL</span></span>
                            <span class="fz-mono" style="margin-left:auto;font-size:9.5px;color:#8a9499" x-text="(stats.total_events ?? 0) + ' events · conf ' + (job.confidence!=null?Number(job.confidence).toFixed(2):'—')"></span>
                        </div>
                        <div style="padding:13px 14px">
                            <div class="fz-scroll" :style="{ maxHeight: reportOpen ? '460px' : '108px', overflow: 'auto' }" style="transition:max-height .2s">
                                <div style="font-size:13.5px;line-height:1.62;color:#dbe2e4;white-space:pre-wrap;max-width:82ch" x-text="job.report"></div>
                            </div>
                            <div class="flex flex-wrap" style="gap:8px;margin-top:14px">
                                <button @click="reportOpen = !reportOpen" class="fz-mono" style="font-size:12px;font-weight:600;letter-spacing:.03em;padding:8px 15px;border-radius:8px;border:1px solid rgba(62,207,142,.4);background:rgba(62,207,142,.15);color:#5fdda5;cursor:pointer" x-text="reportOpen ? 'COLLAPSE' : 'OPEN REPORT'"></button>
                                <a :href="'{{ route('ui.job', $jobId) }}?full=1'" target="_blank" class="fz-mono" style="font-size:12px;letter-spacing:.03em;padding:8px 15px;border-radius:8px;border:1px solid rgba(255,255,255,.11);background:rgba(255,255,255,.03);color:#c8d0d3;text-decoration:none">EXPORT JSON</a>
                                <button @click="rerun()" class="fz-mono" style="font-size:12px;letter-spacing:.03em;padding:8px 15px;border-radius:8px;border:1px solid rgba(234,99,140,.32);background:rgba(234,99,140,.1);color:#ffb3c4;cursor:pointer">RERUN</button>
                            </div>
                        </div>
                    </div>
                </template>

                {{-- thinking stream (running) --}}
                <template x-if="!isDone && activity.thinking_preview">
                    <div style="margin-top:16px;border-radius:11px;border:1px solid rgba(255,255,255,.07);background:linear-gradient(180deg,rgba(16,20,22,.35),rgba(16,20,22,.85));overflow:hidden">
                        <div class="flex items-center gap-2" style="padding:8px 12px;border-bottom:1px solid rgba(255,255,255,.06)">
                            <span class="fz-mono" style="font-size:9.5px;letter-spacing:.14em;color:#96a0a5">THINKING STREAM</span>
                            <div class="flex" style="gap:3px;margin-left:2px">
                                <div style="width:3px;height:3px;border-radius:50%;background:#ea638c;animation:breathe 1.2s ease-in-out infinite"></div>
                                <div style="width:3px;height:3px;border-radius:50%;background:#ea638c;animation:breathe 1.2s ease-in-out .2s infinite"></div>
                                <div style="width:3px;height:3px;border-radius:50%;background:#ea638c;animation:breathe 1.2s ease-in-out .4s infinite"></div>
                            </div>
                        </div>
                        <div class="fz-scroll" style="max-height:120px;overflow:auto;padding:11px 14px">
                            <div class="fz-mono" style="white-space:pre-wrap;font-size:11.5px;line-height:1.75;color:#8e9a9f" x-text="activity.thinking_preview"></div><span style="display:inline-block;width:7px;height:13px;background:#ea638c;vertical-align:-2px;margin-left:2px;animation:caret 1s steps(1) infinite"></span>
                        </div>
                    </div>
                </template>
            </div>

            {{-- ring + vitals --}}
            <div class="flex flex-col" style="flex:0 0 246px;gap:14px">
                <div class="flex items-center" style="gap:16px">
                    <div style="position:relative;width:92px;height:92px;flex:0 0 auto;border-radius:50%" :style="{ background: 'conic-gradient('+ring.color+' 0deg '+ring.deg+'deg,rgba(255,255,255,.07) '+ring.deg+'deg 360deg)' }">
                        <div class="flex flex-col items-center justify-center" style="position:absolute;inset:7px;border-radius:50%;background:#1c2124">
                            <div class="fz-mono" style="font-size:8px;letter-spacing:.11em;color:#8a9499" x-text="ring.cap"></div>
                            <div class="fz-mono" style="font-size:19px;font-weight:600;line-height:1.1" :style="{ color: ring.fg }" x-text="ring.main"></div>
                            <div class="fz-mono" style="font-size:9px;color:#96a0a5;letter-spacing:.08em" x-text="ring.sub"></div>
                        </div>
                    </div>
                    <div class="flex flex-col" style="gap:9px;min-width:0">
                        <div class="fz-mono" style="font-size:9.5px;letter-spacing:.12em;color:#8a9499">CONFIDENCE</div>
                        <div class="flex items-baseline" style="gap:6px">
                            <span class="fz-mono" style="font-size:19px;font-weight:600" :style="{ color: job.confidence!=null?'#3ecf8e':'#8a9499' }" x-text="job.confidence != null ? Number(job.confidence).toFixed(2) : '—'"></span>
                            <template x-if="confDelta !== null && confDelta !== 0">
                                <span style="font-size:10.5px" :style="{ color: confDelta>0?'#3ecf8e':'#f2b661' }" x-text="(confDelta>0?'▲ ':'▼ ')+Math.abs(confDelta).toFixed(2)"></span>
                            </template>
                        </div>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.07);border-radius:10px;overflow:hidden">
                    <template x-for="v in vitals" :key="v.k">
                        <div style="background:#1c2124;padding:9px 11px">
                            <div class="fz-mono" style="font-size:9px;letter-spacing:.1em;color:#8a9499" x-text="v.k"></div>
                            <div class="fz-mono" style="font-size:15px;margin-top:2px" :style="{ color: v.c }" x-text="v.n"></div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </section>

    {{-- Continue a finished job with new guidance --}}
    <template x-if="['completed','failed','cancelled'].includes(job.status)">
        <div style="margin-top:22px;border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#1a1f21;padding:16px">
            <div style="font-size:13px;font-weight:600;color:#e4e9ea;margin-bottom:3px">Continue with new guidance</div>
            <p style="font-size:11.5px;color:#8a9499;margin:0 0 10px">Refine the result without starting over — the agent keeps everything it learned and adjusts.</p>
            <textarea x-model="guidance" rows="2" placeholder="e.g. Focus more on pricing, and double-check the revenue figure with a second source."
                      style="width:100%;border-radius:8px;background:#101416;border:1px solid rgba(255,255,255,.1);padding:8px 12px;font-size:13px;color:#eef1f2;outline:none"></textarea>
            <div class="flex items-center" style="gap:12px;margin-top:10px">
                <button @click="continueJob()" :disabled="continuing || !guidance.trim()"
                        class="fz-mono" style="border-radius:8px;background:linear-gradient(145deg,#ea638c,#89023e);padding:8px 16px;font-size:12px;font-weight:600;color:#1b2021;cursor:pointer;border:none" :style="(continuing||!guidance.trim()) ? { opacity:.4, cursor:'default' } : {}"
                        x-text="continuing ? 'RESUMING…' : 'CONTINUE →'"></button>
                <span style="font-size:11px;color:#8a9499">Adds ~20 iterations of budget from where it stopped.</span>
            </div>
        </div>
    </template>

    {{-- ===== two column ===== --}}
    <div class="fz-two" style="margin-top:22px">

        <section class="fz-tl">

            {{-- PLAN / TASK LIST (supervisor only) --}}
            <template x-if="isSupervisor">
                <div>
                    <div class="flex flex-wrap items-center" style="gap:12px;margin-bottom:14px">
                        <h2 style="margin:0;font-size:13px;font-weight:600;letter-spacing:.02em;color:#e4e9ea">Plan</h2>
                        <span class="fz-mono" style="font-size:10.5px;color:#8a9499" x-text="planSummary"></span>
                    </div>
                    <div style="border:1px solid rgba(255,255,255,.07);border-radius:13px;overflow:hidden;background:#1a1f21">
                        <template x-for="(t, i) in tasks" :key="t.seq">
                            <div style="border-top:1px solid rgba(255,255,255,.05)" :style="{ background: t.tone.row, borderTopWidth: i === 0 ? '0' : '1px' }">
                                <div @click="toggleTask(t.seq)" class="flex" style="gap:13px;align-items:flex-start;padding:12px 15px;cursor:pointer">
                                    <div class="flex items-center justify-center fz-mono" style="flex:0 0 30px;height:30px;border-radius:9px;font-size:11px;font-weight:700"
                                         :style="{ background: t.tone.nodeBg, border: '1px solid '+t.tone.nodeBd, color: t.tone.nodeFg, boxShadow: t.tone.nodeGlow }" x-text="t.seq"></div>
                                    <div style="min-width:0;flex:1">
                                        <div class="flex flex-wrap items-center" style="gap:10px">
                                            <span style="font-size:13px;font-weight:600;letter-spacing:-.005em" :style="{ color: t.tone.titleFg }" x-text="t.title"></span>
                                            <span class="fz-mono" style="font-size:9px;letter-spacing:.07em;padding:2px 8px;border-radius:20px" :style="{ background: t.tone.chipBg, color: t.tone.chipFg, border: '1px solid '+t.tone.chipBd }" x-text="t.statusLabel"></span>
                                        </div>
                                        <div style="font-size:12.5px;line-height:1.55;color:#98a2a7;margin-top:5px;max-width:70ch" x-text="t.brief"></div>
                                    </div>
                                    <div class="fz-mono flex items-center" style="flex:0 0 auto;gap:14px;font-size:10.5px;padding-top:2px">
                                        <span :style="{ color: t.attempts > 1 ? '#ff9b9b' : '#8a9499' }" x-text="t.attempts + ' att'"></span>
                                        <span style="width:92px;text-align:right;color:#ea638c" x-text="t.worker ? ('job_' + String(t.worker).slice(-6)) : 'unassigned'"></span>
                                        <span style="color:#4d565b;width:10px" x-text="taskOpen[t.seq] ? '▴' : '▾'"></span>
                                    </div>
                                </div>
                                <template x-if="taskOpen[t.seq]">
                                    <div @click.stop style="border-top:1px solid rgba(255,255,255,.07);padding:14px 15px;display:flex;flex-direction:column;gap:12px;background:rgba(0,0,0,.22)">
                                        <div>
                                            <div class="fz-mono" style="font-size:9px;letter-spacing:.13em;color:#8a9499;margin-bottom:5px">BRIEF TO WORKER</div>
                                            <div style="font-size:12.5px;line-height:1.65;color:#b6bfc3;max-width:82ch" x-text="t.brief"></div>
                                        </div>
                                        <template x-if="t.result">
                                            <div>
                                                <div class="fz-mono" style="font-size:9px;letter-spacing:.13em;color:#8a9499;margin-bottom:5px" x-text="t.resultLabel"></div>
                                                <pre class="fz-mono fz-scroll" style="margin:0;padding:10px 12px;border-radius:9px;background:#0e1214;border:1px solid rgba(255,255,255,.06);font-size:11px;line-height:1.6;color:#9aa8ac;max-height:170px;overflow:auto;white-space:pre-wrap" x-text="t.result"></pre>
                                            </div>
                                        </template>
                                        <div class="fz-mono flex flex-wrap items-center" style="gap:18px;font-size:10px;color:#8a9499;border-top:1px solid rgba(255,255,255,.06);padding-top:10px">
                                            <span x-text="'attempts ' + t.attempts"></span>
                                            <span x-text="'updated ' + (t.at || '—')"></span>
                                            <template x-if="t.worker"><a :href="'/jobs/' + t.worker" style="margin-left:auto">open worker job →</a></template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <div x-show="!tasks.length" style="padding:20px;text-align:center;color:#8a9499;font-size:13px">No tasks planned yet…</div>
                    </div>
                </div>
            </template>

            {{-- FLOW OF THINKING --}}
            <div>
                <template x-if="job.report && !isDone">
                    <details style="margin-bottom:18px;border-radius:13px;border:1px solid rgba(62,207,142,.3);background:linear-gradient(180deg,rgba(62,207,142,.07),rgba(27,32,33,.6));padding:14px">
                        <summary class="fz-mono" style="font-size:10px;letter-spacing:.13em;color:#5fdda5;cursor:pointer">REPORT SO FAR<span x-show="job.partial" style="color:#f2b661"> · PARTIAL</span></summary>
                        <div style="font-size:13px;white-space:pre-wrap;margin-top:10px;line-height:1.65;color:#c8d0d3" x-text="job.report"></div>
                    </details>
                </template>

                <div class="flex flex-wrap items-center" style="gap:12px;margin-bottom:14px">
                    <h2 style="margin:0;font-size:13px;font-weight:600;letter-spacing:.02em;color:#e4e9ea">Flow of thinking</h2>
                    <span class="fz-mono" style="font-size:10.5px;color:#8a9499" x-text="flowNote"></span>
                    <div class="flex items-center" style="margin-left:auto;gap:6px">
                        <button @click="detailed = !detailed" class="fz-mono"
                                :style="{ fontSize:'10px', letterSpacing:'.06em', padding:'5px 11px', borderRadius:'20px', cursor:'pointer', border:'1px solid rgba(255,255,255,.1)', background:'rgba(255,255,255,.03)', color:'#a3adb1' }"
                                x-text="detailed ? '↤ SUMMARY' : 'EVERY EVENT'"></button>
                        <template x-for="f in ['ALL','TOOLS','THINKING','ERRORS']" :key="f">
                            <button @click="filter = f" class="fz-mono"
                                    :style="{ fontSize:'10px', letterSpacing:'.08em', padding:'5px 11px', borderRadius:'20px', cursor:'pointer', border:'1px solid '+(filter===f?'rgba(234,99,140,.4)':'rgba(255,255,255,.08)'), background:(filter===f?'rgba(234,99,140,.16)':'rgba(255,255,255,.03)'), color:(filter===f?'#ffd9da':'#8e9a9f') }"
                                    x-text="f"></button>
                        </template>
                    </div>
                </div>

                <div style="position:relative">
                    <div style="position:absolute;left:19px;top:8px;bottom:8px;width:1px;background:linear-gradient(180deg,rgba(234,99,140,.5),rgba(255,255,255,.09) 18%,rgba(255,255,255,.05))"></div>

                    <div class="flex flex-col" style="gap:9px">
                        <template x-for="s in filteredSteps" :key="s.id">
                            <div class="flex" style="gap:14px;animation:rowIn .3s ease both">
                                <div class="flex justify-center" style="flex:0 0 40px;padding-top:12px">
                                    <div class="flex items-center justify-center fz-mono" style="width:32px;height:32px;border-radius:10px;font-size:14px;font-weight:700"
                                         :style="{ background: s.tone.nodeBg, border: '1px solid '+s.tone.nodeBd, color: s.tone.nodeFg, boxShadow: s.tone.nodeGlow }" x-text="s.glyph"></div>
                                </div>
                                <div @click="toggle(s.id)" style="flex:1;min-width:0;border-radius:12px;cursor:pointer;overflow:hidden"
                                     :style="{ border: '1px solid '+s.tone.cardBd, background: s.tone.cardBg }">
                                    <div class="flex items-center" style="gap:12px;padding:12px 14px">
                                        <span class="fz-mono" style="font-size:10.5px;color:#8a9499;flex:0 0 26px" x-text="'#'+s.iteration"></span>
                                        <span class="fz-mono" style="font-size:12px;flex:0 0 auto" :style="{ color: s.tone.toolFg }" x-text="s.tool"></span>
                                        <span style="font-size:12.5px;color:#98a2a7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0" x-text="s.outcome"></span>
                                        <span class="flex items-center" style="margin-left:auto;gap:10px;flex:0 0 auto">
                                            <span class="fz-mono" style="font-size:9.5px;letter-spacing:.07em;padding:2px 8px;border-radius:20px" :style="{ background: s.tone.chipBg, color: s.tone.chipFg, border: '1px solid '+s.tone.chipBd }" x-text="s.status"></span>
                                            <span class="fz-mono" style="font-size:10.5px;color:#8a9499;width:52px;text-align:right" x-text="s.dur"></span>
                                            <span class="fz-mono" style="font-size:10px;color:#4d565b;width:10px" x-text="open[s.id] ? '▴' : '▾'"></span>
                                        </span>
                                    </div>
                                    <template x-if="open[s.id]">
                                        <div @click.stop style="border-top:1px solid rgba(255,255,255,.07);padding:14px;background:rgba(0,0,0,.22)">
                                            <div style="margin-bottom:12px">
                                                <div class="fz-mono" style="font-size:9px;letter-spacing:.13em;color:#8a9499;margin-bottom:5px">REASONING</div>
                                                <div style="font-size:12.5px;line-height:1.65;color:#b6bfc3;max-width:82ch" x-text="s.summary"></div>
                                            </div>
                                            <template x-if="payloads[s.id] === undefined">
                                                <div class="fz-mono" style="font-size:11px;color:#8a9499">loading detail…</div>
                                            </template>
                                            <template x-if="payloads[s.id]">
                                                <div style="display:grid;gap:12px">
                                                    <template x-for="key in Object.keys(payloads[s.id])" :key="key">
                                                        <div>
                                                            <div class="fz-mono" style="font-size:9px;letter-spacing:.13em;color:#8a9499;margin-bottom:5px" x-text="key.toUpperCase()"></div>
                                                            <pre class="fz-mono fz-scroll" style="margin:0;padding:10px 12px;border-radius:9px;background:#0e1214;border:1px solid rgba(255,255,255,.06);font-size:11px;line-height:1.6;color:#9aa8ac;max-height:180px;overflow:auto;white-space:pre-wrap" x-text="pretty(payloads[s.id][key])"></pre>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>
                                            <div class="fz-mono flex flex-wrap" style="gap:18px;font-size:10px;color:#8a9499;border-top:1px solid rgba(255,255,255,.06);padding-top:10px;margin-top:12px">
                                                <span x-text="'iteration ' + s.iteration"></span>
                                                <span x-text="'latency ' + s.dur"></span>
                                                <a :href="'/ui/api/events/' + s.id" target="_blank" style="margin-left:auto">raw event →</a>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div x-show="filteredSteps.length === 0" style="font-size:13px;color:#8a9499;padding:24px 0" x-text="timeline.length ? 'No steps match this filter.' : 'No steps yet…'"></div>
                </div>
            </div>
        </section>

        <aside class="fz-side">

            {{-- WORKERS (supervisor only) --}}
            <template x-if="isSupervisor">
                <div class="flex flex-col" style="gap:11px">
                    <div class="flex items-center" style="gap:9px">
                        <span class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499" x-text="'WORKERS · ' + workers.length"></span>
                        <span class="fz-mono" style="margin-left:auto;font-size:9.5px;color:#96a0a5" x-text="workerActiveLabel"></span>
                    </div>
                    <template x-for="w in workers" :key="w.id">
                        <a :href="'/jobs/' + w.id" style="display:block;border-radius:13px;padding:13px;color:#eef1f2;text-decoration:none"
                           :style="{ border: '1px solid '+w.cardBd, background: w.cardBg }" onmouseover="this.style.borderColor='rgba(234,99,140,.5)'">
                            <div class="flex items-center" style="gap:8px">
                                <div style="width:6px;height:6px;border-radius:50%" :style="{ background: w.tone.chipFg, animation: w.tone.anim }"></div>
                                <span class="fz-mono" style="font-size:11.5px;color:#dbe2e4" x-text="'job_' + String(w.id).slice(-8)"></span>
                                <span class="fz-mono" style="margin-left:auto;font-size:8.5px;letter-spacing:.06em;padding:2px 7px;border-radius:20px" :style="{ background: w.tone.chipBg, color: w.tone.chipFg, border: '1px solid '+w.tone.chipBd }" x-text="w.statusLabel"></span>
                            </div>
                            <template x-if="w.task_seq">
                                <div class="fz-mono" style="font-size:9px;letter-spacing:.11em;color:#8a9499;margin-top:11px" x-text="'TASK #' + w.task_seq"></div>
                            </template>
                            <div style="font-size:12.5px;line-height:1.5;color:#c8d0d3;margin-top:3px" x-text="w.task_title"></div>
                            <template x-if="w.activity">
                                <div class="fz-mono flex items-center" style="gap:9px;margin-top:11px;padding:7px 9px;border-radius:8px;background:rgba(0,0,0,.32);border:1px solid rgba(255,255,255,.06);font-size:10.5px">
                                    <span style="color:#8a9499;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" x-text="w.activity"></span>
                                </div>
                            </template>
                            <div class="fz-mono flex items-center" style="gap:10px;margin-top:10px;font-size:10px;color:#8a9499">
                                <span x-text="'iter ' + w.iteration + '/' + w.max_iterations"></span>
                                <span style="color:#394246">│</span>
                                <span>conf <span :style="{ color: w.confFg }" x-text="w.confidence != null ? Number(w.confidence).toFixed(2) : '—'"></span></span>
                                <span style="margin-left:auto;color:#ea638c">open →</span>
                            </div>
                            <div style="height:3px;border-radius:2px;background:rgba(255,255,255,.07);overflow:hidden;margin-top:9px"><div style="height:100%;border-radius:2px" :style="{ width: w.iterW, background: w.tone.chipFg }"></div></div>
                        </a>
                    </template>
                    <div x-show="!workers.length" style="font-size:11.5px;color:#8a9499">No workers spawned yet.</div>
                </div>
            </template>

            {{-- Needs You (solo / worker) --}}
            <template x-for="q in openQuestions" :key="q.id">
                <div style="border-radius:13px;border:1px solid rgba(242,182,97,.28);background:linear-gradient(180deg,rgba(242,182,97,.09),rgba(27,32,33,.6));padding:14px">
                    <div class="flex items-center" style="gap:7px;margin-bottom:9px">
                        <div style="width:6px;height:6px;border-radius:50%;background:#f2b661;animation:breathe 2s ease-in-out infinite"></div>
                        <span class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#f2b661" x-text="'NEEDS YOU · ' + openQuestions.length"></span>
                    </div>
                    <div style="font-size:12.5px;line-height:1.6;color:#d8dfe1" x-text="q.question"></div>
                    <div style="margin-top:11px">
                        <textarea x-model="answers[q.id]" rows="2" placeholder="Answer as a human…"
                                  style="width:100%;border-radius:7px;background:#101416;border:1px solid rgba(255,255,255,.12);padding:7px 9px;font-size:11.5px;color:#e4e9ea;outline:none"></textarea>
                        <button @click="answer(q.id)" style="margin-top:7px;width:100%;font-size:11.5px;padding:7px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:#e4e9ea;cursor:pointer"
                                onmouseover="this.style.background='rgba(255,255,255,.1)'" onmouseout="this.style.background='rgba(255,255,255,.05)'">Submit answer</button>
                    </div>
                </div>
            </template>

            <template x-if="answeredQuestions.length">
                <div style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#1a1f21;padding:14px">
                    <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499;margin-bottom:11px">RESOLVED · <span x-text="answeredQuestions.length"></span></div>
                    <div class="flex flex-col" style="gap:9px">
                        <template x-for="q in answeredQuestions" :key="q.id">
                            <div>
                                <div style="font-size:12px;line-height:1.5;color:#98a2a7" x-text="q.question"></div>
                                <div style="font-size:11.5px;color:#5fdda5;margin-top:3px" x-text="'✔ ' + (q.answer || (q.source || 'answered'))"></div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Tools used --}}
            <div style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#1a1f21;padding:14px">
                <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499;margin-bottom:11px">TOOLS USED</div>
                <div class="flex flex-col" style="gap:7px">
                    <template x-for="t in (stats.tool_usage || [])" :key="t.tool">
                        <div class="flex items-center" style="gap:9px;padding:7px 9px;border-radius:8px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.05)">
                            <span class="fz-mono" style="font-size:9px;padding:2px 5px;border-radius:4px;background:rgba(234,99,140,.16);color:#ea638c" x-text="t.calls + '×'"></span>
                            <span class="fz-mono" style="font-size:11px;color:#b6bfc3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" x-text="t.tool"></span>
                            <span x-show="t.failed" class="fz-mono" style="margin-left:auto;font-size:9.5px;color:#f2b661" x-text="t.failed + ' failed'"></span>
                        </div>
                    </template>
                    <div x-show="!(stats.tool_usage || []).length" style="font-size:11.5px;color:#8a9499">No tools called yet.</div>
                </div>
            </div>

            {{-- Run metadata --}}
            <div class="fz-mono flex flex-col" style="border-radius:13px;border:1px solid rgba(255,255,255,.07);background:#1a1f21;padding:14px;gap:9px;font-size:10.5px">
                <div style="letter-spacing:.13em;color:#8a9499;font-size:9.5px;margin-bottom:2px">RUN METADATA</div>
                <div class="flex justify-between" style="gap:10px"><span style="color:#8a9499">job</span><span style="color:#a3adb1">job_{{ \Illuminate\Support\Str::substr($jobId, -8) }}</span></div>
                <div class="flex justify-between" style="gap:10px"><span style="color:#8a9499">role</span><span style="color:#ffb3c4" x-text="role"></span></div>
                <div class="flex justify-between" style="gap:10px"><span style="color:#8a9499">started</span><span style="color:#a3adb1" x-text="job.started_at || '—'"></span></div>
                <div class="flex justify-between" style="gap:10px"><span style="color:#8a9499">finished</span><span style="color:#a3adb1" x-text="job.finished_at || '—'"></span></div>
                <div class="flex justify-between" style="gap:10px"><span style="color:#8a9499">model</span><span style="color:#a3adb1;text-align:right">{{ $llmModel }}</span></div>
                <div class="flex justify-between" style="gap:10px"><span style="color:#8a9499">temp</span><span style="color:#a3adb1">{{ $llmTemp }}</span></div>
                <template x-if="job.last_error">
                    <div class="flex justify-between" style="gap:10px"><span style="color:#8a9499">error</span><span style="color:#ffb3c4;text-align:right" x-text="job.last_error"></span></div>
                </template>
            </div>
        </aside>
    </div>
</div>

@push('scripts')
<script>
function jobHeaderActions() {
    return {
        status: @json($initial['job']['status']),
        init() { window.addEventListener('job-status', e => { this.status = e.detail; }); },
        async stop() {
            if (!confirm('Stop this research job?')) return;
            await window.postJson('/jobs/{{ $jobId }}/cancel', {});
            window.dispatchEvent(new CustomEvent('job-refresh'));
        },
        async rerun() {
            if (!confirm('Re-run this research from its existing findings?')) return;
            const r = await window.postJson('/jobs/{{ $jobId }}/retry', {});
            if (r && r.error) { alert(r.error); return; }
            window.dispatchEvent(new CustomEvent('job-refresh'));
        },
        async del() {
            if (this.status === 'running') { alert('Stop the research first, then delete.'); return; }
            if (!confirm('Delete this research and all its history? This cannot be undone.')) return;
            const r = await window.deleteJson('/jobs/{{ $jobId }}');
            if (r && r.error) { alert(r.error); return; }
            window.location = '{{ route('dashboard') }}';
        },
    };
}

function jobDetail() {
    const TONES = {
        live: { nodeBg:'linear-gradient(145deg,#ea638c,#89023e)', nodeBd:'rgba(255,217,218,.45)', nodeFg:'#1b2021', nodeGlow:'0 0 22px rgba(234,99,140,.5)',
                cardBg:'linear-gradient(90deg,rgba(234,99,140,.12),rgba(27,32,33,.9))', cardBd:'rgba(234,99,140,.35)', toolFg:'#ffd9da',
                chipBg:'rgba(234,99,140,.18)', chipFg:'#ffb3c4', chipBd:'rgba(234,99,140,.45)', anim:'breathe 1.6s ease-in-out infinite' },
        think:{ nodeBg:'rgba(234,99,140,.12)', nodeBd:'rgba(234,99,140,.3)', nodeFg:'#ea638c', nodeGlow:'none',
                cardBg:'#1a1f21', cardBd:'rgba(255,255,255,.08)', toolFg:'#ea638c',
                chipBg:'rgba(255,255,255,.05)', chipFg:'#9aa8ac', chipBd:'rgba(255,255,255,.1)', anim:'none' },
        ok:   { nodeBg:'#20262a', nodeBd:'rgba(255,255,255,.1)', nodeFg:'#8fa0a6', nodeGlow:'none',
                cardBg:'#1a1f21', cardBd:'rgba(255,255,255,.07)', toolFg:'#c8d0d3',
                chipBg:'rgba(62,207,142,.13)', chipFg:'#5fdda5', chipBd:'rgba(62,207,142,.28)', anim:'none' },
        warn: { nodeBg:'rgba(242,182,97,.14)', nodeBd:'rgba(242,182,97,.35)', nodeFg:'#f2b661', nodeGlow:'none',
                cardBg:'linear-gradient(90deg,rgba(242,182,97,.07),#1a1f21)', cardBd:'rgba(242,182,97,.26)', toolFg:'#f2d3a0',
                chipBg:'rgba(242,182,97,.15)', chipFg:'#f5c987', chipBd:'rgba(242,182,97,.35)', anim:'breathe 2s ease-in-out infinite' },
        err:  { nodeBg:'rgba(137,2,62,.28)', nodeBd:'rgba(255,179,196,.4)', nodeFg:'#ffb3c4', nodeGlow:'none',
                cardBg:'linear-gradient(90deg,rgba(137,2,62,.16),#1a1f21)', cardBd:'rgba(137,2,62,.45)', toolFg:'#ffb3c4',
                chipBg:'rgba(137,2,62,.28)', chipFg:'#ffb3c4', chipBd:'rgba(137,2,62,.5)', anim:'none' },
        input:{ nodeBg:'linear-gradient(145deg,rgba(234,99,140,.22),rgba(137,2,62,.3))', nodeBd:'rgba(255,217,218,.4)', nodeFg:'#ffd9da', nodeGlow:'0 0 16px rgba(234,99,140,.28)',
                cardBg:'linear-gradient(90deg,rgba(234,99,140,.1),#1a1f21)', cardBd:'rgba(234,99,140,.3)', toolFg:'#ffb3c4',
                chipBg:'rgba(234,99,140,.16)', chipFg:'#ffd9da', chipBd:'rgba(234,99,140,.4)', anim:'none' },
    };
    const STATUS = { live:'RUNNING', err:'ERROR', warn:'WAIT', think:'THINK', ok:'OK', input:'PROMPT' };
    // task status → tone + label
    const TASK_TONE = {
        pending:         { tone: Object.assign({}, TONES.think, { nodeBg:'#20262a', nodeBd:'rgba(255,255,255,.1)', nodeFg:'#8a9499', chipBg:'rgba(255,255,255,.05)', chipFg:'#9aa8ac', chipBd:'rgba(255,255,255,.11)', titleFg:'#98a2a7', row:'transparent' }), label:'PENDING' },
        in_progress:     { tone: Object.assign({}, TONES.live, { titleFg:'#ffd9da', row:'rgba(234,99,140,.07)' }), label:'IN PROGRESS' },
        awaiting_review: { tone: Object.assign({}, TONES.warn, { titleFg:'#f2d3a0', row:'rgba(242,182,97,.04)' }), label:'AWAITING REVIEW' },
        done:            { tone: Object.assign({}, TONES.ok,   { nodeFg:'#5fdda5', titleFg:'#c8d0d3', row:'transparent' }), label:'DONE' },
        failed:          { tone: Object.assign({}, TONES.err,  { titleFg:'#ffb0b0', row:'rgba(255,107,107,.04)' }), label:'FAILED' },
    };
    const taskTone = s => TASK_TONE[s] || TASK_TONE.pending;

    return {
        job: @json($initial['job']),
        stats: @json($initial['stats']),
        timeline: @json($initial['timeline']),
        questions: @json($questions),
        activity: @json($initial['activity']),
        extras: @json($initial['role_extras']),
        answers: {},
        payloads: {},
        open: {},
        taskOpen: {},
        live: true,
        detailed: false,
        filter: 'ALL',
        guidance: '',
        continuing: false,
        reportOpen: false,
        confDelta: null,
        _prevConf: null,
        now: Date.now(),

        start() {
            clearInterval(this.timer); clearInterval(this.clock);
            this.live = !['completed','failed','cancelled'].includes(this.job.status);
            this._prevConf = this.job.confidence;
            if (this.live) this.timer = setInterval(() => this.refresh(), 3000);
            this.clock = setInterval(() => { this.now = Date.now(); }, 1000);
            window.addEventListener('job-refresh', () => this.refresh());
            this._openNewest();
            // open the in-progress task by default
            (this.extras.tasks || []).forEach(t => { if (t.status === 'in_progress') this.taskOpen[t.seq] = true; });
        },
        _openNewest() {
            const s = this.simpleSteps;
            if (s.length && this.live) this.open[s[0].id] = true;
        },

        // ── role ────────────────────────────────────────────────────────────
        get role() { return this.extras.role || 'solo'; },
        get maxIter() { return this.extras.max_iterations || {{ $maxIter }}; },
        get isSupervisor() { return this.role === 'supervisor'; },
        get isWorker() { return this.role === 'worker'; },
        get isDone() { return this.job.status === 'completed'; },

        // ── hero ────────────────────────────────────────────────────────────
        get hero() {
            if (this.isDone) return {
                bd:'rgba(62,207,142,.22)', accent:'#3ecf8e', titleFg:'#8ff0c4',
                bg:'radial-gradient(1100px 320px at 12% -40%,rgba(62,207,142,.14),transparent 62%),linear-gradient(180deg,#20272a,#1b2021)',
                shadow:'0 24px 60px -34px rgba(62,207,142,.45),inset 0 1px 0 rgba(255,255,255,.04)',
            };
            return {
                bd:'rgba(234,99,140,.24)', accent:'#ea638c', titleFg:'#ffd9da',
                bg:'radial-gradient(1100px 320px at 12% -40%,rgba(234,99,140,.20),transparent 62%),linear-gradient(180deg,#23282c,#1b2021)',
                shadow:'0 24px 60px -30px rgba(234,99,140,.55),inset 0 1px 0 rgba(255,255,255,.04)',
            };
        },
        get heroStatus() {
            if (this.isDone) return 'COMPLETED · ' + (this.job.finished_at || '');
            if (this.isSupervisor) return 'SUPERVISING · ITERATION ' + (this.job.iteration ?? 0);
            return (this.job.status || 'idle').toUpperCase() + ' · ITERATION ' + (this.job.iteration ?? 0);
        },
        get heroSub() {
            if (this.isDone) return 'answer delivered';
            if (this.isSupervisor) {
                const c = this.taskCounts;
                return c.done + ' of ' + c.total + ' tasks done · ' + this.workers.length + ' workers';
            }
            return this.activity.active ? 'executing' : 'idle';
        },
        get headline() {
            if (this.isDone) return 'answer delivered';
            const a = this.activity || {};
            if (a.phase === 'running_tool') return (a.label || '').replace(/^Running tool:\s*/, '');
            if (a.phase === 'thinking') return 'reasoning';
            if (this.isSupervisor) return 'supervising';
            return (a.label || 'working').toLowerCase().replace(/…$/, '');
        },
        get opLabel() {
            if (this.isDone) return 'CLOSED';
            if (this.isSupervisor) return 'SUPERVISOR';
            const p = this.activity.phase;
            if (p === 'running_tool') return 'RUNNING';
            if (['queued','waiting'].includes(p)) return 'WAITING';
            return 'ACTIVE';
        },
        get opFg() { return this.isDone ? '#5fdda5' : (this.activity.waiting_for ? '#f2b661' : '#ea638c'); },

        get ring() {
            if (this.isSupervisor) {
                const c = this.taskCounts;
                const frac = c.total ? c.done / c.total : 0;
                return { cap:'TASKS', main: String(c.done), sub:'/ ' + c.total + ' done', deg: Math.round(frac*360), color:'#3ecf8e', fg:'#8ff0c4' };
            }
            if (this.isDone) {
                return { cap:'DONE', main: String(this.job.iteration ?? 0), sub:'/ ' + (this.job.iteration ?? 0) + ' iter', deg: 360, color:'#3ecf8e', fg:'#8ff0c4' };
            }
            const frac = Math.min(1, (this.job.iteration || 0) / this.maxIter);
            return { cap:'ITER', main: String(this.job.iteration ?? 0), sub:'/ ' + this.maxIter, deg: Math.round(frac*360), color:'#ea638c', fg:'#ffd9da' };
        },

        get taskCounts() {
            const c = this.extras.task_counts;
            if (c) return c;
            const t = this.extras.tasks || [];
            return { total: t.length, done: t.filter(x => x.status==='done').length, failed: t.filter(x => x.status==='failed').length };
        },
        get vitals() {
            if (this.isSupervisor) {
                const c = this.taskCounts;
                return [
                    { k:'TASKS',   n: c.total, c:'#e4e9ea' },
                    { k:'WORKERS', n: this.workers.length, c:'#e4e9ea' },
                    { k:'DONE',    n: c.done, c:'#3ecf8e' },
                    { k:'FAILED',  n: c.failed, c: (c.failed ? '#ff6b6b' : '#e4e9ea') },
                ];
            }
            return [
                { k:'TOOL CALLS', n: this.job.tool_calls ?? 0, c:'#e4e9ea' },
                { k:'EVENTS',     n: this.stats.total_events ?? this.timeline.length, c:'#e4e9ea' },
                { k:'QUESTIONS',  n: this.stats.open_questions ?? 0, c: (this.stats.open_questions ? '#f2b661' : '#e4e9ea') },
                { k:'ERRORS',     n: this.errorCount, c: (this.errorCount ? '#f2b661' : '#e4e9ea') },
            ];
        },

        // ── supervisor: tasks + workers ─────────────────────────────────────
        get planSummary() {
            const c = this.taskCounts;
            const bits = [c.done + ' of ' + c.total + ' done'];
            if (c.in_progress) bits.push(c.in_progress + ' in progress');
            if (c.awaiting_review) bits.push(c.awaiting_review + ' awaiting review');
            if (c.failed) bits.push(c.failed + ' failed');
            return bits.join(' · ');
        },
        get tasks() {
            return (this.extras.tasks || []).map(t => {
                const tt = taskTone(t.status);
                return { ...t, tone: tt.tone, statusLabel: tt.label,
                    resultLabel: t.status === 'failed' ? 'WORKER FAILURE' : t.status === 'in_progress' ? 'WORKER PROGRESS' : t.status === 'pending' ? 'STATE' : 'WORKER RESULT' };
            });
        },
        toggleTask(seq) { this.taskOpen[seq] = !this.taskOpen[seq]; },
        get workers() {
            return (this.extras.workers || []).map(w => {
                const tt = taskTone(w.status === 'running' ? 'in_progress' : w.status === 'completed' ? 'done' : w.status);
                const frac = w.max_iterations ? Math.min(1, w.iteration / w.max_iterations) : 0;
                const inProg = ['running','in_progress'].includes(w.status);
                return { ...w, tone: tt.tone, statusLabel: (w.status || '').toUpperCase(),
                    iterW: Math.round(frac*100) + '%',
                    confFg: w.confidence == null ? '#8a9499' : w.confidence >= 0.8 ? '#3ecf8e' : w.confidence >= 0.5 ? '#f2b661' : '#ff6b6b',
                    cardBg: inProg ? 'linear-gradient(180deg,rgba(234,99,140,.10),rgba(26,31,33,.9))' : '#1a1f21',
                    cardBd: inProg ? 'rgba(234,99,140,.34)' : w.status === 'failed' ? 'rgba(255,107,107,.24)' : 'rgba(255,255,255,.07)' };
            });
        },
        get workerActiveLabel() {
            const w = this.workers;
            const running = w.filter(x => ['running','in_progress'].includes(x.status)).length;
            const review = (this.extras.tasks || []).filter(t => t.status === 'awaiting_review').length;
            return running + ' running' + (review ? ' · ' + review + ' awaiting review' : '');
        },

        // ── header helpers ──────────────────────────────────────────────────
        get flowNote() {
            if (this.isSupervisor) return 'supervisor reasoning · ' + (this.job.iteration ?? 0) + ' iterations';
            return (this.job.iteration ?? 0) + ' iterations';
        },
        get elapsed() {
            const start = this._epoch(this.job.started_at);
            if (!start) return '—';
            const end = ['completed','failed','cancelled'].includes(this.job.status)
                ? (this._epoch(this.job.finished_at) || start) : this.now;
            let s = Math.max(0, Math.floor((end - start) / 1000));
            const h = Math.floor(s / 3600); s -= h * 3600;
            const m = Math.floor(s / 60); s -= m * 60;
            return (h ? h + 'h ' : '') + m + 'm ' + String(s).padStart(2, '0') + 's';
        },
        _epoch(dt) { if (!dt) return null; const t = Date.parse(dt.replace(' ', 'T') + 'Z'); return isNaN(t) ? null : t; },

        get errorCount() {
            return this.timeline.filter(e => e.level === 'error' || ['failed','tool_failed','invalid_llm_response'].includes(e.type)).length;
        },

        // ── questions ───────────────────────────────────────────────────────
        get openQuestions() { return this.questions.filter(q => ['queued','asked'].includes(q.status)); },
        get answeredQuestions() { return this.questions.filter(q => !['queued','asked'].includes(q.status)); },

        // ── timeline (summary + detailed) ───────────────────────────────────
        iconFor(tool) {
            if (/search/.test(tool)) return '🔍';
            if (/read|webpage|fetch/.test(tool)) return '📄';
            if (/doc|parse|pdf/.test(tool)) return '📄';
            if (/memory|store/.test(tool)) return '💾';
            if (/shell|exec|pip/.test(tool)) return '⚙️';
            if (/human/.test(tool)) return '🙋';
            if (/plan|delegate|review|accept/.test(tool)) return '🧭';
            return '🔧';
        },
        get simpleSteps() {
            const after = (s, re) => { const m = (s || '').match(re); return m ? m[1].trim() : ''; };
            const isLive = !['completed','failed','cancelled'].includes(this.job.status);
            const lastSeq = this.timeline.length ? Math.max(...this.timeline.map(e => e.seq || 0)) : 0;

            // The prompts the HUMAN gave — the starting goal (job_started) and every
            // follow-up guidance (resumed) — are shown as their OWN nodes so you can
            // see exactly what was asked, and when. They're pulled out of the normal
            // iteration grouping below.
            const inputs = this.timeline
                .filter(e => e.type === 'job_started' || e.type === 'resumed')
                .map(e => {
                    const isStart = e.type === 'job_started';
                    const text = isStart
                        ? (after(e.summary, /goal:\s*([\s\S]*)$/i) || this.job.goal || e.summary)
                        : (after(e.summary, /guidance:\s*([\s\S]*)$/i) || e.summary);
                    return { id: e.id, iteration: e.iteration, glyph: '📝',
                             tool: isStart ? 'prompt' : 'new guidance', outcome: text,
                             summary: text, status: STATUS['input'], dur: '', tone: TONES['input'],
                             _key: 'input', isTool: false, seq: e.seq || 0 };
                });

            // One collapsed step per iteration (the agent's action), excluding the
            // prompt events which are their own nodes above.
            const groups = {};
            for (const e of this.timeline) {
                if (e.type === 'job_started' || e.type === 'resumed') continue;
                (groups[e.iteration] ||= []).push(e);
            }
            const iterSteps = Object.keys(groups).map(Number).map(iter => {
                const evs = groups[iter];
                const by = t => evs.find(e => e.type === t);
                let key = 'ok', glyph = '•', tool = 'step', outcome = '', isTool = false, primary = evs[evs.length - 1];
                const finished = by('finished'), toolSel = by('tool_selected'), obs = by('observation'),
                      asked = by('human_question_asked'), queued = by('human_question_queued'),
                      answered = by('human_answer_received'), guard = by('guardrail_triggered'),
                      invalid = by('invalid_llm_response'), failed = by('tool_failed') || by('failed'),
                      thought = by('thought');
                if (finished) { key='ok'; glyph='✅'; tool='finished'; outcome = after(finished.summary, /\((.*)\)/) || 'answer ready'; primary=finished; }
                else if (toolSel) {
                    isTool = true;
                    const t = after(toolSel.summary, /Chose\s+([a-z_.]+)/i) || 'tool';
                    glyph = this.iconFor(t); tool = t; primary = by('tool_succeeded') || obs || failed || toolSel;
                    if (failed) { key='err'; outcome = failed.summary.slice(0,120); }
                    else { key='ok'; outcome = (obs ? (after(obs.summary, /Observation from [a-z_.]+:\s*(.*)/i) || obs.summary) : after(toolSel.summary, /Chose\s+[a-z_.]+:\s*(.*)/i) || toolSel.summary).slice(0,130); }
                }
                else if (asked) { key='warn'; glyph='🙋'; tool='ask human'; outcome = after(asked.summary, /:\s*"?(.*?)"?$/) || asked.summary; primary=asked; }
                else if (queued) { key='warn'; glyph='📥'; tool='queued'; outcome = after(queued.summary, /:\s*"?(.*?)"?$/) || queued.summary; primary=queued; }
                else if (answered) { key='ok'; glyph='💬'; tool='human answered'; outcome = answered.summary.slice(0,120); primary=answered; }
                else if (guard) { key='warn'; glyph='🛑'; tool='guardrail'; outcome = guard.summary.slice(0,120); primary=guard; }
                else if (invalid) { key='warn'; glyph='⚠'; tool='invalid reply'; outcome = invalid.summary.slice(0,120); primary=invalid; }
                else if (thought) { key='think'; glyph='🧠'; tool='thinking'; outcome = thought.summary.slice(0,130); primary=thought; }
                const seq = Math.max(...evs.map(e => e.seq || 0));
                if (isLive && seq === lastSeq && key !== 'err') key = 'live';
                return { id: primary.id, iteration: iter, glyph, tool, outcome, summary: outcome || primary.summary || '',
                         status: STATUS[key], dur: this.fmtDur(primary.duration_ms), tone: TONES[key], _key: key, isTool, seq };
            });

            // Merge and show newest-first (by event order).
            return [...inputs, ...iterSteps].sort((a, b) => (b.seq || 0) - (a.seq || 0));
        },
        toolLabel(e) {
            const m = (e.summary || '').match(/(?:Chose|Running|Observation from|tool)\s+([a-z_.]+)/i);
            if (m) return m[1];
            return (e.type || '').replace(/_/g, '.');
        },
        eventToneKey(e) {
            if (e.level === 'error' || ['failed','tool_failed','invalid_llm_response'].includes(e.type)) return 'err';
            if (this.live && this.timeline.length && e.id === this.timeline[this.timeline.length - 1].id
                && !['completed','failed','cancelled'].includes(this.job.status)) return 'live';
            if (e.level === 'warning' || ['guardrail_triggered','human_question_asked','human_question_queued'].includes(e.type)) return 'warn';
            if (['thought','reflect'].includes(e.type)) return 'think';
            return 'ok';
        },
        get eventSteps() {
            return this.timeline.slice().reverse().map(e => {
                const key = this.eventToneKey(e);
                return { id: e.id, iteration: e.iteration, glyph: e.glyph || '•', tool: this.toolLabel(e),
                         outcome: e.summary || '', summary: e.summary || '', status: STATUS[key],
                         dur: this.fmtDur(e.duration_ms), tone: TONES[key], _key: key,
                         isTool: /^tool_/.test(e.type) || e.type === 'observation' };
            });
        },
        get filteredSteps() {
            const base = this.detailed ? this.eventSteps : this.simpleSteps;
            const f = this.filter;
            return base.filter(s =>
                f === 'ALL' ? true : f === 'TOOLS' ? s.isTool : f === 'THINKING' ? s._key === 'think' : s._key === 'err');
        },
        fmtDur(ms) { if (ms == null) return '—'; return ms >= 1000 ? (ms / 1000).toFixed(1) + 's' : Math.round(ms) + 'ms'; },

        async toggle(id) {
            this.open[id] = !this.open[id];
            if (this.open[id] && this.payloads[id] === undefined) {
                try { const d = await (await fetch('/ui/api/events/' + id)).json(); this.payloads[id] = (d && d.payload) ? d.payload : null; }
                catch (e) { this.payloads[id] = null; }
            }
        },
        pretty(v) { return (typeof v === 'string') ? v : JSON.stringify(v, null, 2); },

        async refresh() {
            try {
                const d = await (await fetch('{{ route('ui.job', $jobId) }}')).json();
                if (d.job && d.job.confidence != null && this._prevConf != null && d.job.confidence !== this._prevConf) {
                    this.confDelta = d.job.confidence - this._prevConf;
                }
                if (d.job) this._prevConf = d.job.confidence;
                this.job = d.job; this.stats = d.stats; this.timeline = d.timeline;
                this.questions = d.questions; this.activity = d.activity;
                if (d.role_extras) this.extras = d.role_extras;
                this.live = !['completed','failed','cancelled'].includes(d.job.status);
                window.dispatchEvent(new CustomEvent('job-status', { detail: d.job.status }));
                if (!this.live) clearInterval(this.timer);
            } catch (e) {}
        },
        async continueJob() {
            if (!this.guidance.trim() || this.continuing) return;
            this.continuing = true;
            try {
                const r = await window.postJson('/jobs/{{ $jobId }}/continue', { guidance: this.guidance });
                if (r && r.error) { alert(r.error); return; }
                this.guidance = ''; this.live = true; this.start(); this.refresh();
            } finally { this.continuing = false; }
        },
        async rerun() {
            const r = await window.postJson('/jobs/{{ $jobId }}/retry', {});
            if (r && r.error) { alert(r.error); return; }
            this.live = true; this.start(); this.refresh();
        },
        async answer(id) {
            const text = (this.answers[id] || '').trim();
            if (!text) return;
            await window.postJson('/questions/' + id + '/answer', { answer: text });
            this.answers[id] = '';
            this.refresh();
        },
    };
}
</script>
@endpush
@endsection
