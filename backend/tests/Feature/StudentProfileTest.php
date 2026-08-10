<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'a-good-long-passphrase';

    private function loginStudent(array $over = []): User
    {
        $u = User::factory()->create(array_merge(['password' => self::PW, 'email_verified_at' => now()], $over));
        UserRole::create(['user_id' => $u->id, 'role' => Role::Student, 'agency_id' => null, 'granted_at' => now()]);
        $this->postJson('/api/login', ['email' => $u->email, 'password' => self::PW])->assertStatus(200);

        return $u->fresh();
    }

    private function personal(array $over = []): array
    {
        return array_merge([
            'first' => 'Ayesha', 'last' => 'Rahman', 'dob' => '2002-04-17',
            'nationality' => 'Bangladeshi', 'cc' => '+880', 'phone' => '1719450382',
            'email' => 'ayesha@example.com',
        ], $over);
    }

    public function test_aggregate_shape_and_implicit_self_creation(): void
    {
        $u = $this->loginStudent(['name' => 'Ayesha Rahman']);

        $res = $this->getJson('/api/me/profile')->assertStatus(200)
            ->assertJsonStructure([
                'student' => ['student_ref', 'name', 'initials'],
                'personal' => ['first', 'last', 'email'],
                'address', 'academic', 'tests',
                'prefs' => ['countries', 'intake', 'budget', 'field'],
                'documents', 'visaDocuments',
                'completeness' => ['pct', 'done', 'total'],
                'versions' => ['personal', 'address', 'prefs', 'academic', 'tests'],
                'intakeOptions', 'must_verify',
            ]);

        // resolved implicitly from the session (one student row exists now)
        $this->assertSame(1, Student::where('user_id', $u->id)->count());
        $res->assertJsonPath('completeness.total', 26);
        $res->assertJsonPath('personal.email', $u->email);          // defaulted from the sign-in identity
        $this->assertCount(6, $res->json('documents'));             // application pack
        $this->assertCount(6, $res->json('visaDocuments'));
    }

    public function test_save_personal_then_completeness_rises(): void
    {
        $this->loginStudent();
        $before = $this->getJson('/api/me/completeness')->json('done');

        $this->putJson('/api/me/profile/personal', $this->personal())->assertStatus(200)
            ->assertJsonPath('personal.first', 'Ayesha');

        $after = $this->getJson('/api/me/completeness')->json('done');
        $this->assertGreaterThan($before, $after);
    }

    public function test_personal_revalidates_client_rules(): void
    {
        $this->loginStudent();
        $this->putJson('/api/me/profile/personal', $this->personal(['first' => '']))->assertStatus(422);
        $this->putJson('/api/me/profile/personal', $this->personal(['phone' => '123']))->assertStatus(422);   // <6 digits
        $this->putJson('/api/me/profile/personal', $this->personal(['email' => 'not-an-email']))->assertStatus(422);
    }

    public function test_profile_email_does_not_change_sign_in_identity(): void
    {
        $u = $this->loginStudent();
        $this->putJson('/api/me/profile/personal', $this->personal(['email' => 'newcontact@example.com']))->assertStatus(200);

        $this->assertSame($u->email, $u->fresh()->email);   // login identity untouched
    }

    public function test_qualifications_whole_collection_replace_drops_empty_rows(): void
    {
        $this->loginStudent();
        $v = $this->getJson('/api/me/profile')->json('versions.academic');

        $this->putJson('/api/me/qualifications', ['version' => $v, 'rows' => [
            ['qualification' => 'BSc CSE', 'institution' => 'DCU', 'year' => '2025', 'grade' => 'CGPA 3.71'],
            ['qualification' => '', 'institution' => '', 'year' => '', 'grade' => ''],   // all-empty → dropped
            ['qualification' => 'HSC', 'institution' => 'BMC', 'year' => '2020', 'grade' => 'GPA 5.00'],
        ]])->assertStatus(200)->assertJsonCount(2, 'academic');
    }

    public function test_qualification_year_must_be_four_digits(): void
    {
        $this->loginStudent();
        $this->putJson('/api/me/qualifications', ['rows' => [
            ['qualification' => 'BSc', 'institution' => 'X', 'year' => '25', 'grade' => 'A'],
        ]])->assertStatus(422);
    }

    public function test_stale_qualifications_save_is_409(): void
    {
        $this->loginStudent();
        // first save establishes a real version
        $this->putJson('/api/me/qualifications', ['version' => '0', 'rows' => [
            ['qualification' => 'BSc', 'institution' => 'X', 'year' => '2025', 'grade' => 'A'],
        ]])->assertStatus(200);

        // a second save that still holds the pre-save version is stale
        $this->putJson('/api/me/qualifications', ['version' => '0', 'rows' => [
            ['qualification' => 'MSc', 'institution' => 'Y', 'year' => '2026', 'grade' => 'A'],
        ]])->assertStatus(409);
    }

    public function test_test_scores_require_score_when_named_and_parse_numeric(): void
    {
        $this->loginStudent();
        $this->putJson('/api/me/test_scores', ['rows' => [['test' => 'IELTS', 'score' => '', 'date' => '']]])
            ->assertStatus(422);

        $this->putJson('/api/me/test_scores', ['rows' => [
            ['test' => 'IELTS Academic', 'score' => '7.5', 'date' => '2026-01-24'],
            ['test' => 'GRE General', 'score' => '318', 'date' => ''],
        ]])->assertStatus(200)->assertJsonCount(2, 'tests');

        $this->assertDatabaseHas('student_test_scores', ['score_raw' => '318', 'score_numeric' => 318]);
        $this->assertDatabaseHas('student_test_scores', ['score_raw' => '7.5', 'score_numeric' => 7.5]);
    }

    public function test_preferences_replace_destinations(): void
    {
        $this->loginStudent();
        $this->putJson('/api/me/preferences', [
            'countries' => ['United Kingdom', 'Ireland', 'Canada'],
            'intake' => 'September 2026', 'budget' => '', 'field' => 'Computing',
        ])->assertStatus(200)->assertJsonPath('prefs.countries', ['United Kingdom', 'Ireland', 'Canada']);

        // replace with fewer
        $this->putJson('/api/me/preferences', ['countries' => ['Ireland'], 'intake' => 'September 2026'])
            ->assertStatus(200)->assertJsonPath('prefs.countries', ['Ireland']);
    }

    public function test_no_endpoint_accepts_a_student_id_idor(): void
    {
        // student A with distinctive data
        $a = $this->loginStudent(['name' => 'Alice A']);
        $this->putJson('/api/me/profile/personal', $this->personal(['first' => 'Alice']))->assertStatus(200);
        $aRef = $this->getJson('/api/me/profile')->json('student.student_ref');

        // student B logs in (new session) — must only ever see B's own data
        $b = $this->loginStudent(['name' => 'Bob B']);
        $res = $this->getJson('/api/me/profile')->assertStatus(200);
        $this->assertNotSame($aRef, $res->json('student.student_ref'));
        $this->assertNotSame('Alice', $res->json('personal.first'));

        // even passing A's ref/id as a query is ignored (implicit-self)
        $this->getJson('/api/me/profile?student='.$aRef.'&student_id='.$a->id)
            ->assertJsonPath('student.student_ref', $res->json('student.student_ref'));
    }

    public function test_intake_options_served_by_backend(): void
    {
        $this->loginStudent();
        $opts = $this->getJson('/api/me/profile')->json('intakeOptions');
        $this->assertNotEmpty($opts);
        $this->assertMatchesRegularExpression('/^(January|May|September) \d{4}$/', $opts[0]);
    }
}
