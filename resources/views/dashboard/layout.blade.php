<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Mission Control') · Fariborz</title>

    {{-- Dev-friendly, no build step. For production, compile Tailwind + bundle Alpine. --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Palette imported from the Claude Design "Fariborz Agent Dashboard".
        // Rose accent (#ea638c / #89023e), green #3ecf8e, amber #f2b661.
        // indigo → rose accent · blue → neutral grey · fuchsia → bright pink.
        tailwind.config = {
            theme: { extend: { colors: {
                indigo:  { 300: '#ffd9da', 400: '#f27aa0', 500: '#ea638c', 600: '#c81e56', 700: '#89023e' },
                blue:    { 300: '#aab0b7', 400: '#8b9298', 500: '#6b7178', 800: '#3a3f47', 900: '#2a2e35', 950: '#23262b' },
                fuchsia: { 400: '#ea638c', 500: '#db3a34' },
                emerald: { 300: '#5fdda5', 400: '#3ecf8e', 700: '#1f6f4d', 800: '#1a4a38', 900: '#12352a', 950: '#0e2620' },
                amber:   { 300: '#f5c987', 400: '#f2b661', 700: '#8a6427', 900: '#4a3512' },
            } } },
        };
    </script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        html, body { height: 100%; margin: 0; }
        body { background: #101416; color: #eef1f2;
               font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        .fz-mono { font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace; }
        a { color: #ea638c; text-decoration: none; }
        a:hover { color: #ffd9da; }
        /* App-shell scrollbars */
        .fz-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
        .fz-scroll::-webkit-scrollbar-track { background: transparent; }
        .fz-scroll::-webkit-scrollbar-thumb { background: #333a3e; border-radius: 8px; border: 3px solid #101416; }
        .fz-scroll::-webkit-scrollbar-thumb:hover { background: #4a5359; }
        @keyframes fzBreathe { 0%,100%{opacity:.55} 50%{opacity:1} }
        /* Responsive: collapse the rail to an icon strip (labels hide, tiles stay) */
        @media (max-width: 1000px) {
            .fz-rail { width: 66px !important; flex: 0 0 66px !important; padding-left: 10px !important; padding-right: 10px !important; }
            [data-label] { display: none !important; }
        }
        @media (max-width: 640px) {
            .fz-body { padding: 18px 16px 40px !important; }
            .fz-header { padding: 14px 16px !important; }
        }
    </style>
</head>
<body>
@php
    $r = request()->route()?->getName();
    // Live nav badges (guarded so the shell never breaks if the DB isn't ready).
    $activeJobs = rescue(fn () => \App\Models\ResearchJob::whereIn('status', ['running', 'waiting', 'pending'])->count(), 0, false);
    $openQ      = rescue(fn () => \App\Models\HumanQuestion::whereIn('status', ['queued', 'asked'])->count(), 0, false);
    $nav = [
        ['label' => 'Jobs',            'code' => 'JB', 'route' => 'dashboard', 'active' => in_array($r, ['dashboard', 'jobs.show']), 'badge' => $activeJobs, 'badgeStyle' => 'accent'],
        ['label' => config('research.human.name', 'Human') . ' Q&A', 'code' => 'QA', 'route' => 'humans', 'active' => $r === 'humans', 'badge' => $openQ, 'badgeStyle' => 'pill'],
        ['label' => 'Tools & Ollama',  'code' => 'TO', 'route' => 'tools',    'active' => $r === 'tools',    'badge' => null],
        ['label' => 'Sandbox',         'code' => 'SB', 'route' => 'sandbox',  'active' => $r === 'sandbox',  'badge' => null],
        ['label' => 'Settings',        'code' => 'ST', 'route' => 'settings', 'active' => $r === 'settings', 'badge' => null],
    ];
@endphp

<div style="display:flex;height:100vh;width:100%;overflow:hidden;background:#101416">

    {{-- ===== rail (fixed, full-height) ===== --}}
    <nav class="fz-rail" style="width:232px;flex:0 0 232px;background:#14191b;border-right:1px solid rgba(255,255,255,.06);display:flex;flex-direction:column;padding:20px 14px;gap:26px">
        <a href="{{ route('dashboard') }}" style="display:flex;align-items:center;gap:10px;padding:0 4px">
            <div class="fz-mono" style="width:26px;height:26px;flex:0 0 26px;border-radius:8px;background:linear-gradient(145deg,#ea638c,#89023e);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#1b2021">F</div>
            <div data-label>
                <div style="font-size:13.5px;font-weight:600;letter-spacing:-.01em;color:#eef1f2">Fariborz</div>
                <div class="fz-mono" style="font-size:10px;color:#96a0a5;letter-spacing:.04em">MISSION CONTROL</div>
            </div>
        </a>

        <div style="display:flex;flex-direction:column;gap:2px">
            <div data-label class="fz-mono" style="font-size:10px;letter-spacing:.12em;color:#8a9499;padding:0 8px 8px">OPERATIONS</div>
            @foreach ($nav as $item)
                @php $on = $item['active']; @endphp
                <a href="{{ route($item['route']) }}"
                   style="display:flex;align-items:center;gap:10px;padding:6px 8px;border-radius:10px;font-size:13px;{{ $on
                        ? 'background:linear-gradient(90deg,rgba(234,99,140,.16),rgba(234,99,140,.02));border:1px solid rgba(234,99,140,.22);color:#ffd9da'
                        : 'color:#98a2a7;border:1px solid transparent' }}"
                   @if (! $on) onmouseover="this.style.background='rgba(255,255,255,.04)';this.style.color='#eef1f2'" onmouseout="this.style.background='transparent';this.style.color='#98a2a7'" @endif>
                    {{-- icon tile with 2-letter code (stays visible when the rail collapses) --}}
                    <div class="fz-mono" title="{{ $item['label'] }}" style="position:relative;width:28px;height:28px;flex:0 0 28px;border-radius:9px;font-size:9.5px;font-weight:700;letter-spacing:.03em;display:flex;align-items:center;justify-content:center;{{ $on
                            ? 'background:rgba(234,99,140,.18);border:1px solid rgba(234,99,140,.45);color:#ffd9da'
                            : 'background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#96a0a5' }}">
                        {{ $item['code'] }}
                        @if (($item['badgeStyle'] ?? '') === 'pill' && ! empty($item['badge']))
                            <span style="position:absolute;top:-4px;right:-5px;min-width:15px;height:15px;padding:0 3px;border-radius:8px;background:#89023e;border:1px solid #14191b;color:#ffd9da;font-size:8.5px;line-height:13px;text-align:center">{{ $item['badge'] }}</span>
                        @endif
                    </div>
                    <span data-label>{{ $item['label'] }}</span>
                    @if (! empty($item['badge']))
                        @if (($item['badgeStyle'] ?? '') === 'pill')
                            <span data-label class="fz-mono" style="margin-left:auto;font-size:10px;background:#89023e;color:#ffd9da;padding:1px 6px;border-radius:20px">{{ $item['badge'] }}</span>
                        @else
                            <span data-label class="fz-mono" style="margin-left:auto;font-size:10px;color:{{ $on ? '#ea638c' : '#8a9499' }}">{{ $item['badge'] }}</span>
                        @endif
                    @endif
                </a>
            @endforeach
        </div>

        <div data-label class="fz-mono" style="margin-top:auto;border-top:1px solid rgba(255,255,255,.06);padding-top:14px;display:flex;flex-direction:column;gap:9px;font-size:10.5px">
            <div style="display:flex;justify-content:space-between"><span style="color:#8a9499">RUNTIME</span><span style="color:#a3adb1">{{ config('research.llm.driver') }}</span></div>
            <div style="display:flex;justify-content:space-between"><span style="color:#8a9499">MODEL</span><span style="color:#a3adb1">{{ config('research.llm.model') }}</span></div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:2px">
                <div style="width:6px;height:6px;border-radius:50%;background:#3ecf8e;animation:fzBreathe 2.4s ease-in-out infinite"></div>
                <span style="color:#96a0a5">agent online</span>
            </div>
        </div>
    </nav>

    {{-- ===== main (frozen header + independent scroll body) ===== --}}
    <main style="flex:1;min-width:0;display:flex;flex-direction:column;overflow:hidden">
        @hasSection('header')
            <header class="fz-header" style="flex:0 0 auto;padding:18px 28px 16px;border-bottom:1px solid rgba(255,255,255,.06);background:#14191b">
                @yield('header')
            </header>
        @endif

        <div class="fz-scroll fz-body" style="flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding:22px 28px 40px">
            @if (session('status'))
                <div style="margin-bottom:16px;border-radius:9px;border:1px solid rgba(62,207,142,.35);background:rgba(62,207,142,.1);padding:9px 14px;font-size:13px;color:#5fdda5">
                    {{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div style="margin-bottom:16px;border-radius:9px;border:1px solid rgba(137,2,62,.5);background:rgba(137,2,62,.22);padding:9px 14px;font-size:13px;color:#ffb3c4">
                    {{ $errors->first() }}
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</div>

<script>
    // Small fetch helper that attaches the CSRF token for JSON POSTs.
    window.csrf = document.querySelector('meta[name=csrf-token]').content;
    window.postJson = (url, body) => fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrf, 'Accept': 'application/json' },
        body: JSON.stringify(body || {}),
    }).then(r => r.json());
    window.deleteJson = (url) => fetch(url, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': window.csrf, 'Accept': 'application/json' },
    }).then(r => r.json());
</script>
{{-- BEGIN DEV NOTES MODULE (removable): corner popup note-taker --}}
@include('devnotes.widget')
{{-- END DEV NOTES MODULE --}}

@stack('scripts')
</body>
</html>
