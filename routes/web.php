<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\HumanController;
use App\Http\Controllers\Dashboard\OllamaController;
use App\Http\Controllers\Dashboard\SandboxController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\DevNotes\DevNotesController;
use Illuminate\Support\Facades\Route;

// ── Dashboard pages ──────────────────────────────────────────────────────────
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/jobs/{id}', [DashboardController::class, 'show'])->name('jobs.show');
Route::get('/humans', [HumanController::class, 'index'])->name('humans');
Route::get('/tools', [OllamaController::class, 'index'])->name('tools');
Route::get('/sandbox', [SandboxController::class, 'index'])->name('sandbox');
Route::post('/sandbox/kill', [SandboxController::class, 'kill'])->name('sandbox.kill');
Route::post('/sandbox/exec', [SandboxController::class, 'exec'])->name('sandbox.exec');
Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');

// ── Mutations (plain forms, CSRF-protected, redirect back) ───────────────────
Route::post('/jobs', [DashboardController::class, 'store'])->name('jobs.store');
Route::post('/jobs/{id}/cancel', [DashboardController::class, 'cancel'])->name('jobs.cancel');
Route::post('/jobs/{id}/continue', [DashboardController::class, 'continueJob'])->name('jobs.continue');
Route::post('/jobs/{id}/retry', [DashboardController::class, 'retry'])->name('jobs.retry');
Route::delete('/jobs/{id}', [DashboardController::class, 'destroy'])->name('jobs.destroy');
Route::post('/humans/{id}/status', [HumanController::class, 'updateStatus'])->name('humans.status');
Route::post('/questions/{id}/answer', [HumanController::class, 'answer'])->name('questions.answer');
Route::post('/tools/ollama/pull', [OllamaController::class, 'pull'])->name('ollama.pull');
Route::post('/tools/browser/test', [OllamaController::class, 'browserTest'])->name('browser.test');
Route::post('/tools/skills/{id}/promote', [OllamaController::class, 'promoteSkill'])->name('skills.promote');
Route::delete('/tools/skills/{id}', [OllamaController::class, 'deleteSkill'])->name('skills.delete');

// ── JSON endpoints used by the UI for live polling ───────────────────────────
Route::prefix('ui/api')->group(function () {
    Route::get('/jobs', [DashboardController::class, 'jobsJson'])->name('ui.jobs');
    Route::get('/jobs/{id}', [DashboardController::class, 'jobJson'])->name('ui.job');
    Route::get('/events/{id}', [DashboardController::class, 'eventJson'])->name('ui.event');
    Route::get('/ollama/status', [OllamaController::class, 'status'])->name('ui.ollama.status');
    Route::get('/sandbox/processes', [SandboxController::class, 'processesJson'])->name('ui.sandbox.processes');
});

// ── BEGIN DEV NOTES MODULE (removable side module) ───────────────────────────
Route::prefix('ui/api')->group(function () {
    Route::get('/dev-notes', [DevNotesController::class, 'index'])->name('ui.dev-notes.index');
    Route::post('/dev-notes', [DevNotesController::class, 'store'])->name('ui.dev-notes.store');
    Route::patch('/dev-notes/{devNote}', [DevNotesController::class, 'update'])->name('ui.dev-notes.update');
    Route::delete('/dev-notes/{devNote}', [DevNotesController::class, 'destroy'])->name('ui.dev-notes.destroy');
});
// ── END DEV NOTES MODULE ─────────────────────────────────────────────────────
