@extends('dashboard.layout')
@section('title', 'Nothing to preview')

@section('header')
<div>
    <h1 style="margin:0;font-size:19px;font-weight:600;letter-spacing:-.012em;color:#f2f5f6">Nothing to preview</h1>
    <div class="fz-mono" style="font-size:10.5px;color:#8a9499;margin-top:4px">{{ $job }}{{ $path !== '' ? ' / '.$path : '' }}</div>
</div>
@endsection

@section('content')
<div style="border:1px solid rgba(255,255,255,.07);border-radius:13px;background:#1a1f21;padding:26px">
    <p style="margin:0;font-size:13.5px;line-height:1.65;color:#c8d0d3">
        @if ($path === '')
            This workspace has no <span class="fz-mono" style="color:#ffb3c4">index.html</span> at its root, so there's no page to show.
        @else
            <span class="fz-mono" style="color:#ffb3c4">{{ $path }}</span> doesn't exist in this workspace.
        @endif
    </p>
    <p style="margin:12px 0 0;font-size:12.5px;line-height:1.65;color:#8a9499">
        The preview serves files straight off the workspace — it can't show what the job never wrote.
        Browse what's actually there in the sandbox console, or point the URL at a file in a subdirectory.
    </p>
    <div class="flex" style="gap:8px;margin-top:18px">
        <a href="{{ route('sandbox') }}?job={{ urlencode($job) }}" class="fz-mono"
           style="font-size:11px;padding:7px 13px;border-radius:8px;border:1px solid rgba(234,99,140,.3);background:rgba(234,99,140,.1);color:#ffb3c4;text-decoration:none">Open workspace console</a>
        <a href="{{ route('dashboard') }}" class="fz-mono"
           style="font-size:11px;padding:7px 13px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#a3adb1;text-decoration:none">All jobs</a>
    </div>
</div>
@endsection
