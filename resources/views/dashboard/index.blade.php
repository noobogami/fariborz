@extends('dashboard.layout')
@section('title', 'Research Jobs')

@php
    $cActive = $jobs->filter(fn ($j) => in_array($j['status'], ['running', 'waiting', 'pending']))->count();
    $cDone   = $jobs->filter(fn ($j) => $j['status'] === 'completed')->count();
    $cFailed = $jobs->filter(fn ($j) => in_array($j['status'], ['failed', 'cancelled']))->count();
    $cSup    = $jobs->filter(fn ($j) => ($j['role'] ?? 'solo') === 'supervisor')->count();
@endphp

@section('header')
<div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;width:100%">
    <div>
        <h1 style="margin:0;font-size:19px;font-weight:600;letter-spacing:-.012em;color:#f2f5f6">Research Jobs</h1>
        <div class="fz-mono" style="font-size:10.5px;color:#8a9499;margin-top:4px">{{ $cActive }} active · {{ $cSup }} supervised · {{ $cDone }} completed · {{ $cFailed }} failed</div>
    </div>
</div>
@endsection

@section('content')
<style>
    @keyframes orbPulse{0%,100%{box-shadow:0 0 0 0 rgba(234,99,140,.5)}50%{box-shadow:0 0 0 7px rgba(234,99,140,0)}}
    @keyframes breathe{0%,100%{opacity:.55}50%{opacity:1}}
    @keyframes rowIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
    .jl-grid{display:grid;grid-template-columns:minmax(0,1fr) 108px 62px 62px 104px 116px 108px;gap:14px}
</style>

