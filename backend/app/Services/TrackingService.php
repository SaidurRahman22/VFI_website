<?php

namespace App\Services;

use App\Models\Student\Student;

/**
 * Phase 5E — read-only application tracking (docs §4). Serves ENUMS, not
 * presentation (the client maps status/tone/state to chips/icons as it does
 * today). Derived values are computed server-side so they never go stale:
 * journey % = (done + 0.5·now)/total, per-status counts, and `late` derived
 * from a real due date (the JS used a hardcoded boolean).
 */
class TrackingService
{
    /** The fixed six-stage journey every student walks (default = all todo). */
    private const DEFAULT_STAGES = ['Counselling', 'Documents', 'Application Sent', 'Offer Received', 'Visa', 'Departure'];

    private const APP_STATUSES = ['submitted', 'review', 'offer', 'conditional', 'rejected', 'enrolled'];

    public function forStudent(Student $student): array
    {
        $student->loadMissing(['journeyStages', 'applications', 'timeline', 'actions']);

        // ---- journey ----
        $stages = $student->journeyStages;
        if ($stages->isEmpty()) {
            $stageOut = array_map(fn ($n) => ['name' => $n, 'state' => 'todo', 'when' => null], self::DEFAULT_STAGES);
            $done = 0;
            $now = 0;
            $total = count(self::DEFAULT_STAGES);
        } else {
            $stageOut = $stages->map(fn ($s) => ['name' => $s->name, 'state' => $s->state, 'when' => $s->when_label])->all();
            $done = $stages->where('state', 'done')->count();
            $now = $stages->where('state', 'now')->count();
            $total = $stages->count();
        }
        $pct = $total ? (int) round(($done + 0.5 * $now) / $total * 100) : 0;

        // ---- applications + per-status counts ----
        $applications = $student->applications->map(fn ($a) => [
            'uni' => $a->university, 'place' => $a->place, 'course' => $a->course,
            'intake' => $a->intake, 'sent' => optional($a->sent_on)->format('d M Y') ?? '',
            'status' => $a->status, 'pct' => (int) $a->pct, 'stage' => $a->stage, 'note' => $a->note,
        ])->all();

        $counts = ['all' => count($applications)];
        foreach (self::APP_STATUSES as $s) {
            $counts[$s] = $student->applications->where('status', $s)->count();
        }

        // ---- timeline ----
        $timeline = $student->timeline->map(fn ($e) => [
            'date' => optional($e->occurred_on)->format('d M Y') ?? '',
            'tone' => $e->tone, 'icon' => $e->icon, 'title' => $e->title, 'text' => $e->body,
        ])->all();

        // ---- actions (late derived from due_at) ----
        $actions = $student->actions->map(function ($t) {
            $late = $t->isOverdue();
            $due = $t->due_at
                ? ($late ? 'Overdue since '.$t->due_at->format('d M Y') : 'Due '.$t->due_at->format('d M Y'))
                : '';

            return ['icon' => $t->icon, 'title' => $t->title, 'text' => $t->body, 'due' => $due, 'late' => $late];
        })->all();

        return [
            'journey' => ['pct' => $pct, 'stages' => $stageOut],
            'applications' => $applications,
            'counts' => $counts,
            'timeline' => $timeline,
            'actions' => $actions,
            'overdue_count' => collect($actions)->where('late', true)->count(),
        ];
    }
}
