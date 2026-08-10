<?php

namespace Tests\Feature;

use App\Models\TaxonomyTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_served_taxonomy_groups_active_terms_by_kind(): void
    {
        $res = $this->getJson('/api/taxonomy')->assertStatus(200)
            ->assertJsonStructure(['vocabularies' => ['country', 'level', 'study_area', 'intake', 'nationality', 'test']]);

        // the 16 program levels + 3+ intakes are served
        $this->assertGreaterThanOrEqual(16, count($res->json('vocabularies.level')));
        $this->assertSame(['value' => 'master', 'label' => "Master's Degree"], collect($res->json('vocabularies.level'))->firstWhere('value', 'master'));
        $this->assertTrue(collect($res->json('vocabularies.intake'))->contains('value', 'summer'));

        $res->assertHeader('Cache-Control', 'max-age=300, public');
    }

    public function test_kinds_filter_narrows_the_response(): void
    {
        $res = $this->getJson('/api/taxonomy?kinds=country,test')->assertStatus(200);
        $this->assertSame(['country', 'test'], array_keys($res->json('vocabularies')));
    }

    public function test_inactive_terms_are_hidden(): void
    {
        TaxonomyTerm::where('kind', 'test')->where('value', 'gmat')->update(['active' => false]);

        $res = $this->getJson('/api/taxonomy?kinds=test')->assertStatus(200);
        $this->assertFalse(collect($res->json('vocabularies.test'))->contains('value', 'gmat'));
    }
}
