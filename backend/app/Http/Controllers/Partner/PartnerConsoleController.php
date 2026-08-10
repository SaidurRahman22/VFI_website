<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6 — the authenticated partner console surface. The agency is resolved
 * from the session-bound tenant (EnsurePartner), never a client id. `me` powers
 * the console greeting per authenticated agency (kills the shared-global-string
 * leak); `members` is tenant-scoped by BelongsToAgency and used to prove
 * cross-tenant isolation.
 */
class PartnerConsoleController extends Controller
{
    /** GET /api/partner/me — greeting identity for the console top bar. */
    public function me(Request $request): JsonResponse
    {
        $agencyId = (int) $request->session()->get('active_partner_agency_id');
        $agency = PartnerAgency::find($agencyId);                       // staff table (not scoped)
        $member = PartnerAgencyMember::where('user_id', $request->user()->id)->first();  // tenant-scoped

        $name = $member?->contact_person_name ?: $request->user()->name;

        return response()->json([
            'agency' => ['id' => $agency?->id, 'name' => $agency?->legal_name],
            'member' => [
                'name' => $name,
                'initial' => mb_strtoupper(mb_substr((string) $name, 0, 1)),
                'seat_role' => $member?->seat_role?->value,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/members — this agency's seats ONLY (tenant-scoped). */
    public function members(Request $request): JsonResponse
    {
        // Any client-supplied agency_id is IGNORED — the tenant comes from the
        // session. This is the cross-tenant isolation guarantee in action.
        $members = PartnerAgencyMember::with('user')->get()->map(fn ($m) => [
            'user_id' => $m->user_id,
            'name' => $m->contact_person_name,
            'work_email' => $m->work_email,
            'seat_role' => $m->seat_role?->value,
            'status' => $m->status?->value,
        ]);

        return response()->json([
            'agency_id' => (int) $request->session()->get('active_partner_agency_id'),
            'count' => $members->count(),
            'members' => $members,
        ])->header('Cache-Control', 'no-store');
    }
}
