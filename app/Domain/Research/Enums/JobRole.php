<?php

namespace App\Domain\Research\Enums;

/**
 * Where a job sits in the hierarchy.
 *   Solo       — a standalone agent loop (the original behaviour).
 *   Supervisor — decomposes a goal into tasks and delegates each to a worker.
 *   Worker     — a focused sub-agent that does ONE task and reports back.
 *   Reviewer   — a focused sub-agent that JUDGES one finished task (a structured
 *                accept/revise verdict, not an artifact) in its own fresh, tiny
 *                context, so reviewing parallelizes and never bloats the
 *                supervisor's transcript. See SubmitReviewTool.
 */
enum JobRole: string
{
    case Solo = 'solo';
    case Supervisor = 'supervisor';
    case Worker = 'worker';
    case Reviewer = 'reviewer';
}
