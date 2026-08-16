<?php

namespace App\Services;

use App\Contracts\WalletGateway;
use App\Enums\ActorType;
use App\Enums\ApplicationStatus;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationStatusEvent;
use App\Models\Partner\PartnerNotification;
use App\Models\Student\Student;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 — the applications pipeline. Create writes the row + exactly ONE
 * append-only status event. transition() is the single mutation path (reused by
 * the Phase 9 staff back-office) — every status move writes exactly one event so
 * the pipeline history is complete and the KPI aggregates stay derivable.
 */
class PipelineService
{
    public function __construct(private readonly WalletGateway $wallet) {}

    /** Create an application at `submitted` with its first pipeline event. */
    public function create(Student $student, array $attrs, ?int $actorId): Application
    {
        return DB::transaction(function () use ($student, $attrs, $actorId) {
            $app = Application::create([
                'agency_id' => app(TenantContext::class)->agencyId(),
                'student_id' => $student->id,
                'program_id' => $attrs['program_id'] ?? null,
                'institution_id' => $attrs['institution_id'] ?? null,
                'intake_month' => $attrs['intake_month'] ?? $student->intake_month,
                'intake_year' => $attrs['intake_year'] ?? $student->intake_year,
                'ack_no' => $attrs['ack_no'] ?? null,
                'deadline_at' => $attrs['deadline_at'] ?? null,
                'status' => ApplicationStatus::Submitted->value,
                'submitted_at' => now(),
            ]);

            $this->writeEvent($app, null, ApplicationStatus::Submitted, ActorType::Partner, $actorId, 'Application created');

            $name = trim(($student->first_name ?? '').' '.($student->last_name ?? '')) ?: ($student->email ?? 'a student');
            PartnerNotification::create([
                'agency_id' => $app->agency_id, 'kind' => 'application',
                'title' => 'Application submitted', 'body' => "A new application was created for {$name}.",
                'link' => 'partner-applications.html',
            ]);

            // The application-fee debit. Atomic with the row above by design: the
            // invariant is "no debit without an application, no application
            // without its debit", so this must stay INSIDE this transaction.
            // Today it resolves to NullWalletGateway and does nothing; Phase 9
            // swaps the binding for the real ledger and this line is unchanged.
            // A throw here rolls the whole submission back, which is the correct
            // outcome for insufficient funds or a frozen wallet.
            $this->wallet->debitApplicationFee($app, (int) $app->agency_id, 'app-fee:'.$app->id);

            return $app;
        });
    }

    /** Move an application's status, writing exactly one append-only event. */
    public function transition(Application $app, ApplicationStatus $to, ActorType $actorType, ?int $actorId, ?string $note = null): Application
    {
        return DB::transaction(function () use ($app, $to, $actorType, $actorId, $note) {
            $from = $app->status;
            $app->update(['status' => $to->value]);
            $this->writeEvent($app, $from, $to, $actorType, $actorId, $note);

            return $app->refresh();
        });
    }

    private function writeEvent(Application $app, ?ApplicationStatus $from, ApplicationStatus $to, ActorType $actorType, ?int $actorId, ?string $note): void
    {
        ApplicationStatusEvent::create([
            'application_id' => $app->id,
            'agency_id' => $app->agency_id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'occurred_at' => now(),
            'actor_type' => $actorType->value,
            'actor_id' => $actorId,
            'note' => $note,
        ]);
    }
}
