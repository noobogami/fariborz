<?php

namespace App\Domain\Research\Contracts;

/**
 * Marker for SUPERVISOR-only control tools (plan_tasks, delegate_task,
 * review_task). Supervisors see ONLY these; solo/worker jobs never see them.
 * Partitioning by this marker keeps role-gating in one place (ToolRegistry).
 */
interface ControlTool extends Tool {}
