<?php

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\ApplicationStatus;
use App\Models\ContentAuditLog;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationNote;
use App\Models\Partner\PartnerNotification;
use App\Models\User;
use App\Support\TenantScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9A slice 2 — the staff side of the applications pipeline.
 *
 * Phase 7 built PipelineService::transition() as a clean low-level write (status
 * + exactly one append-only event) but gave it no caller: no route, no screen,
 * so no one could actually move an application. This is that caller, plus the
 * things a low-level primitive should not decide:
 *
 *   - a transition MAP, so staff cannot jump submitted → visa_received;
 *   - a REASON required on the outcomes that end or stall a case;
 *   - the owning agency is NOTIFIED (written as that tenant — see TenantScope);
 *   - every move is audited with before/after.
 *
 * Counsellor notes live in application_notes and are STAFF-INTERNAL: never
 * serialised to a partner or student response (settled with the client).
 */
class ApplicationReviewService
{
    /** Which statuses a case may legally move to next. */
    private const MAP = [
        'submitted' => ['review', 'pending_from_partner', 'non_enrolment'],
        'review' => ['offer', 'conditional', 'pending_from_partner', 'deferral', 'non_enrolment'],
        'conditional' => ['offer', 'pending_from_partner', 'deferral', 'non_enrolment'],
        'offer' => ['payment', 'conditional', 'deferral', 'non_enrolment'],
        'payment' => ['visa_received', 'visa_rejected', 'deferral', 'non_enrolment'],
        'visa_received' => ['deferral', 'non_enrolment'],
        'visa_rejected' => ['deferral', 'payment', 'non_enrolment'],
        'pending_from_partner' => ['review', 'deferral', 'non_enrolment'],
        'deferral' => ['review', 'offer', 'non_enrolment'],
        // not terminal on purpose: a withdrawn case is sometimes revived, and
        // stranding it would push staff into editing the database by hand.
        'non_enrolment' => ['review'],
    ];

    /** Outcomes that stall or end a case must carry an explanation. */
    private const REASON_REQUIRED = ['non_enrolment', 'visa_rejected', 'pending_from_partner', 'deferral'];

    public function __construct(private readonly PipelineService $pipeline) {}

    /** @return list<ApplicationStatus> */
    public function allowedNextStatuses(ApplicationStatus $from): array
    {
        return array_values(array_filter(array_map(
            fn (string $v) => ApplicationStatus::tryFrom($v),
            self::MAP[$from->value] ?? [],
        )));
    }

    public function canTransition(ApplicationStatus $from, ApplicationStatus $to): bool
    {
        return in_array($to->value, self::MAP[$from->value] ?? [], true);
    }

    /** Move a case, as VFI staff. Returns the refreshed application. */
    public function transition(Application $app, ApplicationStatus $to, User $staff, ?string $reason = null): Application
    {
        $from = $app->status;

        if ($from === $to) {
            throw new RuntimeException('This application is already '.$to->value.'.');
        }
        if (! $this->canTransition($from, $to)) {
            throw new RuntimeException("A case cannot move from {$from->value} to {$to->value}.");
        }
        $reason = $reason !== null ? trim($reason) : null;
        if (in_array($to->value, self::REASON_REQUIRED, true) && blank($reason)) {
            throw new RuntimeException('Moving a case to '.$to->value.' needs a reason.');
        }

        // Everything below writes into RLS-protected, agency-owned tables
        // (applications, application_status_events, partner_notifications) whose
        // WITH CHECK carries no bypass by design. Staff hold no tenant, so the
        // whole unit of work adopts the owning agency — without this the UPDATE
        // silently matches zero rows and the event INSERT is rejected outright,
        // and neither shows up on SQLite where these tests run.
        return TenantScope::runAs((int) $app->agency_id, fn () => DB::transaction(function () use ($app, $from, $to, $staff, $reason) {
            // The write itself reuses the Phase 7 primitive, so there is still
            // exactly ONE code path producing status events.
            $updated = $this->pipeline->transition($app, $to, ActorType::Staff, $staff->id, $reason);

            ContentAuditLog::record('application_transition', 'application', (string) $app->id,
                ['status' => $from->value],
                ['status' => $to->value, 'reason' => $reason, 'actor_user_id' => $staff->id],
            );

            // Tell the owning agency — already inside runAs, so the tenant that
            // partner_notifications' WITH CHECK demands is bound.
            PartnerNotification::create([
                'agency_id' => $app->agency_id,
                'kind' => 'application',
                'title' => 'Application updated',
                'body' => 'An application moved to '.str_replace('_', ' ', $to->value).'.'
                    .($reason ? ' '.$reason : ''),
                'link' => 'partner-applications.html',
            ]);

            return $updated;
        }));
    }

    /** Add a staff-internal note. Append-only — corrections are new notes. */
    public function addNote(Application $app, User $staff, string $body): ApplicationNote
    {
        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('A note cannot be empty.');
        }

        return ApplicationNote::create([
            'application_id' => $app->id,
            'author_user_id' => $staff->id,
            'author_name' => $staff->name ?: $staff->email,
            'body' => mb_substr($body, 0, 5000),
            'created_at' => now(),
        ]);
    }
}
