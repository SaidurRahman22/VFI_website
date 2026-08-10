<?php

namespace Tests\Feature;

use App\Models\Catalogue\Institution;
use App\Models\Catalogue\Program;
use App\Models\Catalogue\ProgramSearchRow;
use App\Models\Catalogue\ProgramShortlist;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\PartnerAgency;
use App\Models\Student\Student;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_program_relations_and_intakes(): void
    {
        $inst = Institution::create(['name' => 'University of Glasgow', 'country' => 'United Kingdom', 'vfi_represented' => true]);
        $prog = $inst->programs()->create(['title' => 'MSc Data Analytics', 'level' => 'master', 'study_area' => 'IT & Computing', 'is_stem' => true]);
        $prog->intakes()->create(['intake_month' => 9, 'intake_year' => 2026, 'season_label' => 'Fall', 'status' => 'open']);
        $prog->intakes()->create(['intake_month' => 1, 'intake_year' => 2027, 'season_label' => 'Spring', 'status' => 'open']);
        $prog->requirements()->create(['test' => 'ielts', 'min_overall' => 6.5, 'is_required' => true]);

        $this->assertTrue($prog->institution->is($inst));
        $this->assertCount(2, $prog->intakes);
        $this->assertTrue($prog->is_stem);
        $this->assertSame('6.50', (string) $prog->requirements->first()->min_overall);
    }

    public function test_flat_search_row_stores_portable_flag_tokens(): void
    {
        $inst = Institution::create(['name' => 'Test U', 'country' => 'Canada']);
        $prog = $inst->programs()->create(['title' => 'BSc CS', 'level' => 'bachelor']);

        ProgramSearchRow::create([
            'program_id' => $prog->id, 'institution_id' => $inst->id,
            'title' => 'BSc CS', 'university_name' => 'Test U', 'country' => 'Canada', 'level' => 'bachelor',
            'intake_month' => 9, 'intake_year' => 2026, 'season_label' => 'Fall',
            'search_blob' => 'bsc cs test u', 'flags' => ' stem coop scholarship waive_gre ',
        ]);

        // a chip becomes a space-padded LIKE — portable on SQLite + Postgres
        $this->assertSame(1, ProgramSearchRow::where('flags', 'like', '% stem %')->count());
        $this->assertSame(1, ProgramSearchRow::where('flags', 'like', '% waive_gre %')->count());
        $this->assertSame(0, ProgramSearchRow::where('flags', 'like', '% gmat %')->count());
        $this->assertSame(1, ProgramSearchRow::where('search_blob', 'like', '%test u%')->count());
    }

    public function test_shortlist_is_tenant_isolated(): void
    {
        $a1 = PartnerAgency::create(['legal_name' => 'A1', 'country' => 'X']);
        $a2 = PartnerAgency::create(['legal_name' => 'A2', 'country' => 'Y']);
        $s1 = Student::create(['agency_id' => $a1->id, 'source' => 'partner_modal', 'email' => 's1@x.test', 'student_ref' => 'R1']);
        $s2 = Student::create(['agency_id' => $a2->id, 'source' => 'partner_modal', 'email' => 's2@x.test', 'student_ref' => 'R2']);
        $inst = Institution::create(['name' => 'U', 'country' => 'X']);
        $p = $inst->programs()->create(['title' => 'P', 'level' => 'master']);

        app(TenantContext::class)->setAgencyId($a1->id);
        ProgramShortlist::create(['agency_id' => $a1->id, 'student_id' => $s1->id, 'program_id' => $p->id, 'note' => 'A1 pick']);
        app(TenantContext::class)->setAgencyId($a2->id);
        ProgramShortlist::create(['agency_id' => $a2->id, 'student_id' => $s2->id, 'program_id' => $p->id]);

        app(TenantContext::class)->setAgencyId($a1->id);
        $this->assertSame(1, ProgramShortlist::count());
        $this->assertSame('A1 pick', ProgramShortlist::first()->note);
        app(TenantContext::class)->clear();
        $this->assertSame(0, ProgramShortlist::count());   // fail-closed
        $this->assertSame(2, ProgramShortlist::withoutGlobalScope(BelongsToAgencyScope::class)->count());
    }
}
