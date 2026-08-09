@extends('dashboard.layout')
@section('title', 'Settings')

@php
    use Illuminate\Support\Str;

    // Build the UI payload from the real settings schema + current values + overrides.
    $grpMeta = function (string $name) {
        $title = $name;
        $note = '';
        if (($p = strpos($name, '(')) !== false) {
            $title = rtrim(substr($name, 0, $p));
            $note = rtrim(substr($name, $p + 1), ') ');
        }
        $nav = trim(explode(' — ', $title)[0]);

        return [mb_strtoupper($title), $note, $nav];
    };

    $groups = [];
    foreach ($schema as $groupName => $fields) {
        [$title, $note, $nav] = $grpMeta($groupName);
        $rows = [];
        foreach ($fields as $f) {
            $key = $f['key'];
            $secret = ! empty($f['secret']);
            $type = $secret ? 'secret' : $f['type'];       // select|string|float|int|bool|secret
            $val = $values[$key] ?? null;
            $rows[] = [
                'key' => $key,
                'label' => $f['label'],
                'help' => $f['help'] ?? '',
                'type' => $type,
                // Forces the dropdown control regardless of option count (see
                // SettingsController::withModelDropdowns) — a segmented row of
                // model names is unreadable and hides the blank option.
                'ui' => $f['ui'] ?? '',
                // Warning shown under the label: gateway down / empty / stale value.
                'notice' => $f['notice'] ?? '',
                // A saved value the gateway no longer serves, kept selectable.
                'stale' => $f['stale'] ?? '',
                'numeric' => in_array($f['type'], ['int', 'float'], true),
                'options' => $f['options'] ?? [],
                'value' => $type === 'bool' ? (bool) $val : ($secret ? '' : ($val ?? '')),
                'isSet' => $secret ? (bool) $val : false,
                'state' => in_array($key, $overridden, true) ? 'override' : 'default',
            ];
        }
        $groups[] = ['id' => Str::slug($nav) ?: 'grp'.count($groups), 'title' => $title, 'note' => $note, 'nav' => $nav, 'fields' => $rows];
    }
    $payload = ['groups' => $groups];
@endphp

@section('header')
<div x-data style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;width:100%">
    <div>
        <h1 style="margin:0;font-size:19px;font-weight:600;letter-spacing:-.012em;color:#f2f5f6">Settings</h1>
        <div class="fz-mono" style="font-size:10.5px;color:#8a9499;margin-top:4px">
            runtime config · <span x-text="$store.settings.overrideCount"></span> overridden · <span x-text="$store.settings.dirtyCount"></span> unsaved · applies next iteration
        </div>
    </div>

    <div class="flex items-center" style="margin-left:auto;gap:8px;flex-wrap:wrap">
        <input x-model="$store.settings.query" placeholder="filter settings…" class="fz-mono"
               style="width:196px;padding:8px 11px;border-radius:8px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);color:#e4e9ea;font-size:11.5px">
        <button type="button" @click="$store.settings.dirty && document.getElementById('settingsForm').requestSubmit()" class="fz-mono"
                :style="{ fontSize:'12.5px', fontWeight:'600', letterSpacing:'.03em', padding:'9px 17px', borderRadius:'8px', cursor: $store.settings.dirty?'pointer':'default', border:'1px solid '+($store.settings.dirty?'rgba(255,217,218,.35)':'rgba(255,255,255,.1)'), background: $store.settings.dirty?'linear-gradient(145deg,#ea638c,#89023e)':'rgba(255,255,255,.03)', color: $store.settings.dirty?'#fff':'#6f797d', boxShadow: $store.settings.dirty?'0 10px 26px -14px rgba(234,99,140,.9)':'none' }">SAVE CHANGES</button>
    </div>
</div>
@endsection

