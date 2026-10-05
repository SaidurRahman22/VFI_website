<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Models\Content\PpDoc;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Partner\PartnerNotification;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Services\PipelineService;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerResourcesNotificationsTest extends TestCase
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

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_resources_are_filtered_by_the_server_not_dumped(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        PpDoc::create(['legacy_id' => 'd1', 'position' => 0, 'country' => 'UK', 'category' => 'Visa', 'title' => 'UK visa guide', 'url' => 'https://x.test/a.pdf']);
        PpDoc::create(['legacy_id' => 'd2', 'position' => 1, 'country' => 'Canada', 'category' => 'Finance', 'title' => 'Canada funding', 'url' => 'https://x.test/b.pdf']);
        PpDoc::create(['legacy_id' => 'd3', 'position' => 2, 'country' => 'UK', 'category' => 'Finance', 'title' => 'UK loans', 'url' => 'https://x.test/c.pdf']);

        // country filter → only UK's two
        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources?country=UK')
            ->assertStatus(200)->assertJsonCount(2, 'data');
        // country + category
        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources?country=UK&category=Visa')
            ->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'UK visa guide');
        // keyword
        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources?q=funding')
            ->assertStatus(200)->assertJsonCount(1, 'data');
        // facet lists come back for the panels
        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources')
            ->assertJsonCount(3, 'data')->assertJsonPath('countries', ['Canada', 'UK']);
    }

    /**
     * A row that names a document nobody has uploaded says so.
     *
     * Every row on live is one of four written by the 2026_09_19 seed
     * migration, and all four carry `url` = `partner-resources.html` — the page
     * the partner is already on. The console rendered each as a "Download"
     * button beside the word "PDF", so a partner clicked four times, got the
     * same page back four times, and concluded the console was broken. It is
     * not broken; the documents do not exist yet. The API has to say which of
     * those two it is, and it must not invent a file to avoid saying it.
     */
    public function test_a_seeded_row_is_flagged_as_a_placeholder_and_offers_no_link(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        // the seed migration's own shape, legacy_id included
        PpDoc::create(['legacy_id' => 'seed_pp_docs_1', 'position' => 0, 'country' => 'All',
            'category' => 'Agreements', 'title' => 'Partner agreement (template)', 'size' => 'PDF',
            'date' => 'Current', 'url' => 'partner-resources.html']);

        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources')
            ->assertStatus(200)
            ->assertJsonPath('data.0.placeholder', true)
            // null, not the stored value: a link that reopens the page you are
            // on is worse than no link, because it reads as a failed download
            ->assertJsonPath('data.0.url', null)
            // the row itself is still listed — it is a real, editable entry
            ->assertJsonPath('data.0.title', 'Partner agreement (template)');
    }

    /** A real document is untouched: flag false, url intact. */
    public function test_a_real_document_is_not_flagged(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        PpDoc::create(['legacy_id' => 'd1', 'position' => 0, 'country' => 'UK', 'category' => 'Visa',
            'title' => 'UK visa guide', 'url' => 'https://x.test/a.pdf']);

        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources')
            ->assertStatus(200)
            ->assertJsonPath('data.0.placeholder', false)
            ->assertJsonPath('data.0.url', 'https://x.test/a.pdf');
    }

    /**
     * The two signals, each covering what the other gets wrong.
     *
     * An admin-added row pointing at a page of this site is as undownloadable
     * as a seeded one, and an absolute https link to someone's guidance page is
     * a genuine resource even though it is HTML — calling that one a
     * placeholder would be the opposite lie.
     */
    public function test_placeholder_detection_reads_the_url_not_only_the_seed_marker(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        foreach ([
            ['own-page', 'partner-enquiries.html', true],          // relative page of this site
            ['root-page', '/partner-resources.html?x=1', true],    // root-relative, query trimmed
            ['no-url', '', true],                                  // nothing to open at all
            ['external-page', 'https://gov.test/student-visa.html', false],
            ['own-file', '/storage/media/guide.pdf', false],       // a real file on this site
        ] as $i => [$id, $url, $expected]) {
            PpDoc::create(['legacy_id' => $id, 'position' => $i, 'title' => $id, 'url' => $url]);
        }

        $rows = collect($this->asPartner($user, $agency->id)->getJson('/api/partner/resources')
            ->assertStatus(200)->json('data'))->keyBy('title');

        $this->assertTrue($rows['own-page']['placeholder']);
        $this->assertTrue($rows['root-page']['placeholder']);
        $this->assertTrue($rows['no-url']['placeholder']);
        $this->assertFalse($rows['external-page']['placeholder']);
        $this->assertFalse($rows['own-file']['placeholder']);
        $this->assertSame('/storage/media/guide.pdf', $rows['own-file']['url']);
    }

    /**
     * The label clears itself, which is the reason the flag is derived from the
     * url rather than from the seed migration's `legacy_id` marker.
     *
     * legacy_id is immutable — ContentItem mints it once — so a marker-based
     * flag would go on calling one of those four rows a sample for ever after
     * the desk had uploaded the real PDF to it. Here the desk's only action is
     * editing the url, and nobody has to remember a second step.
     */
    public function test_editing_a_seeded_row_to_point_at_a_real_file_clears_the_flag(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        $doc = PpDoc::create(['legacy_id' => 'seed_pp_docs_2', 'position' => 0, 'title' => 'Checklist',
            'url' => 'partner-resources.html']);

        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources')
            ->assertJsonPath('data.0.placeholder', true);

        $doc->forceFill(['url' => 'https://x.test/checklist.pdf'])->save();

        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources')
            ->assertJsonPath('data.0.placeholder', false)
            ->assertJsonPath('data.0.url', 'https://x.test/checklist.pdf');
    }

    public function test_notifications_are_tenant_scoped_with_read_state(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        app(TenantContext::class)->setAgencyId($agencyA->id);
        $sA = Student::create(['agency_id' => $agencyA->id, 'source' => 'partner_modal', 'email' => 'a@x.test', 'first_name' => 'A', 'student_ref' => 'RA']);
        app(PipelineService::class)->create($sA, [], $userA->id);   // creates a notification
        app(TenantContext::class)->setAgencyId($agencyB->id);
        $sB = Student::create(['agency_id' => $agencyB->id, 'source' => 'partner_modal', 'email' => 'b@x.test', 'first_name' => 'B', 'student_ref' => 'RB']);
        app(PipelineService::class)->create($sB, [], $userB->id);

        // A sees only its own notification, unread
        $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/notifications')
            ->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.title', 'Application submitted');

        // mark all read
        $this->asPartner($userA, $agencyA->id)->postJson('/api/partner/notifications/read', [])
            ->assertStatus(200)->assertJsonPath('unread_count', 0);

        // B's notification is untouched (tenant isolation)
        app(TenantContext::class)->setAgencyId($agencyB->id);
        $this->assertSame(1, PartnerNotification::whereNull('read_at')->count());
    }
}
