<?php

namespace Tests\Feature;

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
use App\Services\PartnerReview;
use App\Support\RlsBypass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerReviewTest extends TestCase
{
    use RefreshDatabase;

    private PartnerReview $review;

    protected function setUp(): void
    {
        parent::setUp();
        $this->review = app(PartnerReview::class);
    }

    private function application(array $over = []): PartnerApplication
    {
        $user = User::factory()->create(array_merge(['status' => UserStatus::PendingVerification], $over));

        return PartnerApplication::create([
            'agency_name' => 'Acme Study Abroad', 'country' => 'Bangladesh', 'city' => 'Dhaka',
            'contact_person' => 'Jane', 'work_email' => $user->email, 'phone_cc' => '+880', 'phone_national' => '1712345678',
            'user_id' => $user->id, 'authorised_signatory_attested' => true,
            'submitted_at' => now(), 'review_status' => ApplicationReviewStatus::Pending,
        ]);
    }

    public function test_approval_mints_exactly_one_tenant_and_one_owner_role(): void
    {
        Mail::fake();
        $staff = User::factory()->create();
        $app = $this->application();

        $agency = $this->review->approve($app, $staff);

        $this->assertSame(1, PartnerAgency::count());
        $this->assertSame(AgencyStatus::Approved, $agency->status);
        $this->assertSame($staff->id, $agency->approved_by_user_id);

        // Exactly one owner seat, scoped to this agency. No tenant is bound here
        // (staff minted it), so net 2 has to come off through the app's own
        // audited escape hatch - the members policy names its flag. No-op off
        // Postgres, so this reads the same on both drivers.
        $members = RlsBypass::run(
            fn () => PartnerAgencyMember::withoutGlobalScope(BelongsToAgencyScope::class)->get()
        );
        $this->assertCount(1, $members);
        $this->assertSame($agency->id, $members->first()->agency_id);   // the comment's claim, asserted
        $this->assertSame(SeatRole::Owner, $members->first()->seat_role);
        $this->assertSame(MemberStatus::Active, $members->first()->status);

        // partner_owner role granted with the agency binding
        $this->assertSame(1, UserRole::where('user_id', $app->user_id)->where('role', Role::PartnerOwner->value)->where('agency_id', $agency->id)->count());

        // application marked approved + user activated + audited + emailed
        $app->refresh();
        $this->assertSame(ApplicationReviewStatus::Approved, $app->review_status);
        $this->assertSame($agency->id, $app->agency_id);
        $this->assertSame(UserStatus::Active, $app->user->fresh()->status);
        $this->assertSame(1, AuthEvent::where('event', 'agency_approved')->count());
        Mail::assertSent(PartnerDecisionMail::class, fn (PartnerDecisionMail $m) => $m->decision === 'approved');
    }

    public function test_approve_is_idempotent(): void
    {
        Mail::fake();
        $staff = User::factory()->create();
        $app = $this->application();

        $a1 = $this->review->approve($app, $staff);
        $a2 = $this->review->approve($app->fresh(), $staff);

        $this->assertTrue($a1->is($a2));
        $this->assertSame(1, PartnerAgency::count());   // no second tenant
    }

    public function test_rejection_creates_no_tenant(): void
    {
        Mail::fake();
        $staff = User::factory()->create();
        $app = $this->application();

        $this->review->reject($app, $staff, 'Documents did not match the agency name.');

        $this->assertSame(0, PartnerAgency::count());
        // Wrapped, because unwrapped this assertion CANNOT FAIL on Postgres: with
        // no tenant bound the policy returns 0 rows whether or not a rejection
        // wrongly minted a seat. It was green and proving nothing.
        $this->assertSame(0, RlsBypass::run(
            fn () => PartnerAgencyMember::withoutGlobalScope(BelongsToAgencyScope::class)->count()
        ));
        $app->refresh();
        $this->assertSame(ApplicationReviewStatus::Rejected, $app->review_status);
        $this->assertStringContainsString('did not match', $app->review_notes);
        Mail::assertSent(PartnerDecisionMail::class, fn (PartnerDecisionMail $m) => $m->decision === 'rejected');
    }

    public function test_more_info_sets_status_and_emails(): void
    {
        Mail::fake();
        $staff = User::factory()->create();
        $app = $this->application();

        $this->review->requestMoreInfo($app, $staff, 'Please upload your trade license.');

        $this->assertSame(ApplicationReviewStatus::MoreInfo, $app->fresh()->review_status);
        $this->assertSame(0, PartnerAgency::count());
        Mail::assertSent(PartnerDecisionMail::class, fn (PartnerDecisionMail $m) => $m->decision === 'more_info');
    }

    public function test_suspend_revokes_every_member_session(): void
    {
        Mail::fake();
        $staff = User::factory()->create();
        $application = $this->application();
        $agency = $this->review->approve($application, $staff);
        // The application already names the user the owner seat was minted for,
        // so take it from there rather than reading an RLS table with no tenant
        // bound. Read that way on Postgres it was NULL, and both session rows
        // below were then inserted against a NULL user_id - so the closing
        // assertion passed or failed for a reason unrelated to revocation.
        $ownerId = $application->user_id;

        DB::table('sessions')->insert([
            ['id' => 'sA', 'user_id' => $ownerId, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
            ['id' => 'sB', 'user_id' => $ownerId, 'ip_address' => '2.2.2.2', 'user_agent' => 'y', 'payload' => '', 'last_activity' => time()],
        ]);

        $this->review->setAgencyStatus($agency, AgencyStatus::Suspended);

        $this->assertSame(AgencyStatus::Suspended, $agency->fresh()->status);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $ownerId)->count());   // logged out
    }
}
