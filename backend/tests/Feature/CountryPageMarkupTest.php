<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The country editor's field list, checked against the pages it edits.
 *
 * This is the one defect in this feature that cannot announce itself. A list
 * declared here with no `data-crender` hook in the markup is not an error
 * anywhere: the endpoint saves it, the console shows it filled in, and the
 * public page quietly renders whatever is built into it. It has already
 * happened twice - fourteen country blocks shipped with hooks no renderer
 * listened to, and the USA page went months without a Top Admits section or a
 * recruiter strip while the schema had both - and both times it cost an editor
 * real work that no visitor ever saw.
 *
 * So: every declared list has a hook on every country page, every hook has a
 * field behind it, and all six pages agree on the set. Nothing here asserts
 * what the sections contain - the pages are hand-written and the renderer only
 * replaces a section when there is something to put in it.
 *
 * The test skips itself when the static site is not beside the backend. A
 * backend-only deploy is a supported shape and must not fail the suite over
 * files it does not have.
 */
class CountryPageMarkupTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $u = User::factory()->create([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enrolled_at' => now(),
        ]);
        UserRole::create(['user_id' => $u->id, 'role' => Role::ContentEditor->value, 'granted_at' => now()]);

        return $u->fresh();
    }

    /** The repo root, where the static pages live, or null when it is absent. */
    private function siteRoot(): ?string
    {
        $root = realpath(base_path('..'));

        return $root !== false && is_file($root.'/study-in-uk.html') ? $root : null;
    }

    private function grouped(): array
    {
        $this->actingAs($this->staff());

        return $this->getJson('/api/admin/content/singleton/countries')->assertOk()->json('grouped');
    }

    public function test_every_country_page_carries_a_hook_for_every_list_the_editor_offers(): void
    {
        $root = $this->siteRoot();
        if ($root === null) {
            $this->markTestSkipped('The static site is not beside the backend in this deploy.');
        }

        $grouped = $this->grouped();
        $declared = array_column($grouped['lists'], 'key');
        $seen = [];

        foreach ($grouped['groups'] as $group) {
            $file = $root.'/'.$group['page'];
            $this->assertFileExists($file, "{$group['label']} is offered for editing, so its page has to exist");

            $html = (string) file_get_contents($file);
            preg_match_all('/data-crender="([^"]+)"/', $html, $matches);
            $hooks = array_values(array_unique($matches[1]));
            sort($hooks);
            $seen[$group['slug']] = $hooks;

            // The body attribute is what js/render.js looks the bucket up by, so
            // a page whose slug does not match its group is editable and inert.
            $this->assertStringContainsString(
                'data-country="'.$group['slug'].'"',
                $html,
                "{$group['page']} must claim the slug the editor saves under",
            );

            foreach ($grouped['lists'] as $list) {
                // `absent_on` is how a page that genuinely has no such section
                // says so on screen. It has to stay honest in both directions.
                $absent = in_array($group['slug'], $list['absent_on'] ?? [], true);

                if ($absent) {
                    $this->assertNotContains($list['key'], $hooks,
                        "{$group['page']} does have a {$list['label']} section, so the editor must not say it has none");

                    continue;
                }

                $this->assertContains($list['key'], $hooks,
                    "{$list['label']} is editable for {$group['label']}, but {$group['page']} has nowhere to render it");
            }

            $this->assertSame([], array_values(array_diff($hooks, $declared)),
                "{$group['page']} advertises a section the editor cannot fill");
        }

        $sets = array_values(array_unique(array_map('json_encode', $seen)));
        $this->assertCount(1, $sets, 'the six country pages must offer the same sections: '.json_encode($seen));
    }

    /**
     * The seed classes, which are not decoration: js/render.js reads the crest
     * and avatar colours off the built-in cards before it replaces them
     * (cmods), so a page missing them repaints an edited section in the generic
     * palette and it stops matching the rest of the page.
     */
    public function test_every_country_page_seeds_the_colours_the_renderer_reads_back(): void
    {
        $root = $this->siteRoot();
        if ($root === null) {
            $this->markTestSkipped('The static site is not beside the backend in this deploy.');
        }

        foreach ($this->grouped()['groups'] as $group) {
            $html = (string) file_get_contents($root.'/'.$group['page']);

            $this->assertMatchesRegularExpression('/unic__logo--[a-z0-9]+/', $html, $group['page']);
            $this->assertMatchesRegularExpression('/admit__ava--[a-z0-9]+/', $html, $group['page']);
            $this->assertStringContainsString('class="rlogo"', $html, $group['page']);
        }
    }

    /**
     * The per-row image id, and the dead hook it replaces.
     *
     * js/render.js used to stamp data-media="country_<slug>_uni<N>" on every
     * crest. Only ten named slots are registered server-side, so it resolved to
     * nothing on every page - and keying a picture by row NUMBER is what a
     * per-row id exists to avoid: reorder the list and the logos stay behind.
     */
    public function test_the_renderer_reads_the_image_id_off_the_row(): void
    {
        $root = $this->siteRoot();
        if ($root === null) {
            $this->markTestSkipped('The static site is not beside the backend in this deploy.');
        }

        $render = (string) file_get_contents($root.'/js/render.js');

        $this->assertMatchesRegularExpression('/esc\(u\.img/', $render,
            'the university crest must read the img key this schema declares');
        $this->assertMatchesRegularExpression('/esc\(r\.img/', $render,
            'the admit avatar must read the img key this schema declares');
        $this->assertStringNotContainsString('data-media="country_', $render,
            'the country pass must not emit a media slot that was never registered');
    }
}
