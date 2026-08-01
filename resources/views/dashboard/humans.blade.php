@extends('dashboard.layout')
@section('title', config('research.human.name', 'Father'))

@php
    use App\Models\HumanQuestion;

    $stVal = fn ($h) => $h->status->value ?? (string) $h->status;
    $onlineCount = $humans->filter(fn ($h) => $stVal($h) !== 'offline')->count();
    $blockingCount = $open->filter(fn ($q) => ($q->status->value ?? (string) $q->status) === 'queued')->count();

    // Recently answered — read here so the shell/controller stay unchanged; guarded.
    $answered = rescue(fn () => HumanQuestion::whereIn('status', ['answered', 'resolved_by_other'])
        ->latest('updated_at')->limit(6)->get(), collect(), false);

    $tone = [
        'available' => ['fg' => '#5fdda5', 'anim' => 'breathe 2.4s ease-in-out infinite', 'cardBd' => 'rgba(62,207,142,.22)', 'cardBg' => 'linear-gradient(180deg,rgba(62,207,142,.05),#1a1f21)', 'avBg' => 'rgba(62,207,142,.12)', 'avBd' => 'rgba(62,207,142,.3)', 'avFg' => '#5fdda5'],
        'busy'      => ['fg' => '#f5c987', 'anim' => 'breathe 2s ease-in-out infinite',   'cardBd' => 'rgba(242,182,97,.22)', 'cardBg' => 'linear-gradient(180deg,rgba(242,182,97,.05),#1a1f21)', 'avBg' => 'rgba(242,182,97,.12)', 'avBd' => 'rgba(242,182,97,.3)', 'avFg' => '#f5c987'],
        'away'      => ['fg' => '#f2b661', 'anim' => 'breathe 2.6s ease-in-out infinite', 'cardBd' => 'rgba(242,182,97,.18)', 'cardBg' => 'linear-gradient(180deg,rgba(242,182,97,.04),#1a1f21)', 'avBg' => 'rgba(242,182,97,.1)',  'avBd' => 'rgba(242,182,97,.26)', 'avFg' => '#f2b661'],
        'offline'   => ['fg' => '#8a9499', 'anim' => 'none', 'cardBd' => 'rgba(255,255,255,.07)', 'cardBg' => '#1a1f21', 'avBg' => 'rgba(255,255,255,.05)', 'avBd' => 'rgba(255,255,255,.1)', 'avFg' => '#96a0a5'],
    ];
    $stateBtns = ['available' => 'AVL', 'busy' => 'BSY', 'away' => 'AWY', 'offline' => 'OFF'];
    $initials = fn ($name) => strtoupper(collect(explode(' ', trim($name)))->filter()->take(2)->map(fn ($w) => $w[0] ?? '')->implode('')) ?: '?';
@endphp

@section('header')
<div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;width:100%">
    <div>
        <h1 style="margin:0;font-size:19px;font-weight:600;letter-spacing:-.012em;color:#f2f5f6">{{ config('research.human.name', 'Father') }} &amp; Questions</h1>
        <div class="fz-mono" style="font-size:10.5px;color:#8a9499;margin-top:4px">{{ $open->count() }} open · {{ $blockingCount }} blocking a run · {{ $onlineCount }} of {{ $humans->count() }} online</div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
        <div class="fz-mono" style="display:flex;align-items:center;gap:7px;padding:7px 12px;border-radius:8px;border:1px solid rgba(62,207,142,.28);background:rgba(62,207,142,.08);font-size:11px;color:#5fdda5">
            <span style="width:6px;height:6px;border-radius:50%;background:#3ecf8e;animation:breathe 2.4s ease-in-out infinite"></span>{{ $onlineCount }} AVAILABLE
        </div>
    </div>
</div>
@endsection

@section('content')
<style>
    @keyframes breathe{0%,100%{opacity:.55}50%{opacity:1}}
    .hqa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(268px,1fr));gap:14px}
</style>

