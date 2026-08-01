{{--
    ── DEV NOTES MODULE ──────────────────────────────────────────────────────────
    Corner popup note-taker for jotting ideas / fixes during development.
    Self-contained Alpine component; talks to /ui/api/dev-notes (see routes/web.php).
    Removable: delete this file, the @include in layout.blade.php, the route block
    in web.php, the DevNotesController, the DevNote model, and the dev_notes migration.
--}}
<div x-data="devNotes()" x-init="load()" x-cloak
     class="fixed bottom-4 right-4 z-50 font-sans text-sm">

    {{-- Panel --}}
    <div x-show="open" x-transition
         class="mb-3 w-80 max-h-[70vh] flex flex-col rounded-xl border border-gray-700 bg-[#16191b] shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-800 px-4 py-3">
            <div class="flex items-center gap-2">
                <span>🗒️</span>
                <span class="font-semibold text-gray-200">Dev Notes</span>
                <span class="rounded-full bg-gray-800 px-2 py-0.5 text-xs text-gray-400" x-text="openCount + ' open'"></span>
            </div>
            <button @click="open = false" class="text-gray-500 hover:text-gray-300">✕</button>
        </div>

        {{-- New note --}}
        <form @submit.prevent="add()" class="border-b border-gray-800 p-3">
            <textarea x-model="draft" rows="2" placeholder="Idea, bug, or fix…"
                      @keydown.meta.enter="add()" @keydown.ctrl.enter="add()"
                      class="w-full resize-none rounded-lg border border-gray-700 bg-[#1b2021] px-3 py-2 text-gray-200 placeholder-gray-600 focus:border-indigo-500 focus:outline-none"></textarea>
            <div class="mt-2 flex items-center justify-between">
                <span class="text-xs text-gray-600">⌘/Ctrl + Enter</span>
                <button type="submit" :disabled="!draft.trim()"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-40">
                    Add
                </button>
            </div>
        </form>

        {{-- List --}}
        <div class="flex-1 overflow-y-auto p-2">
            <template x-if="notes.length === 0">
                <p class="px-2 py-6 text-center text-xs text-gray-600">No notes yet.</p>
            </template>
            <template x-for="note in notes" :key="note.id">
                <div class="group flex items-start gap-2 rounded-lg px-2 py-2 hover:bg-gray-800/60">
                    <input type="checkbox" :checked="note.done" @change="toggle(note)"
                           class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer accent-indigo-500">

                    {{-- Display mode --}}
                    <p x-show="editingId !== note.id"
                       @dblclick="startEdit(note)"
                       title="Double-click to edit"
                       class="flex-1 cursor-text whitespace-pre-wrap break-words leading-snug"
                       :class="note.done ? 'text-gray-600 line-through' : 'text-gray-200'"
                       x-text="note.body"></p>

                    {{-- Edit mode (x-if so the textarea only exists — and is measurable — while visible) --}}
                    <template x-if="editingId === note.id">
                        <div class="flex-1">
                            <textarea x-model="editDraft" rows="1"
                                      x-init="$nextTick(() => { autoGrow($el); $el.focus(); $el.setSelectionRange($el.value.length, $el.value.length); })"
                                      @input="autoGrow($event.target)"
                                      @keydown.meta.enter="saveEdit(note)" @keydown.ctrl.enter="saveEdit(note)"
                                      @keydown.escape="cancelEdit()"
                                      class="w-full resize-y overflow-y-auto rounded-lg border border-indigo-500 bg-[#1b2021] px-2 py-1 text-gray-200 focus:outline-none"></textarea>
                            <div class="mt-1 flex items-center gap-2 text-xs">
                                <button @click="saveEdit(note)" class="rounded bg-indigo-600 px-2 py-1 font-medium text-white hover:bg-indigo-500">Save</button>
                                <button @click="cancelEdit()" class="text-gray-500 hover:text-gray-300">Cancel</button>
                            </div>
                        </div>
                    </template>

                    <div x-show="editingId !== note.id" class="flex shrink-0 gap-1 opacity-0 transition group-hover:opacity-100">
                        <button @click="copy(note)" :title="copiedId === note.id ? 'Copied!' : 'Copy to clipboard'"
                                class="text-gray-600 hover:text-indigo-300"
                                x-text="copiedId === note.id ? '✓' : '⧉'"></button>
                        <button @click="startEdit(note)" title="Edit" class="text-gray-600 hover:text-indigo-300">✎</button>
                        <button @click="remove(note)" title="Delete" class="text-gray-600 hover:text-red-400">🗑</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- Toggle button --}}
    <button @click="open = !open"
            class="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-600 text-xl shadow-2xl transition hover:bg-indigo-500">
        <span x-show="!open">🗒️</span>
        <span x-show="open">✕</span>
        <span x-show="!open && openCount > 0"
              class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-fuchsia-500 px-1 text-xs font-bold text-white"
              x-text="openCount"></span>
    </button>
</div>

@push('scripts')
<script>
    function devNotes() {
        const base = '{{ url('ui/api/dev-notes') }}';
        return {
            open: false,
            notes: [],
            draft: '',
            editingId: null,
            editDraft: '',
            copiedId: null,
            get openCount() { return this.notes.filter(n => !n.done).length; },

            async copy(note) {
                try {
                    if (navigator.clipboard?.writeText) {
                        await navigator.clipboard.writeText(note.body);
                    } else {
                        // Fallback for non-secure contexts (plain http).
                        const ta = document.createElement('textarea');
                        ta.value = note.body;
                        ta.style.position = 'fixed';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        ta.remove();
                    }
                    this.copiedId = note.id;
                    setTimeout(() => { if (this.copiedId === note.id) this.copiedId = null; }, 1500);
                } catch (e) { /* clipboard blocked — ignore */ }
            },

            autoGrow(el) {
                if (!el) return;
                el.style.height = 'auto';
                // Cap so a huge note doesn't overflow the panel; then it scrolls.
                el.style.height = Math.min(el.scrollHeight, 240) + 'px';
            },
            startEdit(note) {
                this.editingId = note.id;
                this.editDraft = note.body;
                // Sizing + focus happens in the textarea's x-init (it's freshly
                // rendered and visible via x-if, so scrollHeight is accurate).
            },
            cancelEdit() {
                this.editingId = null;
                this.editDraft = '';
            },
            async saveEdit(note) {
                const body = this.editDraft.trim();
                if (!body || body === note.body) { this.cancelEdit(); return; }
                note.body = body;
                this.cancelEdit();
                await fetch(`${base}/${note.id}`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrf, Accept: 'application/json' },
                    body: JSON.stringify({ body }),
                });
            },

            async load() {
                try {
                    const r = await fetch(base, { headers: { Accept: 'application/json' } });
                    this.notes = await r.json();
                } catch (e) { /* silent — module is optional */ }
            },
            async add() {
                const body = this.draft.trim();
                if (!body) return;
                this.draft = '';
                const note = await window.postJson(base, { body });
                this.notes.unshift(note);
            },
            async toggle(note) {
                note.done = !note.done;
                await fetch(`${base}/${note.id}`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrf, Accept: 'application/json' },
                    body: JSON.stringify({ done: note.done }),
                });
                // Keep open notes on top, newest first.
                this.notes.sort((a, b) => (a.done - b.done) || (b.id - a.id));
            },
            async remove(note) {
                await window.deleteJson(`${base}/${note.id}`);
                this.notes = this.notes.filter(n => n.id !== note.id);
            },
        };
    }
</script>
@endpush