@section('content')
<style>
    @keyframes barIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
    @keyframes breathe{0%,100%{opacity:.55}50%{opacity:1}}
    .set-cols{display:flex;gap:26px;align-items:flex-start}
    .set-nav{position:sticky;top:0;flex:0 0 196px;width:196px}
    .set-navlist{display:flex;flex-direction:column;gap:2px}
    .set-main{flex:1 1 640px;min-width:0;display:flex;flex-direction:column;gap:18px}
    .set-row{display:flex;align-items:center;gap:20px}
    .set-ctl{flex:0 0 320px;width:320px;display:flex;align-items:center;gap:9px;justify-content:flex-end}
    @media (max-width:1000px){
        .set-cols{flex-direction:column}
        .set-nav{position:static;width:100%;flex:1 1 auto}
        .set-navlist{flex-direction:row;flex-wrap:wrap}
        .set-main{flex-basis:100%}
        .set-row{flex-direction:column;align-items:stretch;gap:9px}
        .set-ctl{width:100%;flex:1 1 auto;justify-content:flex-start}
    }
</style>

<div x-data>
    <div class="set-cols">

        {{-- section nav --}}
        <nav class="set-nav">
                <div class="fz-mono" style="font-size:9px;letter-spacing:.13em;color:#8a9499;padding:0 8px 9px">SECTIONS</div>
                <div class="set-navlist">
                    <template x-for="n in $store.settings.nav" :key="n.id">
                        <a :href="'#' + n.id" @click="$store.settings.active = n.id" class="flex items-center"
                           style="gap:9px;padding:7px 9px;border-radius:9px;font-size:12.5px;text-decoration:none"
                           :style="{ border: '1px solid '+(n.on?'rgba(234,99,140,.24)':'transparent'), background: n.on?'linear-gradient(90deg,rgba(234,99,140,.16),rgba(234,99,140,.02))':'transparent', color: n.on?'#ffd9da':'#98a2a7' }">
                            <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="n.label"></span>
                            <span class="fz-mono" style="margin-left:auto;font-size:9.5px;flex:0 0 auto" :style="{ color: n.dirty ? '#f2b661' : '#6f797d' }" x-text="n.count"></span>
                        </a>
                    </template>

                    {{-- operational section (not editable settings — lives below the config) --}}
                    <div class="fz-mono" style="font-size:9px;letter-spacing:.13em;color:#8a9499;padding:11px 8px 7px">OPERATIONS</div>
                    <a href="#tools" @click="$store.settings.active = 'tools'" class="flex items-center"
                       style="gap:9px;padding:7px 9px;border-radius:9px;font-size:12.5px;text-decoration:none"
                       :style="{ border: '1px solid '+($store.settings.active==='tools'?'rgba(234,99,140,.24)':'transparent'), background: $store.settings.active==='tools'?'linear-gradient(90deg,rgba(234,99,140,.16),rgba(234,99,140,.02))':'transparent', color: $store.settings.active==='tools'?'#ffd9da':'#98a2a7' }">
                        <span>Tools &amp; Gateway</span>
                    </a>
                </div>
                <div style="margin-top:14px;padding:11px;border-radius:11px;border:1px solid rgba(255,255,255,.07);background:#1a1f21;display:flex;flex-direction:column;gap:8px" class="fz-mono">
                    <div style="letter-spacing:.12em;color:#8a9499;font-size:9px">LEGEND</div>
                    <div class="flex items-center" style="gap:7px;font-size:10px"><span style="width:7px;height:7px;border-radius:2px;background:rgba(255,255,255,.22)"></span><span style="color:#96a0a5">from .env / default</span></div>
                    <div class="flex items-center" style="gap:7px;font-size:10px"><span style="width:7px;height:7px;border-radius:2px;background:#ea638c"></span><span style="color:#96a0a5">saved override</span></div>
                    <div class="flex items-center" style="gap:7px;font-size:10px"><span style="width:7px;height:7px;border-radius:2px;background:#f2b661"></span><span style="color:#96a0a5">edited, unsaved</span></div>
                </div>
            </nav>

            {{-- groups --}}
            <div class="set-main" :style="{ paddingBottom: $store.settings.dirty ? '58px' : '0' }">
              <form id="settingsForm" method="POST" action="{{ route('settings.update') }}" style="display:flex;flex-direction:column;gap:18px">
                @csrf
                <template x-for="g in $store.settings.groups" :key="g.id">
                    <section :id="g.id" style="border-radius:14px;background:#1a1f21;overflow:hidden;scroll-margin-top:16px" :style="{ border: '1px solid '+g.bd }">
                        <div class="flex flex-wrap items-center" style="gap:11px;padding:13px 16px;border-bottom:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.02)">
                            <span class="fz-mono" style="font-size:10.5px;letter-spacing:.14em" :style="{ color: g.titleFg }" x-text="g.title"></span>
                            <span class="fz-mono" style="font-size:10px;color:#8a9499;min-width:0" x-text="g.note"></span>
                            <span class="fz-mono" style="margin-left:auto;font-size:9.5px;color:#8a9499;flex:0 0 auto" x-text="g.countLabel"></span>
                        </div>

                        <template x-for="f in g.fields" :key="f.key">
                            <div class="set-row" style="padding:13px 16px;border-top:1px solid rgba(255,255,255,.045)" :style="{ background: f.edited ? 'rgba(242,182,97,.045)' : 'transparent' }">
                                <div style="min-width:0;flex:1 1 auto">
                                    <div style="font-size:13px;color:#dbe2e4;letter-spacing:-.004em" x-text="f.label"></div>
                                    <div class="fz-mono" style="font-size:10px;color:#8a9499;margin-top:4px;line-height:1.5" x-text="f.help"></div>
                                    <template x-if="f.notice">
                                        <div class="fz-mono flex" style="gap:6px;align-items:flex-start;margin-top:6px;font-size:10px;line-height:1.5;color:#f5c987">
                                            <span style="flex:0 0 auto">▲</span><span x-text="f.notice"></span>
                                        </div>
                                    </template>
                                </div>

                                <div class="set-ctl">
                                    {{-- text / number --}}
                                    <template x-if="f.control === 'input'">
                                        <div style="position:relative;flex:1;min-width:0">
                                            <input type="text" :value="$store.settings.cur(f)" @input="$store.settings.set(f, $event.target.value)"
                                                   placeholder="from .env / default" class="fz-mono"
                                                   style="width:100%;padding:8px 11px;border-radius:8px;background:#101416;font-size:11.5px"
                                                   :style="{ border: '1px solid '+(f.edited?'rgba(242,182,97,.4)':'rgba(255,255,255,.1)'), color: f.edited?'#f7e2c2':'#e4e9ea' }">
                                            <template x-if="f.edited"><input type="hidden" :name="'settings['+f.key+']'" :value="$store.settings.cur(f)"></template>
                                        </div>
                                    </template>

                                    {{-- secret --}}
                                    <template x-if="f.control === 'secret'">
                                        <div class="flex items-center" style="flex:1;min-width:0;gap:8px">
                                            <input type="password" :name="'settings['+f.key+']'" x-model="$store.settings.edits[f.key]"
                                                   :placeholder="f.isSet ? '•••••••• (set — leave blank to keep)' : 'not set'" class="fz-mono"
                                                   style="flex:1;min-width:0;padding:8px 11px;border-radius:8px;background:#101416;font-size:11.5px;letter-spacing:.08em"
                                                   :style="{ border: '1px solid '+($store.settings.edits[f.key]?'rgba(242,182,97,.4)':'rgba(255,255,255,.1)'), color:'#e4e9ea' }">
                                            <span class="fz-mono" style="flex:0 0 auto;font-size:8.5px;letter-spacing:.08em;padding:3px 7px;border-radius:5px"
                                                  :style="{ background: f.isSet?'rgba(62,207,142,.12)':'rgba(255,255,255,.04)', border:'1px solid '+(f.isSet?'rgba(62,207,142,.3)':'rgba(255,255,255,.1)'), color: f.isSet?'#5fdda5':'#8a9499' }"
                                                  x-text="f.isSet ? 'SET' : 'NOT SET'"></span>
                                        </div>
                                    </template>

                                    {{-- segmented select --}}
                                    <template x-if="f.control === 'seg'">
                                        <div class="flex" style="gap:3px;padding:3px;border-radius:9px;background:#101416;border:1px solid rgba(255,255,255,.09)">
                                            <template x-for="o in f.options" :key="o">
                                                <button type="button" @click="$store.settings.set(f, o)" class="fz-mono"
                                                        :style="{ fontSize:'11px', letterSpacing:'.03em', padding:'5px 12px', borderRadius:'7px', cursor:'pointer', border:'1px solid '+($store.settings.cur(f)===o?'rgba(234,99,140,.42)':'transparent'), background:($store.settings.cur(f)===o?'rgba(234,99,140,.18)':'transparent'), color:($store.settings.cur(f)===o?'#ffd9da':'#8e9a9f') }"
                                                        x-text="o"></button>
                                            </template>
                                            <template x-if="f.edited"><input type="hidden" :name="'settings['+f.key+']'" :value="$store.settings.cur(f)"></template>
                                        </div>
                                    </template>

                                    {{-- dropdown (dynamic model list from the LiteLLM gateway) --}}
                                    <template x-if="f.control === 'select'">
                                        <div style="position:relative;flex:1;min-width:0">
                                            <select @change="$store.settings.set(f, $event.target.value)"
                                                    x-init="$nextTick(() => { $el.value = $store.settings.cur(f) })"
                                                    x-effect="$store.settings.cur(f); $nextTick(() => { $el.value = $store.settings.cur(f) })" class="fz-mono"
                                                    style="width:100%;padding:8px 30px 8px 11px;border-radius:8px;background:#101416;font-size:11.5px;appearance:none;-webkit-appearance:none;cursor:pointer"
                                                    :style="{ border: '1px solid '+(f.edited?'rgba(242,182,97,.4)':'rgba(255,255,255,.1)'), color: f.edited?'#f7e2c2':'#e4e9ea' }">
                                                <template x-for="o in f.options" :key="o">
                                                    <option :value="o" :selected="String($store.settings.cur(f)) === String(o)"
                                                            x-text="o==='' ? '— use default model —' : (o===f.stale ? o + '  ·  not on gateway' : o)"
                                                            style="background:#101416;color:#e4e9ea"></option>
                                                </template>
                                            </select>
                                            <span class="fz-mono" style="position:absolute;right:11px;top:50%;transform:translateY(-50%);pointer-events:none;font-size:9px;color:#8a9499">▾</span>
                                            <template x-if="f.edited"><input type="hidden" :name="'settings['+f.key+']'" :value="$store.settings.cur(f)"></template>
                                        </div>
                                    </template>

                                    {{-- boolean toggle (always submitted) --}}
                                    <template x-if="f.control === 'bool'">
                                        <div class="flex items-center" style="gap:10px">
                                            <input type="hidden" :name="'settings['+f.key+']'" :value="$store.settings.cur(f) ? '1' : '0'">
                                            <span class="fz-mono" style="font-size:10.5px;letter-spacing:.06em;width:24px;text-align:right" :style="{ color: $store.settings.cur(f)?'#ffb3c4':'#8a9499' }" x-text="$store.settings.cur(f) ? 'ON' : 'OFF'"></span>
                                            <button type="button" @click="$store.settings.set(f, !$store.settings.cur(f))" style="position:relative;width:44px;height:24px;flex:0 0 44px;border-radius:20px;cursor:pointer;padding:0"
                                                    :style="{ border:'1px solid '+($store.settings.cur(f)?'rgba(255,217,218,.35)':'rgba(255,255,255,.12)'), background: $store.settings.cur(f)?'linear-gradient(145deg,#ea638c,#89023e)':'rgba(255,255,255,.06)' }">
                                                <span style="position:absolute;top:2px;width:18px;height:18px;border-radius:50%;transition:left .16s ease" :style="{ left: $store.settings.cur(f)?'22px':'2px', background: $store.settings.cur(f)?'#fff':'#8a9499' }"></span>
                                            </button>
                                        </div>
                                    </template>

                                    <span class="fz-mono" style="flex:0 0 auto;font-size:8.5px;letter-spacing:.08em;padding:3px 8px;border-radius:20px;width:84px;text-align:center"
                                          :style="{ background: f.chip.bg, border: '1px solid '+f.chip.bd, color: f.chip.fg }" x-text="f.chip.label"></span>
                                </div>
                            </div>
                        </template>
                    </section>
                </template>

                <template x-if="$store.settings.noResults">
                    <div class="fz-mono" style="border-radius:14px;border:1px dashed rgba(255,255,255,.12);padding:34px;text-align:center;font-size:11.5px;color:#8a9499">no settings match “<span x-text="$store.settings.query"></span>”</div>
                </template>

                <div class="fz-mono" style="font-size:10.5px;line-height:1.75;color:#7c868a;padding:2px 2px 0">Empty a field to revert it to its .env / default. Secrets are stored encrypted. Changes apply to the next research iteration — no restart.</div>

                {{-- sticky unsaved-changes bar --}}
                <template x-if="$store.settings.dirty">
                    <div style="position:sticky;bottom:0;left:0;right:0;z-index:5;padding:12px 0 4px;background:linear-gradient(180deg,rgba(16,20,22,0),rgba(16,20,22,.96) 42%);animation:barIn .2s ease both">
                        <div class="flex flex-wrap items-center" style="gap:14px;padding:11px 15px;border-radius:12px;border:1px solid rgba(242,182,97,.3);background:linear-gradient(90deg,rgba(242,182,97,.09),#1c2124);box-shadow:0 18px 40px -22px rgba(0,0,0,.9)">
                            <div style="width:7px;height:7px;border-radius:50%;background:#f2b661;animation:breathe 2s ease-in-out infinite"></div>
                            <span class="fz-mono" style="font-size:11.5px;color:#f5c987" x-text="$store.settings.dirtyLabel"></span>
                            <span class="fz-mono" style="font-size:10.5px;color:#8a9499;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="$store.settings.dirtyFields"></span>
                            <div class="flex" style="margin-left:auto;gap:8px;flex:0 0 auto">
                                <button type="button" @click="$store.settings.discard()" class="fz-mono" style="font-size:12px;letter-spacing:.03em;padding:8px 15px;border-radius:8px;border:1px solid rgba(255,255,255,.11);background:rgba(255,255,255,.03);color:#c8d0d3;cursor:pointer"
                                        onmouseover="this.style.background='rgba(255,255,255,.08)';this.style.color='#fff'" onmouseout="this.style.background='rgba(255,255,255,.03)';this.style.color='#c8d0d3'">DISCARD</button>
                                <button type="submit" class="fz-mono" style="font-size:12px;font-weight:600;letter-spacing:.03em;padding:8px 17px;border-radius:8px;border:1px solid rgba(255,217,218,.35);background:linear-gradient(145deg,#ea638c,#89023e);color:#fff;cursor:pointer;box-shadow:0 10px 26px -14px rgba(234,99,140,.9)"
                                        onmouseover="this.style.filter='brightness(1.12)'" onmouseout="this.style.filter='none'">SAVE CHANGES</button>
                            </div>
                        </div>
                    </div>
                </template>
              </form>

              {{-- ── Gateway & Tools (operational, non-editable) ────────────── --}}
              @include('dashboard.partials.tools')
            </div>
        </div>