{{-- AVAILABILITY --}}
<div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499;margin-bottom:12px">AVAILABILITY</div>
<div class="hqa-grid">
    @forelse ($humans as $h)
        @php $s = $stVal($h); $t = $tone[$s] ?? $tone['offline']; @endphp
        <div style="border-radius:13px;border:1px solid {{ $t['cardBd'] }};background:{{ $t['cardBg'] }};padding:15px">
            <div class="flex items-center" style="gap:11px">
                <div class="fz-mono" style="width:34px;height:34px;flex:0 0 34px;border-radius:11px;background:{{ $t['avBg'] }};border:1px solid {{ $t['avBd'] }};display:flex;align-items:center;justify-content:center;font-size:11.5px;font-weight:700;color:{{ $t['avFg'] }}">{{ $initials($h->name) }}</div>
                <div style="min-width:0">
                    <div style="font-size:13.5px;font-weight:600;color:#eef1f2">{{ $h->name }}</div>
                    <div class="fz-mono" style="font-size:10px;color:#96a0a5">{{ $h->expertise[0] ?? 'generalist' }}</div>
                </div>
                <div class="flex items-center" style="margin-left:auto;gap:6px">
                    <span style="width:7px;height:7px;border-radius:50%;background:{{ $t['fg'] }};animation:{{ $t['anim'] }}"></span>
                    <span class="fz-mono" style="font-size:9.5px;letter-spacing:.08em;color:{{ $t['fg'] }}">{{ strtoupper($s) }}</span>
                </div>
            </div>
            @if (! empty($h->expertise))
                <div class="flex flex-wrap" style="gap:5px;margin-top:12px">
                    @foreach ($h->expertise as $tag)
                        <span class="fz-mono" style="font-size:9.5px;padding:3px 8px;border-radius:20px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:#a3adb1">{{ $tag }}</span>
                    @endforeach
                </div>
            @endif
            <div class="fz-mono flex items-center" style="gap:10px;margin-top:13px;padding-top:12px;border-top:1px solid rgba(255,255,255,.06);font-size:10px;color:#8a9499">
                <span>open <span style="color:{{ $h->open_count > 0 ? '#f5c987' : '#96a0a5' }}">{{ $h->open_count }}</span></span>
                <form method="POST" action="{{ route('humans.status', $h->id) }}" style="margin-left:auto;display:flex;gap:4px">
                    @csrf
                    @foreach ($stateBtns as $val => $lbl)
                        @php $on = $s === $val; @endphp
                        <button name="status" value="{{ $val }}" class="fz-mono" style="font-size:9px;letter-spacing:.06em;padding:3px 7px;border-radius:6px;cursor:pointer;border:1px solid {{ $on ? 'rgba(234,99,140,.4)' : 'rgba(255,255,255,.08)' }};background:{{ $on ? 'rgba(234,99,140,.16)' : 'rgba(255,255,255,.03)' }};color:{{ $on ? '#ffd9da' : '#8a9499' }}">{{ $lbl }}</button>
                    @endforeach
                </form>
            </div>
        </div>
    @empty
        <p style="font-size:13px;color:#8a9499">No humans configured. Seed them: <code class="fz-mono">php artisan db:seed --class=Database\\Seeders\\HumanSeeder</code></p>
    @endforelse
</div>

{{-- OPEN QUESTIONS --}}
<div class="flex items-center" style="gap:12px;margin:26px 0 13px">
    <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499">OPEN QUESTIONS</div>
    @if ($open->count())
        <span class="fz-mono" style="font-size:10px;padding:2px 8px;border-radius:20px;background:rgba(242,182,97,.14);border:1px solid rgba(242,182,97,.35);color:#f5c987">{{ $open->count() }} WAITING</span>
    @endif
</div>

