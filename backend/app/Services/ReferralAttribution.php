<?php

namespace App\Services;

use App\Enums\StudentSource;
use App\Models\Partner\AgencyReferralLink;
use App\Models\Partner\ReferralSignup;
use App\Models\Student\Student;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 — QR referral attribution (docs §6). The rules settled at P6 sign-off:
 *   - attribution only COUNTS after email verification (anti-farming);
 *   - a QR may claim an UNOWNED (self-signup) student, but never re-parent one
 *     already owned by another agency (first-agency-wins).
 *
 * capture() records a pending signup at registration; convertForUser() runs on
 * OTP verify and is the moment ownership + the counted signup are set.
 */
class ReferralAttribution
{
    /** Resolve a public ref slug to an ACTIVE link, or null. */
    public function resolveLink(?string $ref): ?AgencyReferralLink
    {
        if (! $ref) {
            return null;
        }
        $link = AgencyReferralLink::withoutGlobalScopes()->where('slug', $ref)->first();

        return $link && $link->isActive() ? $link : null;
    }

    /** Record a PENDING signup at registration (no ownership/attribution yet). */
    public function capture(?AgencyReferralLink $link, Student $student): void
    {
        if (! $link) {
            return;
        }
        // Never attribute a student already owned by a DIFFERENT agency.
        if ($student->agency_id !== null && $student->agency_id !== $link->agency_id) {
            return;
        }

        $this->asAgency($link->agency_id, function () use ($link, $student) {
            if (ReferralSignup::where('student_id', $student->id)->whereNull('converted_at')->exists()) {
                return;   // one pending signup per student
            }
            ReferralSignup::create([
                'referral_link_id' => $link->id, 'student_id' => $student->id,
                'ref_code_seen' => $link->slug, 'landed_at' => now(), 'channel' => 'qr',
            ]);
        });
    }

    /** On email verification: set ownership + count the signup (attribution). */
    public function convertForUser(User $user): void
    {
        $student = Student::where('user_id', $user->id)->first();
        if (! $student) {
            return;
        }

        $signup = ReferralSignup::withoutGlobalScopes()
            ->where('student_id', $student->id)->whereNull('converted_at')->latest('id')->first();
        if (! $signup) {
            return;
        }
        $link = AgencyReferralLink::withoutGlobalScopes()->find($signup->referral_link_id);
        if (! $link || ! $link->isActive()) {
            return;   // link revoked before verification → attribution does not count
        }
        // QR-only claim: only if unowned or already this agency's (never steal).
        if ($student->agency_id !== null && $student->agency_id !== $link->agency_id) {
            return;
        }

        $this->asAgency($link->agency_id, function () use ($student, $signup, $link) {
            $student->update(['agency_id' => $link->agency_id, 'source' => StudentSource::QrLink->value]);
            ReferralSignup::where('id', $signup->id)->update(['converted_at' => now()]);
            $link->increment('uses_count');
            $link->forceFill(['last_used_at' => now()])->save();
        });
    }

    /**
     * Run a closure with the tenant bound (both nets) so writes to agency-scoped
     * tables succeed in the PUBLIC register/verify context, where no partner
     * session set app.agency_id.
     */
    private function asAgency(int $agencyId, callable $fn): mixed
    {
        $tc = app(TenantContext::class);
        $prev = $tc->agencyId();
        $tc->setAgencyId($agencyId);
        try {
            return DB::transaction(function () use ($fn, $agencyId) {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement("SET LOCAL app.agency_id = '".$agencyId."'");
                }

                return $fn();
            });
        } finally {
            $tc->setAgencyId($prev);
        }
    }
}