<div x-data="jobsList()" x-init="start()">

    {{-- START NEW RESEARCH --}}
    <section style="position:relative;overflow:hidden;border-radius:16px;border:1px solid rgba(234,99,140,.22);background:radial-gradient(900px 260px at 8% -50%,rgba(234,99,140,.16),transparent 60%),linear-gradient(180deg,#23282c,#1b2021);padding:20px 22px;box-shadow:0 20px 50px -32px rgba(234,99,140,.5)">
        <form method="POST" action="{{ route('jobs.store') }}">
            @csrf
            <input type="hidden" name="supervised" :value="mode === 'SUPERVISED' ? '1' : '0'">
            <div class="flex items-center" style="gap:9px;margin-bottom:13px">
                <div style="width:7px;height:7px;border-radius:50%;background:#ea638c;animation:orbPulse 2.2s ease-out infinite"></div>
                <span class="fz-mono" style="font-size:10.5px;letter-spacing:.14em;color:#ea638c">START NEW RESEARCH</span>
            </div>
            <textarea name="goal" rows="3" required minlength="5"
                      placeholder="e.g. Determine whether Acme Robotics is a viable tier-1 supplier — audit financials, delivery record and compliance history."
                      style="width:100%;resize:vertical;padding:13px 15px;border-radius:11px;background:rgba(0,0,0,.34);border:1px solid rgba(255,255,255,.09);color:#eef1f2;font-size:13.5px;line-height:1.6"></textarea>
            <div class="flex flex-wrap items-center" style="gap:14px;margin-top:13px">
                <div class="flex items-center" style="gap:8px">
                    <span class="fz-mono" style="font-size:10px;letter-spacing:.1em;color:#8a9499">MODE</span>
                    <div class="flex" style="gap:4px;padding:3px;border-radius:9px;background:rgba(0,0,0,.34);border:1px solid rgba(255,255,255,.09)">
                        <template x-for="m in ['SOLO','SUPERVISED']" :key="m">
                            <button type="button" @click="mode = m" class="fz-mono"
                                    :style="{ fontSize:'11px', letterSpacing:'.04em', padding:'5px 11px', borderRadius:'7px', cursor:'pointer', border:'1px solid '+(mode===m?'rgba(234,99,140,.4)':'transparent'), background:(mode===m?'rgba(234,99,140,.18)':'transparent'), color:(mode===m?'#ffd9da':'#96a0a5') }"
                                    x-text="m"></button>
                        </template>
                    </div>
                </div>
                <div class="flex items-center" style="gap:8px" x-show="mode === 'SUPERVISED'" x-cloak>
                    <span class="fz-mono" style="font-size:9.5px;color:#8a9499">breaks the goal into tasks · one worker per task</span>
                </div>
                <div class="flex items-center" style="gap:8px">
                    <span class="fz-mono" style="font-size:10px;letter-spacing:.1em;color:#8a9499">MAX ITER</span>
                    <input type="number" name="max_iterations" min="1" max="500" placeholder="{{ config('research.limits.max_iterations') }}"
                           style="width:66px;padding:7px 10px;border-radius:8px;background:rgba(0,0,0,.34);border:1px solid rgba(255,255,255,.09);color:#e4e9ea;font-size:12px" class="fz-mono">
                </div>
                <div class="flex items-center" style="gap:8px">
                    <span class="fz-mono" style="font-size:10px;letter-spacing:.1em;color:#8a9499">MODEL</span>
                    <div class="fz-mono" style="padding:7px 11px;border-radius:8px;background:rgba(0,0,0,.34);border:1px solid rgba(255,255,255,.09);color:#e4e9ea;font-size:12px">{{ config('research.llm.model') }}</div>
                </div>
                <button type="submit" style="margin-left:auto;font-size:13px;font-weight:600;padding:10px 22px;border-radius:10px;border:1px solid rgba(255,217,218,.35);background:linear-gradient(145deg,#ea638c,#89023e);color:#fff;cursor:pointer;box-shadow:0 10px 26px -12px rgba(234,99,140,.9)"
                        onmouseover="this.style.filter='brightness(1.1)'" onmouseout="this.style.filter='none'">Start research →</button>
            </div>
        </form>
    </section>

    {{-- All jobs --}}
    <div class="flex flex-wrap items-center" style="gap:12px;margin:24px 0 13px">
        <h2 style="margin:0;font-size:13px;font-weight:600;color:#e4e9ea">All jobs</h2>
        <span class="fz-mono" style="font-size:10.5px;color:#8a9499" x-text="jobs.length + ' total'"></span>
        <div class="flex flex-wrap items-center" style="margin-left:auto;gap:6px">
            <input x-model="q" placeholder="filter goals…" class="fz-mono"
                   style="width:180px;padding:6px 11px;border-radius:8px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);color:#e4e9ea;font-size:11.5px">
            <template x-for="t in ['ALL','RUNNING','WAITING','COMPLETED','FAILED']" :key="t">
                <button @click="tab = t" class="fz-mono"
                        :style="{ fontSize:'10px', letterSpacing:'.08em', padding:'5px 11px', borderRadius:'20px', cursor:'pointer', border:'1px solid '+(tab===t?'rgba(234,99,140,.4)':'rgba(255,255,255,.08)'), background:(tab===t?'rgba(234,99,140,.16)':'rgba(255,255,255,.03)'), color:(tab===t?'#ffd9da':'#96a0a5') }"
                        x-text="t"></button>
            </template>
        </div>
    </div>

    <div style="border:1px solid rgba(255,255,255,.07);border-radius:13px;overflow:hidden;background:#1a1f21">
        <div style="overflow-x:auto">
            <div style="min-width:940px">
                <div class="jl-grid fz-mono" style="padding:10px 16px;background:rgba(255,255,255,.025);border-bottom:1px solid rgba(255,255,255,.07);font-size:9.5px;letter-spacing:.12em;color:#8a9499">
                    <div>GOAL</div><div>STATUS</div><div style="text-align:right">ITER</div><div style="text-align:right">TOOLS</div><div>CONFIDENCE</div><div>STARTED</div><div style="text-align:right">ACTIONS</div>
                </div>
                <template x-for="j in filtered" :key="j.id">
                    <div style="border-bottom:1px solid rgba(255,255,255,.05)">
                        {{-- main row --}}
                        <div class="jl-grid" style="padding:13px 16px;align-items:center" :style="{ background: (j.role==='supervisor' && expanded[j.id]) ? 'rgba(234,99,140,.07)' : rowBg(j.status) }">
                            <div class="flex" style="min-width:0;gap:10px;align-items:flex-start">
                                <template x-if="j.role === 'supervisor'">
                                    <button @click="toggle(j.id)" title="worker sub-jobs" class="fz-mono flex items-center justify-center" style="flex:0 0 20px;width:20px;height:20px;margin-top:1px;border-radius:6px;cursor:pointer;font-size:9px"
                                            :style="{ border:'1px solid '+(expanded[j.id]?'rgba(234,99,140,.4)':'rgba(255,255,255,.1)'), background:(expanded[j.id]?'rgba(234,99,140,.18)':'rgba(255,255,255,.04)'), color:(expanded[j.id]?'#ffd9da':'#96a0a5') }"
                                            x-text="expanded[j.id] ? '▾' : '▸'"></button>
                                </template>
                                <div style="min-width:0;flex:1">
                                    <div class="flex items-center" style="gap:8px;min-width:0">
                                        <span style="font-size:12.5px;line-height:1.45;color:#dbe2e4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0" x-text="j.goal"></span>
                                        <template x-if="j.role === 'supervisor'">
                                            <span class="fz-mono" style="flex:0 0 auto;font-size:8.5px;letter-spacing:.1em;padding:2px 7px;border-radius:5px;background:rgba(234,99,140,.14);border:1px solid rgba(234,99,140,.34);color:#ffb3c4">SUPERVISED</span>
                                        </template>
                                    </div>
                                    <div class="fz-mono" style="font-size:9.5px;color:#8a9499;margin-top:4px" x-text="(j.slug || ('job_' + String(j.id).slice(-8))) + supNote(j)"></div>
                                    <template x-if="j.role === 'supervisor' && (j.tasks || []).length">
                                        <div class="flex items-center" style="gap:9px;margin-top:7px;max-width:320px">
                                            <span class="fz-mono" style="font-size:9.5px;color:#c8d0d3;flex:0 0 auto" x-text="taskDone(j) + '/' + j.tasks.length + ' tasks'"></span>
                                            <div class="flex" style="flex:1;gap:2px;min-width:0">
                                                <template x-for="(seg, i) in j.tasks" :key="i">
                                                    <div :title="'#' + seg.seq + ' ' + seg.status" style="flex:1;height:3px;border-radius:2px" :style="{ background: taskSeg(seg.status) }"></div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <div>
                                <span class="fz-mono" style="display:inline-flex;align-items:center;gap:5px;font-size:9.5px;letter-spacing:.07em;padding:3px 9px;border-radius:20px"
                                      :style="{ background: tone(j.status).bg, color: tone(j.status).fg, border: '1px solid '+tone(j.status).bd }">
                                    <span :style="{ width:'5px', height:'5px', borderRadius:'50%', background: tone(j.status).fg, animation: tone(j.status).anim }"></span>
                                    <span x-text="j.status.toUpperCase()"></span>
                                </span>
                            </div>
                            <div class="fz-mono" style="font-size:12px;color:#c8d0d3;text-align:right" x-text="j.iteration"></div>
                            <div class="fz-mono" style="font-size:12px;color:#c8d0d3;text-align:right" x-text="j.tool_call_count"></div>
                            <div>
                                <div class="fz-mono" style="font-size:11px;margin-bottom:4px" :style="{ color: confFg(j.confidence) }" x-text="j.confidence != null ? Number(j.confidence).toFixed(2) : '—'"></div>
                                <div style="height:3px;border-radius:2px;background:rgba(255,255,255,.08);overflow:hidden"><div style="height:100%;border-radius:2px" :style="{ width: (j.confidence != null ? Math.round(j.confidence*100) : 0)+'%', background: confFg(j.confidence) }"></div></div>
                            </div>
                            <div class="fz-mono" style="font-size:10.5px;color:#96a0a5;line-height:1.4" x-text="fmt(j.created_at)"></div>
                            <div class="flex" style="gap:5px;justify-content:flex-end">
                                <a :href="'/jobs/' + j.id" class="fz-mono" style="font-size:9.5px;padding:4px 8px;border-radius:6px;border:1px solid rgba(234,99,140,.3);background:rgba(234,99,140,.1);color:#ffb3c4;text-decoration:none">OPEN</a>
                                <button x-show="['failed','cancelled'].includes(j.status)" @click="retry(j)" title="Retry" class="fz-mono" style="font-size:9.5px;padding:4px 8px;border-radius:6px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#a3adb1;cursor:pointer">↻</button>
                                <button @click="del(j)" title="Delete" class="fz-mono" style="font-size:9.5px;padding:4px 8px;border-radius:6px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#a3adb1;cursor:pointer"
                                        onmouseover="this.style.color='#ff9b9b';this.style.borderColor='rgba(255,107,107,.4)'" onmouseout="this.style.color='#a3adb1';this.style.borderColor='rgba(255,255,255,.1)'">✕</button>
                            </div>
                        </div>

                        {{-- worker sub-jobs (expanded supervisor) --}}
                        <template x-if="j.role === 'supervisor' && expanded[j.id]">
                            <div style="background:rgba(0,0,0,.28);border-top:1px solid rgba(255,255,255,.05)">
                                <div class="fz-mono" style="padding:9px 16px 5px 46px;font-size:9px;letter-spacing:.13em;color:#8a9499" x-text="'WORKER SUB-JOBS · ' + (j.children || []).length"></div>
                                <template x-for="w in (j.children || [])" :key="w.id">
                                    <div class="jl-grid" style="padding:10px 16px 10px 46px;align-items:center;border-top:1px solid rgba(255,255,255,.04);animation:rowIn .22s ease both">
                                        <div class="flex items-center" style="min-width:0;gap:10px" :style="{ borderLeft: '2px solid '+tone(w.status).fg, paddingLeft: '11px' }">
                                            <div style="min-width:0">
                                                <div class="flex items-center" style="gap:7px;min-width:0">
                                                    <span class="fz-mono" style="font-size:11px;color:#c8d0d3;flex:0 0 auto" x-text="w.slug || ('job_' + String(w.id).slice(-8))"></span>
                                                    <span class="fz-mono" style="flex:0 0 auto;font-size:8.5px;letter-spacing:.1em;padding:1px 6px;border-radius:5px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);color:#96a0a5">WORKER</span>
                                                    <template x-if="w.task_seq"><span class="fz-mono" style="font-size:9.5px;color:#8a9499;flex:0 0 auto" x-text="'TASK #' + w.task_seq"></span></template>
                                                </div>
                                                <div style="font-size:12px;color:#a9b3b7;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="w.task_title"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <span class="fz-mono" style="display:inline-flex;align-items:center;gap:5px;font-size:8.5px;letter-spacing:.06em;padding:3px 8px;border-radius:20px"
                                                  :style="{ background: tone(w.status).bg, color: tone(w.status).fg, border: '1px solid '+tone(w.status).bd }">
                                                <span :style="{ width:'4px', height:'4px', borderRadius:'50%', background: tone(w.status).fg, animation: tone(w.status).anim }"></span>
                                                <span x-text="w.status.toUpperCase()"></span>
                                            </span>
                                        </div>
                                        <div class="fz-mono" style="font-size:11px;color:#a3adb1;text-align:right" x-text="w.iteration"></div>
                                        <div class="fz-mono" style="font-size:11px;color:#a3adb1;text-align:right" x-text="w.tool_call_count"></div>
                                        <div>
                                            <div class="fz-mono" style="font-size:10.5px;margin-bottom:4px" :style="{ color: confFg(w.confidence) }" x-text="w.confidence != null ? Number(w.confidence).toFixed(2) : '—'"></div>
                                            <div style="height:2px;border-radius:2px;background:rgba(255,255,255,.07);overflow:hidden"><div style="height:100%;border-radius:2px" :style="{ width: (w.confidence != null ? Math.round(w.confidence*100) : 0)+'%', background: confFg(w.confidence) }"></div></div>
                                        </div>
                                        <div class="fz-mono" style="font-size:10px;color:#8a9499" x-text="fmt(w.created_at)"></div>
                                        <div class="flex" style="justify-content:flex-end">
                                            <a :href="'/jobs/' + w.id" class="fz-mono" style="font-size:9px;padding:3px 8px;border-radius:6px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#a3adb1;text-decoration:none">OPEN</a>
                                        </div>
                                    </div>
                                </template>
                                <div x-show="!(j.children || []).length" class="fz-mono" style="padding:8px 16px 12px 46px;font-size:11px;color:#8a9499">No workers spawned yet.</div>
                            </div>
                        </template>
                    </div>
                </template>
                <div x-show="filtered.length === 0" style="padding:32px 16px;text-align:center;color:#8a9499;font-size:13px" x-text="jobs.length ? 'No jobs match this filter.' : 'No research jobs yet.'"></div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function jobsList() {
    const TONE = {
        running:   { bg:'rgba(234,99,140,.18)', fg:'#ffb3c4', bd:'rgba(234,99,140,.45)', anim:'breathe 1.6s ease-in-out infinite', row:'rgba(234,99,140,.05)' },
        pending:   { bg:'rgba(234,99,140,.18)', fg:'#ffb3c4', bd:'rgba(234,99,140,.45)', anim:'breathe 1.6s ease-in-out infinite', row:'rgba(234,99,140,.05)' },
        completed: { bg:'rgba(62,207,142,.12)', fg:'#5fdda5', bd:'rgba(62,207,142,.3)', anim:'none', row:'transparent' },
        waiting:   { bg:'rgba(242,182,97,.14)', fg:'#f5c987', bd:'rgba(242,182,97,.35)', anim:'breathe 2s ease-in-out infinite', row:'rgba(242,182,97,.035)' },
        failed:    { bg:'rgba(255,107,107,.13)', fg:'#ff9b9b', bd:'rgba(255,107,107,.32)', anim:'none', row:'transparent' },
        cancelled: { bg:'rgba(255,255,255,.06)', fg:'#a3adb1', bd:'rgba(255,255,255,.12)', anim:'none', row:'transparent' },
    };
    const fallback = { bg:'rgba(255,255,255,.06)', fg:'#a3adb1', bd:'rgba(255,255,255,.12)', anim:'none', row:'transparent' };
    const TASK_SEG = { pending:'rgba(255,255,255,.12)', in_progress:'#ea638c', awaiting_review:'#f2b661', reviewing:'#a87ff9', done:'#3ecf8e', failed:'#ff6b6b' };
    return {
        jobs: @json($jobs),
        tab: 'ALL',
        q: '',
        mode: 'SUPERVISED',
        expanded: {},
        start() {
            // auto-expand a running supervisor so its workers are visible
            const sup = this.jobs.find(j => j.role === 'supervisor' && j.status === 'running');
            if (sup) this.expanded[sup.id] = true;
            this.timer = setInterval(() => this.refresh(), 4000);
        },
        async refresh() {
            try { this.jobs = await (await fetch('{{ route('ui.jobs') }}')).json(); } catch (e) {}
        },
        toggle(id) { this.expanded[id] = !this.expanded[id]; },
        tone(s) { return TONE[s] || fallback; },
        rowBg(s) { return this.tone(s).row; },
        confFg(c) { return c == null ? '#8a9499' : c >= 0.8 ? '#3ecf8e' : c >= 0.5 ? '#f2b661' : '#ff6b6b'; },
        taskSeg(s) { return TASK_SEG[s] || TASK_SEG.pending; },
        taskDone(j) { return (j.tasks || []).filter(t => t.status === 'done').length; },
        supNote(j) {
            if (j.role !== 'supervisor') return '';
            const n = (j.children || []).length;
            return ' · supervisor · ' + n + (n === 1 ? ' worker' : ' workers');
        },
        get filtered() {
            const q = this.q.trim().toLowerCase();
            return this.jobs.filter(j =>
                (this.tab === 'ALL' || j.status === this.tab.toLowerCase()) &&
                (!q || (j.goal || '').toLowerCase().includes(q)));
        },
        fmt(t) { return t ? new Date(t).toLocaleString([], { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }) : '—'; },
        async retry(j) {
            if (!confirm('Retry this research?\n\n' + j.goal)) return;
            const r = await window.postJson('/jobs/' + j.id + '/retry', {});
            if (r && r.error) { alert(r.error); return; }
            j.status = 'running';
        },
        async del(j) {
            if (j.status === 'running') { alert('This research is still running. Open it and Stop it first, then delete.'); return; }
            if (!confirm('Delete this research and all its history?\n\n' + j.goal)) return;
            const r = await window.deleteJson('/jobs/' + j.id);
            if (r && r.error) { alert(r.error); return; }
            this.jobs = this.jobs.filter(x => x.id !== j.id);
        },
    };
}
</script>
@endpush
@endsection
