<?php

namespace Tests\Feature;

use App\Enums\ActorType;
use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Enums\StudentSource;
use App\Models\ContentAuditLog;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Services\PipelineService;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerStudentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:PartnerAgency,1:User} */
    private function agencyOwner(string $name): array
    {
        $agency = PartnerAgency::create(['legal_name' => $name, 'country' => 'Bangladesh']);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        // Bound first, exactly as production does: the members table carries
        // RLS FORCE, so a cold INSERT is refused on Postgres.
        TenantScope::runAs((int) $agency->id, fn () => PartnerAgencyMember::create(['agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]));

        return [$agency, $user->fresh()];
    }

    private function asPartner(User $user, int $agencyId): self
    {
        return $this->actingAs($user)->withSession(['active_scope' => 'partner', 'active_partner_agency_id' => $agencyId]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'first_name' => 'Rafi', 'last_name' => 'Ahmed', 'dial' => '+880', 'mobile' => '1712345678',
            'email' => 'rafi@lead.test', 'destination_country' => 'United Kingdom', 'intake_month' => 'September', 'intake_year' => 2026,
        ], $over);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_create_student_owned_by_session_agency(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');

        $this->asPartner($user, $agency->id)->postJson('/api/partner/students', $this->payload())
            ->assertStatus(201)->assertJsonPath('student.name', 'Rafi Ahmed');

        $s = Student::where('email', 'rafi@lead.test')->firstOrFail();
        $this->assertSame($agency->id, $s->agency_id);           // from session
        $this->assertSame(StudentSource::PartnerModal, $s->source);
        $this->assertSame($user->id, $s->registered_by_user_id);
        $this->assertMatchesRegularExpression('/^VFI-\d{4}-\d{5}$/', $s->student_ref);
    }

    public function test_agency_id_in_body_is_ignored_tenant_from_session(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB] = $this->agencyOwner('B');

        // A forges agency_id=B in the body — it must be ignored.
        $this->asPartner($userA, $agencyA->id)
            ->postJson('/api/partner/students', $this->payload(['agency_id' => $agencyB->id]))
            ->assertStatus(201);

        $this->assertSame($agencyA->id, Student::where('email', 'rafi@lead.test')->value('agency_id'));
    }

    public function test_collision_refuses_email_owned_by_another_agency(): void
    {
        [$agencyA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        Student::create(['agency_id' => $agencyA->id, 'source' => 'partner_modal', 'email' => 'taken@lead.test', 'first_name' => 'X', 'student_ref' => 'RX']);

        $this->asPartner($userB, $agencyB->id)
            ->postJson('/api/partner/students', $this->payload(['email' => 'taken@lead.test']))
            ->assertStatus(409);
    }

    public function test_collision_refuses_a_self_signup_email_keep_separate(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        // a self-signup portal student (unowned)
        $u = User::factory()->create(['email' => 'selfsignup@lead.test']);
        Student::resolveFor($u);

        $this->asPartner($user, $agency->id)
            ->postJson('/api/partner/students', $this->payload(['email' => 'selfsignup@lead.test']))
            ->assertStatus(409);   // manual modal never claims a self-signup
    }

    public function test_list_is_tenant_scoped_and_filters(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        Student::create(['agency_id' => $agencyA->id, 'source' => 'partner_modal', 'email' => 'a1@x.test', 'first_name' => 'Alpha', 'destination_country' => 'Canada', 'student_ref' => 'RA1']);
        Student::create(['agency_id' => $agencyA->id, 'source' => 'partner_modal', 'email' => 'a2@x.test', 'first_name' => 'Beta', 'destination_country' => 'United Kingdom', 'student_ref' => 'RA2']);
        Student::create(['agency_id' => $agencyB->id, 'source' => 'partner_modal', 'email' => 'b1@x.test', 'first_name' => 'Gamma', 'student_ref' => 'RB1']);

        // A sees only its two
        $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/students')
            ->assertStatus(200)->assertJsonPath('meta.total', 2);
        // keyword filter
        $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/students?q=Alpha')
            ->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Alpha');
        // country filter
        $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/students?country=Canada')
            ->assertStatus(200)->assertJsonPath('meta.total', 1);
        // B sees only its one — never A's
        $this->asPartner($userB, $agencyB->id)->getJson('/api/partner/students')
            ->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'b1@x.test');
    }

    /**
     * The intake a partner sees, versus the slug the database holds.
     *
     * intake_month stores a taxonomy slug — the Apply button on a shortlist
     * already posts `fall` straight from the catalogue's season_label — and
     * both tables used to print it raw, so a partner read "fall 2026" and
     * reasonably concluded their own data was broken. The programme search has
     * always capitalised the same value, so the two screens disagreed about one
     * field.
     */
    public function test_the_intake_is_shown_capitalised_not_as_the_raw_slug(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'slug@x.test',
            'first_name' => 'Slug', 'student_ref' => 'RS1',
            'intake_month' => 'fall', 'intake_year' => 2026,
        ]);

        $this->asPartner($user, $agency->id)->getJson('/api/partner/students')
            ->assertStatus(200)->assertJsonPath('data.0.intake', 'Fall 2026');
    }

    /** A student with no intake yet must read as blank, not as a stray year. */
    public function test_an_unset_intake_is_empty_rather_than_half_printed(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'none@x.test',
            'first_name' => 'None', 'student_ref' => 'RS2',
        ]);

        $this->asPartner($user, $agency->id)->getJson('/api/partner/students')
            ->assertStatus(200)->assertJsonPath('data.0.intake', '');
    }

    /**
     * The two filters the console could not previously send. They were built
     * server-side and then left unreachable: the controls above the table had
     * no id and nothing in js/ read them.
     */
    public function test_the_intake_and_year_filters_narrow_the_list(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        foreach ([['f1@x.test', 'fall', 2026], ['s1@x.test', 'spring', 2027], ['f2@x.test', 'fall', 2027]] as $i => [$email, $season, $year]) {
            Student::create([
                'agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => $email,
                'first_name' => 'S'.$i, 'student_ref' => 'RF'.$i,
                'intake_month' => $season, 'intake_year' => $year,
            ]);
        }

        $this->asPartner($user, $agency->id)->getJson('/api/partner/students?intake=fall')
            ->assertStatus(200)->assertJsonPath('meta.total', 2);

        $this->asPartner($user, $agency->id)->getJson('/api/partner/students?year=2027')
            ->assertStatus(200)->assertJsonPath('meta.total', 2);

        // together, not either/or
        $this->asPartner($user, $agency->id)->getJson('/api/partner/students?intake=fall&year=2027')
            ->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'f2@x.test');
    }

    public function test_archived_list_is_separate(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        Student::create(['agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'live@x.test', 'first_name' => 'Live', 'student_ref' => 'RL']);
        Student::create(['agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'old@x.test', 'first_name' => 'Old', 'archived_at' => now(), 'student_ref' => 'RO']);

        $this->asPartner($user, $agency->id)->getJson('/api/partner/students')
            ->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'live@x.test');
        $this->asPartner($user, $agency->id)->getJson('/api/partner/students?archived=1')
            ->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'old@x.test');
    }

    /*
     * ------------------------------------------------------------------
     * Archive / unarchive.
     *
     * `archived_at` was READ by index() — the console's "Archived Students"
     * view — and written only by the GDPR erasure service, so the console
     * offered a view nothing could fill and a partner who mistyped an email
     * carried that row for ever. These cover the two endpoints that close it,
     * and the one case they refuse.
     * ------------------------------------------------------------------
     */

    public function test_archive_moves_a_student_into_the_archived_view_and_back(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        $s = Student::create(['agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'typo@x.test', 'first_name' => 'Typo', 'student_ref' => 'RT']);

        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/archive")
            ->assertStatus(200)->assertJsonPath('student.archived', true);

        $this->assertNotNull($s->fresh()->archived_at);
        $this->asPartner($user, $agency->id)->getJson('/api/partner/students')
            ->assertStatus(200)->assertJsonPath('meta.total', 0);
        $this->asPartner($user, $agency->id)->getJson('/api/partner/students?archived=1')
            ->assertStatus(200)->assertJsonPath('meta.total', 1);

        // and back again — an archive a partner cannot undo is a delete with
        // extra steps
        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/unarchive")
            ->assertStatus(200)->assertJsonPath('student.archived', false);

        $this->assertNull($s->fresh()->archived_at);
        $this->asPartner($user, $agency->id)->getJson('/api/partner/students')
            ->assertStatus(200)->assertJsonPath('meta.total', 1);
    }

    /** The row survives. ARCHIVE, never DELETE — applications point at it. */
    public function test_archive_does_not_remove_the_row(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        $s = Student::create(['agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'keep@x.test', 'first_name' => 'Keep', 'student_ref' => 'RK']);

        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/archive")->assertStatus(200);

        $this->assertDatabaseHas('students', ['id' => $s->id, 'email' => 'keep@x.test']);
    }

    /**
     * A student with a live application is REFUSED, with the count so the
     * console can give a reason rather than a dead button.
     *
     * Archiving would not hide the case — it stays in the pipeline and the
     * KPIs — it would strand it: document uploads are reached through
     * /api/partner/students/{id}/documents and the only route to that id is the
     * student list, which archiving removes them from. Staff would go on asking
     * for paperwork the partner can no longer see how to send.
     */
    public function test_a_student_with_a_live_application_cannot_be_archived(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        app(TenantContext::class)->setAgencyId($agency->id);
        $s = Student::create(['agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'live@x.test', 'first_name' => 'Live', 'student_ref' => 'RLV']);
        app(PipelineService::class)->create($s, [], $user->id);   // lands at `submitted`

        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/archive")
            ->assertStatus(409)
            ->assertJsonPath('open_applications', 1);

        $this->assertNull($s->fresh()->archived_at);
    }

    /** A finished case does not hold the student open for ever. */
    public function test_a_student_whose_applications_are_all_closed_can_be_archived(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        app(TenantContext::class)->setAgencyId($agency->id);
        $s = Student::create(['agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'done@x.test', 'first_name' => 'Done', 'student_ref' => 'RD']);
        $pipeline = app(PipelineService::class);
        $app = $pipeline->create($s, [], $user->id);

        // The write is to an RLS FORCE table, so the tenant has to be really
        // bound rather than bypassed — runAs is how production performs it.
        TenantScope::runAs((int) $agency->id, fn () => $pipeline->transition(
            $app, ApplicationStatus::NonEnrolment, ActorType::Staff, $user->id, 'Withdrew'
        ));

        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/archive")
            ->assertStatus(200)->assertJsonPath('student.archived', true);
    }

    /** Both are idempotent: a double-click is not an error and writes no second audit row. */
    public function test_archiving_twice_is_a_no_op(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        $s = Student::create(['agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'twice@x.test', 'first_name' => 'Twice', 'student_ref' => 'RW']);

        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/archive")->assertStatus(200);
        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/archive")
            ->assertStatus(200)->assertJsonPath('student.archived', true);

        $this->assertSame(1, ContentAuditLog::where('action', 'partner_student_archive')
            ->where('entity_id', (string) $s->id)->count());

        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/unarchive")->assertStatus(200);
        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/unarchive")
            ->assertStatus(200)->assertJsonPath('student.archived', false);

        $this->assertSame(1, ContentAuditLog::where('action', 'partner_student_unarchive')
            ->where('entity_id', (string) $s->id)->count());
    }

    /** Somebody other than the data subject changed their record — it is on the record. */
    public function test_archiving_is_audited_with_the_agency(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        $s = Student::create(['agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'audit@x.test', 'first_name' => 'Audit', 'student_ref' => 'RAU']);

        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/archive")->assertStatus(200);

        $row = ContentAuditLog::where('action', 'partner_student_archive')->firstOrFail();
        $this->assertSame('student', $row->entity);
        $this->assertSame((string) $s->id, $row->entity_id);
        $this->assertSame($user->id, $row->actor_user_id);
        $this->assertSame($agency->id, $row->after['agency_id']);
        $this->assertNotNull($row->after['archived_at']);
    }

    /**
     * Another agency's id is a 404, never a 403 — a 403 would confirm to one
     * agency that another agency's row exists.
     */
    /**
     * The Restore button must not undo half of a GDPR erasure.
     *
     * archived_at is not the partner's field alone: DataSubjectErasureService
     * sets it when it pseudonymises a subject, on purpose, so an erased person
     * stops surfacing in the console as a workable lead. An unconditional
     * unarchive hands the partner a button that puts them straight back.
     */
    public function test_an_erased_student_cannot_be_restored_from_the_archive(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        $student = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal', 'student_ref' => 'RGDPR',
            // exactly what pseudonymise() leaves behind
            'first_name' => 'Erased', 'email' => 'erased+0123456789abcdef@erased.invalid',
            'archived_at' => now(),
        ]);

        $this->asPartner($user, $agency->id)
            ->postJson("/api/partner/students/{$student->id}/unarchive")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This record was erased at the request of the person it belonged to and cannot be restored.');

        $this->assertNotNull($student->fresh()?->archived_at, 'an erased subject must stay out of the lists');
    }

    /** And an ordinary archived student still restores, or the guard is an outage. */
    public function test_an_ordinary_archived_student_still_restores(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        $student = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal', 'student_ref' => 'ROK',
            'first_name' => 'Ordinary', 'email' => 'ordinary@x.test', 'archived_at' => now(),
        ]);

        $this->asPartner($user, $agency->id)
            ->postJson("/api/partner/students/{$student->id}/unarchive")
            ->assertStatus(200);

        $this->assertNull($student->fresh()?->archived_at);
    }

    public function test_one_agency_cannot_archive_anothers_student(): void
    {
        [$agencyA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        $aStudent = Student::create(['agency_id' => $agencyA->id, 'source' => 'partner_modal', 'email' => 'theirs@x.test', 'first_name' => 'Theirs', 'student_ref' => 'RTH']);

        $this->asPartner($userB, $agencyB->id)->postJson("/api/partner/students/{$aStudent->id}/archive")
            ->assertStatus(404);
        $this->asPartner($userB, $agencyB->id)->postJson("/api/partner/students/{$aStudent->id}/unarchive")
            ->assertStatus(404);

        $this->assertNull($aStudent->fresh()->archived_at);
    }

    public function test_archive_requires_a_partner_session(): void
    {
        [$agency] = $this->agencyOwner('A');
        $s = Student::create(['agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'anon@x.test', 'first_name' => 'Anon', 'student_ref' => 'RAN']);

        $this->postJson("/api/partner/students/{$s->id}/archive")->assertStatus(401);
    }
}
