<?php

namespace Tests\Feature;

use App\Models\Catalogue\Institution;
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
}
