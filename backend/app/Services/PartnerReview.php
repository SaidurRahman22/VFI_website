<?php

namespace App\Services;

use App\Enums\AgencyStatus;
use App\Enums\ApplicationReviewStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Enums\UserStatus;
use App\Mail\PartnerDecisionMail;
use App\Models\AuthEvent;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Partner\PartnerApplication;
use App\Models\User;
use App\Models\UserRole;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Phase 6F — staff review workflow (docs §7). Approval is the ONLY path that
 * mints a live tenant; it does so in one transaction. Rejection / more-info
 * never touch partner_agencies. Suspend/close flip the status (consumed by the
 * sign-in gate) and revoke every member session.
 */
class PartnerReview
{
    /**
     * Approve → create exactly one agency + one owner seat + the partner_owner
     * role, mark the application approved, audit, and email the applicant.
     */
    public function approve(PartnerApplication $application, User $staff): PartnerAgency
    {
        if ($application->review_status === ApplicationReviewStatus::Approved && $application->agency_id) {
            return $application->agency;   // idempotent
        }

        return DB::transaction(function () use ($application, $staff) {
            $agency = PartnerAgency::create([
                'legal_name' => $application->agency_name,
                'country' => $application->country,
                'city' => $application->city,
                'status' => AgencyStatus::Approved->value,
                'approved_by_user_id' => $staff->id,
                'approved_at' => now(),
            ]);

            // Bind the new tenant so the member insert satisfies both nets
            // (BelongsToAgency stamp + Postgres RLS WITH CHECK).
            app(TenantContext::class)->setAgencyId($agency->id);
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL app.agency_id = '".$agency->id."'");
            }

            PartnerAgencyMember::create([
                'agency_id' => $agency->id,
                'user_id' => $application->user_id,
                'seat_role' => SeatRole::Owner,
                'contact_person_name' => $application->contact_person,
                'work_email' => $application->work_email,
                'phone_cc' => $application->phone_cc,
                'phone_national' => $application->phone_national,
                'accepted_at' => now(),
                'status' => MemberStatus::Active,
            ]);

            UserRole::create([
                'user_id' => $application->user_id,
                'role' => Role::PartnerOwner,
                'agency_id' => $agency->id,
                'granted_at' => now(),
            ]);

            User::whereKey($application->user_id)->update(['status' => UserStatus::Active->value]);

            $application->update([
                'review_status' => ApplicationReviewStatus::Approved,
                'reviewed_by_user_id' => $staff->id,
                'reviewed_at' => now(),
                'agency_id' => $agency->id,
            ]);

            AuthEvent::record('agency_approved', [
                'user_id' => $application->user_id, 'email' => $application->work_email,
                'context' => ['agency_id' => $agency->id, 'by' => $staff->id],
            ]);
            Mail::to($application->work_email)->send(new PartnerDecisionMail('approved', $agency->legal_name));

            app(TenantContext::class)->clear();

            return $agency;
        });
    }

    public function reject(PartnerApplication $application, User $staff, string $reason): void
    {
        $application->update([
            'review_status' => ApplicationReviewStatus::Rejected,
            'reviewed_by_user_id' => $staff->id,
            'reviewed_at' => now(),
            'review_notes' => $reason,
        ]);
        AuthEvent::record('agency_rejected', ['user_id' => $application->user_id, 'email' => $application->work_email, 'context' => ['by' => $staff->id]]);
        Mail::to($application->work_email)->send(new PartnerDecisionMail('rejected', $reason));
        // NO tenant is created.
    }

    public function requestMoreInfo(PartnerApplication $application, User $staff, string $notes): void
    {
        $application->update([
            'review_status' => ApplicationReviewStatus::MoreInfo,
            'reviewed_by_user_id' => $staff->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
        Mail::to($application->work_email)->send(new PartnerDecisionMail('more_info', $notes));
    }

    /** Flip an agency's status; suspend/close revoke every member session. */
    public function setAgencyStatus(PartnerAgency $agency, AgencyStatus $status): void
    {
        $agency->update(['status' => $status->value]);

        if (in_array($status, [AgencyStatus::Suspended, AgencyStatus::Closed], true)) {
            $userIds = PartnerAgencyMember::withoutGlobalScope(BelongsToAgencyScope::class)
                ->where('agency_id', $agency->id)->pluck('user_id');
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            User::whereIn('id', $userIds)->update(['remember_token' => Str::random(60)]);
        }
    }
}