<div class="flex flex-col" style="gap:12px">
    @forelse ($open as $q)
        @php $blocking = ($q->status->value ?? (string) $q->status) === 'queued'; @endphp
        <div style="border-radius:13px;overflow:hidden;border:1px solid {{ $blocking ? 'rgba(242,182,97,.26)' : 'rgba(255,255,255,.07)' }};background:{{ $blocking ? 'linear-gradient(180deg,rgba(242,182,97,.06),#1a1f21)' : '#1a1f21' }}">
            <div class="flex flex-wrap items-center" style="gap:11px;padding:13px 16px;border-bottom:1px solid rgba(255,255,255,.06)">
                <span class="fz-mono" style="font-size:9.5px;letter-spacing:.07em;padding:3px 9px;border-radius:20px;background:{{ $blocking ? 'rgba(242,182,97,.15)' : 'rgba(255,255,255,.05)' }};color:{{ $blocking ? '#f5c987' : '#a3adb1' }};border:1px solid {{ $blocking ? 'rgba(242,182,97,.35)' : 'rgba(255,255,255,.1)' }}">{{ $blocking ? 'BLOCKING' : strtoupper($q->status->value ?? (string) $q->status) }}</span>
                <a href="{{ route('jobs.show', $q->research_job_id) }}" class="fz-mono" style="font-size:11px">{{ \Illuminate\Support\Str::limit($q->job->goal ?? ('job_'.\Illuminate\Support\Str::substr($q->research_job_id, -8)), 52) }}</a>
                <span class="fz-mono" style="font-size:10.5px;color:#8a9499">asked {{ optional($q->created_at)->diffForHumans() ?? '—' }}</span>
            </div>
            <div style="padding:15px 16px">
                <div style="font-size:14.5px;line-height:1.55;color:#eef1f2;max-width:88ch">{{ $q->question }}</div>
                @if (! empty($q->tags))
                    <div class="fz-mono" style="margin-top:11px;padding:11px 13px;border-radius:9px;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.06);font-size:11px;line-height:1.65;color:#9aa8ac">#{{ implode('  #', $q->tags) }}</div>
                @endif
                <form method="POST" action="{{ route('questions.answer', $q->id) }}" class="flex flex-wrap" style="gap:10px;margin-top:12px;align-items:flex-end">
                    @csrf
                    <textarea name="answer" rows="2" required placeholder="Type an answer — the agent resumes on submit…"
                              style="flex:1 1 340px;resize:vertical;padding:11px 13px;border-radius:10px;background:rgba(0,0,0,.34);border:1px solid rgba(255,255,255,.09);color:#eef1f2;font-size:13px;line-height:1.55"></textarea>
                    <button type="submit" style="font-size:12.5px;font-weight:600;padding:9px 18px;border-radius:9px;border:1px solid rgba(255,217,218,.35);background:linear-gradient(145deg,#ea638c,#89023e);color:#fff;cursor:pointer;box-shadow:0 8px 20px -12px rgba(234,99,140,.9)"
                            onmouseover="this.style.filter='brightness(1.1)'" onmouseout="this.style.filter='none'">Answer &amp; resume</button>
                </form>
            </div>
        </div>
    @empty
        <p style="font-size:13px;color:#8a9499">No open questions right now.</p>
    @endforelse
</div>

{{-- RECENTLY ANSWERED --}}
@if ($answered->count())
    <div class="fz-mono" style="font-size:9.5px;letter-spacing:.13em;color:#8a9499;margin:26px 0 12px">RECENTLY ANSWERED</div>
    <div style="border:1px solid rgba(255,255,255,.07);border-radius:13px;background:#1a1f21;overflow:hidden">
        @foreach ($answered as $a)
            <div class="flex flex-wrap items-center" style="gap:12px;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.05)">
                <span class="fz-mono" style="font-size:9.5px;padding:2px 8px;border-radius:20px;background:rgba(62,207,142,.12);color:#5fdda5;border:1px solid rgba(62,207,142,.3)">ANSWERED</span>
                <span style="font-size:12.5px;color:#b6bfc3;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1">{{ $a->question }}</span>
                <span class="fz-mono" style="font-size:10.5px;color:#96a0a5">{{ $a->resolved_source ?? 'human' }} · {{ optional($a->updated_at)->diffForHumans() }}</span>
            </div>
        @endforeach
    </div>
@endif
@endsection
