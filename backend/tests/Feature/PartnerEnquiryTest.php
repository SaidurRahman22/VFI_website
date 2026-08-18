<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Enums\SeatRole;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Partner\ProgramRequest;
use App\Models\Partner\ProgramRequestDocument;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartnerEnquiryTest extends TestCase
{
    use RefreshDatabase;

    private const EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

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

    private function pdf(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('transcript.pdf', "%PDF-1.4\n".$body."\n%%EOF");
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_new_enquiry_with_clean_document(): void
    {
        [$agency, $user] = $this->agencyOwner('A');

        $this->asPartner($user, $agency->id)->post('/api/partner/enquiries', [
            'enquiry_type' => 'new', 'first_name' => 'Lead', 'last_name' => 'One', 'email' => 'lead@x.test',
            'destination' => 'Canada', 'files' => [UploadedFile::fake()->image('t.png')],
        ])->assertStatus(201)->assertJsonPath('files_rejected', 0);

        app(TenantContext::class)->setAgencyId($agency->id);
        $pr = ProgramRequest::firstOrFail();
        $this->assertSame($agency->id, $pr->agency_id);
        $doc = ProgramRequestDocument::firstOrFail();
        $this->assertSame(ScanStatus::Clean, $doc->scan_status);
        Storage::disk('documents')->assertExists($doc->storage_key);
        $this->assertStringStartsWith('blob/', $doc->storage_key);
    }

    public function test_eicar_document_is_quarantined_and_never_served(): void
    {
        [$agency, $user] = $this->agencyOwner('A');

        $this->asPartner($user, $agency->id)->post('/api/partner/enquiries', [
            'enquiry_type' => 'new', 'first_name' => 'Lead', 'email' => 'lead@x.test',
            'files' => [$this->pdf(self::EICAR)],
        ])->assertStatus(201)->assertJsonPath('files_rejected', 1);

        app(TenantContext::class)->setAgencyId($agency->id);
        $doc = ProgramRequestDocument::firstOrFail();
        $this->assertSame(ScanStatus::Infected, $doc->scan_status);
        Storage::disk('documents')->assertMissing($doc->storage_key);   // bytes dropped
        // and it is never downloadable
        $this->asPartner($user, $agency->id)->getJson('/api/partner/enquiries/documents/'.$doc->id.'/download')
            ->assertStatus(404);
    }

    public function test_existing_enquiry_resolves_student_within_tenant_only(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB] = $this->agencyOwner('B');
        $bStudent = Student::create(['agency_id' => $agencyB->id, 'source' => 'partner_modal', 'email' => 'b@x.test', 'first_name' => 'B', 'student_ref' => 'RB']);

        // A references B's student id → refused
        $this->asPartner($userA, $agencyA->id)->post('/api/partner/enquiries', [
            'enquiry_type' => 'existing', 'student_id' => $bStudent->id,
        ])->assertStatus(404);
    }

    public function test_clean_document_download_is_single_use(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        $this->asPartner($user, $agency->id)->post('/api/partner/enquiries', [
            'enquiry_type' => 'new', 'first_name' => 'Lead', 'email' => 'lead@x.test',
            'files' => [UploadedFile::fake()->image('t.png')],
        ])->assertStatus(201);

        app(TenantContext::class)->setAgencyId($agency->id);
        $docId = ProgramRequestDocument::value('id');

        $url = $this->asPartner($user, $agency->id)->getJson('/api/partner/enquiries/documents/'.$docId.'/download')
            ->assertStatus(200)->json('url');
        $path = parse_url($url, PHP_URL_PATH);

        $this->get($path)->assertStatus(200)->assertHeader('Content-Disposition', 'attachment; filename="t.png"');
        $this->get($path)->assertStatus(404);   // single-use
    }

    public function test_enquiries_list_is_tenant_scoped(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        $this->asPartner($userA, $agencyA->id)->post('/api/partner/enquiries', ['enquiry_type' => 'new', 'first_name' => 'A1', 'email' => 'a1@x.test'])->assertStatus(201);
        $this->asPartner($userB, $agencyB->id)->post('/api/partner/enquiries', ['enquiry_type' => 'new', 'first_name' => 'B1', 'email' => 'b1@x.test'])->assertStatus(201);

        $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/enquiries')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'A1');
        $this->asPartner($userB, $agencyB->id)->getJson('/api/partner/enquiries')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'B1');
    }
}
