<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\HumanController;
use App\Http\Controllers\Dashboard\SandboxController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\ToolsController;
use App\Http\Controllers\DevNotes\DevNotesController;
use Illuminate\Support\Facades\Route;

// ── Dashboard pages ──────────────────────────────────────────────────────────
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/jobs/{id}', [DashboardController::class, 'show'])->name('jobs.show');
Route::get('/humans', [HumanController::class, 'index'])->name('humans');
// Tools & Gateway moved under Settings; keep the old URL working as a redirect
// straight to the Tools section anchor.
Route::get('/tools', fn () => redirect()->to(route('settings').'#tools'))->name('tools');
Route::get('/sandbox', [SandboxController::class, 'index'])->name('sandbox');
// Static view of a workspace: /sandbox/preview/<workspace-slug>/ renders its
// index.html (and relative assets) with no server for the agent to write.
Route::get('/sandbox/preview/{job}/{path?}', [SandboxController::class, 'preview'])
    ->where(['job' => '[A-Za-z0-9._-]+', 'path' => '.*'])
    ->name('sandbox.preview');
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
Route::post('/tools/browser/test', [ToolsController::class, 'browserTest'])->name('browser.test');
Route::post('/tools/skills/{id}/promote', [ToolsController::class, 'promoteSkill'])->name('skills.promote');
Route::delete('/tools/skills/{id}', [ToolsController::class, 'deleteSkill'])->name('skills.delete');
Route::post('/tools/gateway/models', [ToolsController::class, 'gatewayCreate'])->name('gateway.models.create');
Route::post('/tools/gateway/models/remove', [ToolsController::class, 'gatewayDelete'])->name('gateway.models.delete');
Route::post('/tools/gateway/health', [ToolsController::class, 'gatewayHealth'])->name('gateway.health');

// ── JSON endpoints used by the UI for live polling ───────────────────────────
Route::prefix('ui/api')->group(function () {
    Route::get('/jobs', [DashboardController::class, 'jobsJson'])->name('ui.jobs');
    Route::get('/jobs/{id}', [DashboardController::class, 'jobJson'])->name('ui.job');
    Route::get('/events/{id}', [DashboardController::class, 'eventJson'])->name('ui.event');
    Route::get('/tools/status', [ToolsController::class, 'status'])->name('ui.tools.status');
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
