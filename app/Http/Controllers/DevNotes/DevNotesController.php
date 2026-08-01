<?php

namespace App\Http\Controllers\DevNotes;

use App\Http\Controllers\Controller;
use App\Models\DevNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DEV NOTES MODULE — removable side module.
 *
 * Backs the corner popup note-taker. Plain JSON CRUD used by the Alpine widget
 * in resources/views/devnotes/widget.blade.php. To remove the module, delete
 * this controller and the other files/blocks marked "DEV NOTES MODULE".
 */
class DevNotesController extends Controller
{
    /** All notes, open ones first, newest first. */
    public function index(): JsonResponse
    {
        return response()->json(
            DevNote::orderBy('done')->latest()->get()
        );
    }

    /** Create a note. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        return response()->json(DevNote::create($data), 201);
    }

    /** Toggle done / edit body. */
    public function update(Request $request, DevNote $devNote): JsonResponse
    {
        $data = $request->validate([
            'body' => ['sometimes', 'string', 'max:5000'],
            'done' => ['sometimes', 'boolean'],
        ]);

        $devNote->update($data);

        return response()->json($devNote);
    }

    /** Delete a note. */
    public function destroy(DevNote $devNote): JsonResponse
    {
        $devNote->delete();

        return response()->json(['ok' => true]);
    }
}