</div>

@push('scripts')
<script>
const CHIP = {
    DEFAULT:    { label:'DEFAULT',    bg:'rgba(255,255,255,.04)', fg:'#8a9499', bd:'rgba(255,255,255,.1)' },
    OVERRIDDEN: { label:'OVERRIDDEN', bg:'rgba(234,99,140,.13)', fg:'#ffb3c4', bd:'rgba(234,99,140,.32)' },
    EDITED:     { label:'EDITED',     bg:'rgba(242,182,97,.14)', fg:'#f5c987', bd:'rgba(242,182,97,.35)' },
};

document.addEventListener('alpine:init', () => {
    const data = @json($payload);
    const ALL = data.groups.flatMap(g => g.fields);

    Alpine.store('settings', {
        query: '',
        active: data.groups[0]?.id || '',
        edits: {},

        // ── per-field value helpers ─────────────────────────────────────────
        cur(f) { return Object.prototype.hasOwnProperty.call(this.edits, f.key) ? this.edits[f.key] : f.value; },
        edited(key) { const v = this.edits[key]; return Object.prototype.hasOwnProperty.call(this.edits, key) && !(ALL.find(x=>x.key===key)?.type==='secret' && !v); },
        set(f, v) {
            const same = f.type === 'bool' ? (!!f.value === !!v) : (String(f.value) === String(v));
            if (same) delete this.edits[f.key]; else this.edits[f.key] = v;
        },
        discard() { this.edits = {}; },

        // ── dirty state ─────────────────────────────────────────────────────
        get dirtyKeys() { return Object.keys(this.edits).filter(k => this.edited(k)); },
        get dirty() { return this.dirtyKeys.length > 0; },
        get dirtyCount() { return this.dirtyKeys.length; },
        get overrideCount() { return ALL.filter(f => f.state === 'override').length; },
        get dirtyLabel() { const n = this.dirtyCount; return n + ' unsaved change' + (n === 1 ? '' : 's'); },
        get dirtyFields() { return this.dirtyKeys.map(k => (ALL.find(f => f.key === k) || {}).label).filter(Boolean).join(' · '); },

        _match(f) {
            const q = this.query.trim().toLowerCase();
            return !q || (f.label + ' ' + f.help + ' ' + f.key).toLowerCase().indexOf(q) >= 0;
        },
        _fieldProps(f) {
            const isBool = f.type === 'bool', isSecret = f.type === 'secret';
            // A short option list normally becomes a segmented row, but a field
            // that asked for `ui: select` (the gateway model lists) always stays a
            // dropdown — long names don't fit buttons, and a blank option would
            // render as an invisible one.
            const isSeg = f.type === 'select' && f.ui !== 'select' && f.options.length <= 3;
            const isSelect = f.type === 'select' && !isSeg;
            const control = isBool ? 'bool' : isSecret ? 'secret' : isSeg ? 'seg' : isSelect ? 'select' : 'input';
            const edited = this.edited(f.key);
            const chip = edited ? CHIP.EDITED : f.state === 'override' ? CHIP.OVERRIDDEN : CHIP.DEFAULT;
            return { ...f, control, edited, chip };
        },
        get groups() {
            return data.groups.map(g => {
                const fields = g.fields.filter(f => this._match(f)).map(f => this._fieldProps(f));
                const dirtyHere = fields.filter(f => f.edited).length;
                const overHere = fields.filter(f => f.state === 'override' && !f.edited).length;
                return {
                    id: g.id, title: g.title, note: g.note,
                    titleFg: dirtyHere ? '#f5c987' : '#c8d0d3',
                    bd: dirtyHere ? 'rgba(242,182,97,.26)' : 'rgba(255,255,255,.07)',
                    countLabel: fields.length + ' field' + (fields.length === 1 ? '' : 's') + (overHere ? ' · ' + overHere + ' overridden' : ''),
                    fields,
                };
            }).filter(g => g.fields.length > 0);
        },
        get noResults() { return this.groups.length === 0; },
        get nav() {
            return data.groups.map(g => {
                const dirtyHere = g.fields.filter(f => this.edited(f.key)).length;
                return {
                    id: g.id, label: g.nav, on: this.active === g.id, dirty: dirtyHere > 0,
                    count: dirtyHere ? dirtyHere + ' ●' : String(g.fields.length),
                };
            });
        },
    });
});
</script>
@endpush
@endsection
