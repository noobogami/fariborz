@extends('dashboard.layout')
@section('title', 'Settings')

@section('header')
<div class="fz-mono" style="font-size:10.5px;letter-spacing:.12em;color:#8a9499;margin-bottom:6px">CONFIG</div>
<h1 style="margin:0;font-size:19px;font-weight:600;letter-spacing:-.015em;color:#f2f5f6">Settings</h1>
@endsection

@section('content')
<p class="text-sm text-gray-400 mb-6">
    Everything the agent needs — LLM, API keys, endpoints, limits — is configured here.
    Changes apply to the next research iteration (no restart). Only the database and
    Redis are set in <code>.env</code>.
</p>

<form method="POST" action="{{ route('settings.update') }}" class="space-y-8 max-w-3xl">
    @csrf
    @foreach ($schema as $group => $fields)
        <div class="rounded-lg border border-white/[.07] bg-[#1a1f21] p-5">
            <h2 class="text-sm font-semibold text-gray-300 mb-4">{{ $group }}</h2>
            <div class="space-y-4">
                @foreach ($fields as $f)
                    @php($key = $f['key'])
                    @php($val = $values[$key] ?? null)
                    @php($secret = $f['secret'] ?? false)
                    <div class="grid grid-cols-3 gap-4 items-start">
                        <label class="text-sm text-gray-300 pt-2">
                            {{ $f['label'] }}
                            @if (! empty($f['help']))
                                <span class="block text-xs text-gray-600 mt-0.5">{{ $f['help'] }}</span>
                            @endif
                        </label>
                        <div class="col-span-2">
                            @if ($f['type'] === 'select')
                                <select name="settings[{{ $key }}]"
                                        class="w-full rounded bg-[#101416] border border-white/10 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                                    @foreach ($f['options'] as $opt)
                                        <option value="{{ $opt }}" @selected($val === $opt)>{{ $opt }}</option>
                                    @endforeach
                                </select>
                            @elseif ($f['type'] === 'bool')
                                <label class="inline-flex items-center gap-2 text-sm text-gray-300">
                                    <input type="checkbox" name="settings[{{ $key }}]" value="1" @checked($val)
                                           class="rounded bg-[#101416] border-white/10">
                                    enabled
                                </label>
                            @elseif ($secret)
                                <input type="password" name="settings[{{ $key }}]" autocomplete="new-password"
                                       placeholder="{{ $val ? '•••••••• (set — leave blank to keep)' : 'not set' }}"
                                       class="w-full rounded bg-[#101416] border border-white/10 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                            @else
                                <input type="{{ $f['type'] === 'int' || $f['type'] === 'float' ? 'number' : 'text' }}"
                                       @if ($f['type'] === 'float') step="0.1" @endif
                                       name="settings[{{ $key }}]" value="{{ $val }}"
                                       class="w-full rounded bg-[#101416] border border-white/10 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="flex items-center gap-3">
        <button class="rounded bg-indigo-600 hover:bg-indigo-500 px-5 py-2 text-sm font-medium">Save settings</button>
        <span class="text-xs text-gray-500">Empty a field to revert it to its <code>.env</code>/default value. Secrets are stored encrypted.</span>
    </div>
</form>
@endsection
