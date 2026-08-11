<?php

namespace Tests\Feature;

use App\Models\Catalogue\Institution;
use App\Models\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicUniversityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'catalogue.seed.universities_per_country' => 2,
            'catalogue.seed.programs_per_university' => 3,
            'catalogue.seed.base_year' => 2026,
        ]);
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();
    }

    public function test_directory_is_public_and_paged(): void
    {
        // no auth needed
        $res = $this->getJson('/api/universities')->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'country', 'location', 'programs']], 'meta' => ['total', 'page', 'last_page']]);

        // 5 countries × 2 unis = 10 institutions, 12 per page → one page
        $this->assertSame(10, $res->json('meta.total'));
        $this->assertStringContainsString('max-age', $res->headers->get('Cache-Control'));
    }

    public function test_country_filter(): void
    {
        $res = $this->getJson('/api/universities?country=United+Kingdom')->assertStatus(200);
        $this->assertSame(2, $res->json('meta.total'));
        foreach ($res->json('data') as $row) {
            $this->assertSame('United Kingdom', $row['country']);
        }
    }

    public function test_search_by_name_or_city(): void
    {
        $city = Institution::query()->value('city');
        $res = $this->getJson('/api/universities?q='.urlencode($city))->assertStatus(200);
        $this->assertGreaterThan(0, $res->json('meta.total'));
    }

    public function test_meta_lists_countries_with_counts(): void
    {
        $res = $this->getJson('/api/universities/meta')->assertStatus(200)
            ->assertJsonStructure(['countries' => [['country', 'count']], 'total']);
        $this->assertSame(10, $res->json('total'));
        $uk = collect($res->json('countries'))->firstWhere('country', 'United Kingdom');
        $this->assertSame(2, $uk['count']);
    }

    public function test_detail_returns_university_with_courses_and_related(): void
    {
        $id = Institution::query()->value('id');
        $res = $this->getJson("/api/universities/{$id}")->assertStatus(200)
            ->assertJsonPath('university.id', $id)
            ->assertJsonStructure(['university' => [
                'name', 'country', 'stats' => ['programs', 'levels', 'seasons'], 'courses' => [['id', 'title', 'level']], 'related',
            ]]);
        $this->assertSame(3, $res->json('university.stats.programs'));   // 3 programs/uni
        $this->assertLessThanOrEqual(4, count($res->json('university.related')));
    }

    public function test_detail_404_for_unknown(): void
    {
        $this->getJson('/api/universities/999999')->assertStatus(404);
    }

    public function test_detail_serves_admin_authored_page_defaults(): void
    {
        SiteContent::create(['key' => 'universityPage', 'value' => [
            'seasons' => [['key' => 'fall', 'month' => 'Sept', 'note' => 'Main intake', 'image' => 'media/x/fall.jpg']],
            'intake_footnote' => 'Apply early.',
            'cost_intro' => 'Costs at {university} vary.',
            'cost_footnote' => 'Indicative only.',
            'scholarship_note' => 'Ask us about {university} funding.',
            'faqs' => [['q' => 'Default Q?', 'a' => 'Default A.']],
            'interest_options' => [['label' => "Master's"], ['label' => 'MBA']],
        ]]);

        $id = Institution::query()->value('id');
        $this->getJson("/api/universities/{$id}")->assertStatus(200)
            ->assertJsonPath('defaults.seasons.fall.month', 'Sept')
            ->assertJsonPath('defaults.seasons.fall.image', '/storage/media/x/fall.jpg')
            ->assertJsonPath('defaults.intake_footnote', 'Apply early.')
            ->assertJsonPath('defaults.cost_intro', 'Costs at {university} vary.')
            ->assertJsonPath('defaults.faqs.0.q', 'Default Q?')
            ->assertJsonPath('defaults.interest_options.1', 'MBA');
    }

    public function test_intake_cards_carry_month_and_image(): void
    {
        $inst = Institution::query()->firstOrFail();
        $inst->update(['intakes_json' => [
            ['name' => 'Fall Intake', 'month' => 'September', 'note' => 'Main', 'image' => 'media/universities/intakes/f.jpg'],
        ]]);

        $this->getJson("/api/universities/{$inst->id}")->assertStatus(200)
            ->assertJsonPath('university.profile.intake_blocks.0.month', 'September')
            ->assertJsonPath('university.profile.intake_blocks.0.image', '/storage/media/universities/intakes/f.jpg');
    }

    public function test_detail_includes_editorial_profile_and_logo(): void
    {
        $inst = Institution::query()->firstOrFail();
        $inst->update([
            'tagline' => 'A great university', 'logo_key' => 'media/universities/x.png',
            'overview' => 'About this place.', 'ranking_world' => '#54',
            'scholarships_json' => [['name' => 'Merit', 'amount' => '£5,000', 'level' => 'PG']],
            'faqs_json' => [['q' => 'Is there an application fee?', 'a' => 'No.']],
            'gallery_json' => ['media/universities/gallery/g1.jpg'],
            'recruiters_json' => [['name' => 'Google']],
            'admission_english' => 'IELTS 6.5',
            'placement_note' => 'Strong graduate outcomes.',
        ]);

        $this->getJson("/api/universities/{$inst->id}")->assertStatus(200)
            ->assertJsonPath('university.tagline', 'A great university')
            ->assertJsonPath('university.logo', '/storage/media/universities/x.png')
            ->assertJsonPath('university.profile.overview', 'About this place.')
            ->assertJsonPath('university.profile.ranking.world', '#54')
            ->assertJsonPath('university.profile.scholarships.0.name', 'Merit')
            ->assertJsonPath('university.profile.faqs.0.q', 'Is there an application fee?')
            ->assertJsonPath('university.profile.gallery.0', '/storage/media/universities/gallery/g1.jpg')
            ->assertJsonPath('university.profile.placement.recruiters.0', 'Google');
    }
}
