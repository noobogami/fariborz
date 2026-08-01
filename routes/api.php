<?php

use App\Http\Controllers\HumanInteractionController;
use App\Http\Controllers\ResearchJobController;
use Illuminate\Support\Facades\Route;

// ── Research jobs ────────────────────────────────────────────────────────────
Route::post('/research', [ResearchJobController::class, 'store']);
Route::get('/research/{id}', [ResearchJobController::class, 'show'])->name('research.show');
Route::post('/research/{id}/cancel', [ResearchJobController::class, 'cancel']);

// ── Human interaction (async) ────────────────────────────────────────────────
Route::post('/humans/{human}/status', [HumanInteractionController::class, 'updateStatus']);
Route::post('/questions/{question}/answer', [HumanInteractionController::class, 'answer']);
