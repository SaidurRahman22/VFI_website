<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\StudentTrackingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'a-good-long-passphrase';

    private function loginStudent(): User
    {
        $u = User::factory()->create(['password' => self::PW, 'email_verified_at' => now()]);
        UserRole::create(['user_id' => $u->id, 'role' => Role::Student, 'agency_id' => null, 'granted_at' => now()]);
        $this->postJson('/api/login', ['email' => $u->email, 'password' => self::PW])->assertStatus(200);

        return $u->fresh();
    }

    public function test_empty_student_gets_default_journey_and_empty_lists(): void
    {
        $this->loginStudent();

        $this->getJson('/api/me/tracking')->assertStatus(200)
            ->assertJsonPath('journey.pct', 0)
            ->assertJsonCount(6, 'journey.stages')             // default template always renders
            ->assertJsonPath('journey.stages.0.state', 'todo')
            ->assertJsonCount(0, 'applications')
            ->assertJsonPath('counts.all', 0);
    }

    public function test_seeded_tracking_computes_derived_values(): void
    {
        $u = $this->loginStudent();
        (new StudentTrackingSeeder)->seedFor(Student::resolveFor($u));

        $res = $this->getJson('/api/me/tracking')->assertStatus(200);

        // 3 done + 1 now of 6 → (3 + 0.5)/6 = 58%
        $res->assertJsonPath('journey.pct', 58);
        $res->assertJsonPath('counts.all', 4);
        $res->assertJsonPath('counts.offer', 1);
        $res->assertJsonPath('counts.review', 1);

        // exactly one action is overdue (server-derived from due_at), and it's flagged
        $res->assertJsonPath('overdue_count', 1);
        $actions = $res->json('actions');
        $overdue = collect($actions)->firstWhere('late', true);
        $this->assertStringStartsWith('Overdue since', $overdue['due']);
        $noDate = collect($actions)->firstWhere('due', '');
        $this->assertFalse($noDate['late']);                   // null due → never overdue

        // enums served, dates formatted for display
        $res->assertJsonPath('applications.0.status', 'offer');
        $this->assertMatchesRegularExpression('/^\d{2} [A-Z][a-z]{2} \d{4}$/', $res->json('applications.0.sent'));
    }

    public function test_tracking_requires_a_student_session(): void
    {
        $this->getJson('/api/me/tracking')->assertStatus(401);
    }
}
