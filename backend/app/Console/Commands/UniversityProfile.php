<?php

namespace App\Console\Commands;

use App\Models\Catalogue\Institution;
use App\Models\ContentAuditLog;
use App\Services\ImageOptimiser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Write a full editorial profile onto one catalogue institution from a JSON file.
 *
 * WHY A COMMAND AND NOT JUST THE ADMIN FORM
 * The form is the right tool for an editor changing a paragraph. It is the wrong
 * tool for the first fill of a profile, which is ~50 fields across 13 sections
 * including nine repeaters: done by hand it is an hour of clicking with no
 * record of where each figure came from, and no way to do the next university
 * the same way. This takes a reviewed file, writes it in one transaction, and
 * leaves the file in the repository as the citation trail.
 *
 * The form remains the authority on SHAPE — every key below exists because
 * UniversityForm renders it, and the allow-list is checked against the model's
 * own fillable, so a typo is an error here rather than a field that silently
 * never appears on the public page.
 *
 * IMAGES GO THROUGH THE SAME PIPELINE AS AN UPLOAD
 * Not a shortcut past it. A file named in `images` is read, refused unless
 * ImageOptimiser accepts the actual bytes, re-encoded and downscaled to the same
 * per-surface cap the form uses, given a content-derived name, and written to
 * the same `public` disk directory. The stored value is the same kind of path
 * the form would have stored, so nothing downstream can tell the difference.
 *
 * SAFETY
 *  - only keys in FIELDS are writable, and each must be in $fillable
 *  - --dry-run prints the diff and writes nothing
 *  - existing values are only overwritten when the file names that key, so a
 *    partial file tops up a profile instead of blanking the rest of it
 *  - every run writes a content-audit row naming the file it came from
 */
class UniversityProfile extends Command
{
    protected $signature = 'university:profile
        {id : the institution id}
        {--file= : JSON profile, relative to the backend directory}
        {--images= : directory holding any files named in the JSON "images" block}
        {--dry-run : show what would change and write nothing}';

    protected $description = 'Fill a university profile from a reviewed JSON file';

    /**
     * Every writable field, grouped as the admin form groups them. The comment
     * on each group is the tab it fills on the public detail page, because the
     * point of a profile is what a student ends up reading.
     */
    private const FIELDS = [
        // Identity — page header and every search card
        'name', 'tagline', 'country', 'province_state', 'city', 'website',

        // At a glance — these decide which searches the university appears in
        'vfi_represented', 'is_major_city', 'has_own_english_test', 'interview_required',
        'affordability_band', 'offer_tat_band', 'offer_acceptance_band', 'tuition_deposit_policy',

        // Overview tab
        'overview', 'overview_stats_json',

        // Ranking tab
        'rankings_json', 'ranking_note',

        // Intakes tab
        'intakes_json',

        // Cost to Study tab
        'cost_note', 'cost_rows_json', 'living_cost_note', 'accommodation_note',

        // Scholarships tab
        'scholarships_json',

        // Admissions tab
        'admissions_json', 'admission_academic', 'admission_english',

        // Placements tab
        'placement_rate', 'salary_note', 'placement_note', 'alumni_note',
        'recruiters_json', 'jobs_json',

        // Life on campus
        'services_json',

        // Gallery tab
        'gallery_json',

        // FAQs accordion
        'faqs_json',
    ];

    /**
     * Where each image field is stored and how far it is downscaled. Copied
     * deliberately from UniversityForm's FileUpload calls rather than invented:
     * a logo rendered at ~120px has no use for a 2400px original, and the hero
     * spans the page.
     */
    private const IMAGE_FIELDS = [
        'logo_key' => ['dir' => 'media/universities', 'max' => 800],
        'hero_image_key' => ['dir' => 'media/universities', 'max' => 2400],
        'gallery_json' => ['dir' => 'media/universities/gallery', 'max' => 2000],
    ];

    public function handle(ImageOptimiser $optimiser): int
    {
        $university = Institution::query()->find((int) $this->argument('id'));
        if (! $university) {
            $this->error('No institution with id '.$this->argument('id').'.');

            return self::FAILURE;
        }

        $path = (string) $this->option('file');
        if ($path === '' || ! is_file($path)) {
            $this->error('Pass --file=<path to the JSON profile>.');

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            $this->error('That file is not valid JSON.');

            return self::FAILURE;
        }

        // A key that is not writable is a mistake worth stopping for: silently
        // skipping it means someone writes a paragraph that never appears and
        // has no way to tell.
        $images = $data['images'] ?? [];
        unset($data['images']);

        $unknown = array_diff(array_keys($data), self::FIELDS);
        if ($unknown !== []) {
            $this->error('Not writable: '.implode(', ', $unknown));
            $this->line('Writable fields are: '.implode(', ', self::FIELDS));

            return self::FAILURE;
        }

        $notFillable = array_diff(array_keys($data), $university->getFillable());
        if ($notFillable !== []) {
            // The model is the last word. If this fires, FIELDS above has
            // drifted from $fillable and the write would be silently dropped.
            $this->error('In FIELDS but not fillable on the model: '.implode(', ', $notFillable));

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        // ---- images first: a failure here must not leave half a profile -----
        $stored = [];
        foreach ($images as $field => $files) {
            if (! isset(self::IMAGE_FIELDS[$field])) {
                $this->error('Not an image field: '.$field);

                return self::FAILURE;
            }

            $spec = self::IMAGE_FIELDS[$field];
            $paths = [];

            foreach ((array) $files as $file) {
                $full = rtrim((string) $this->option('images'), '/\\').'/'.$file;
                if (! is_file($full)) {
                    $this->error('Missing image: '.$full);

                    return self::FAILURE;
                }

                $bytes = (string) file_get_contents($full);
                if (! $optimiser->accepts($bytes)) {
                    // Sniffed from the bytes, not the extension - the same
                    // check the upload endpoint makes.
                    $this->error($file.' is not an image this pipeline accepts.');

                    return self::FAILURE;
                }

                $optimised = $optimiser->optimise($bytes, $spec['max']);
                $name = $optimiser->storageName($file, $optimiser->extensionFor($optimised));
                $target = $spec['dir'].'/'.$name;

                $this->line(sprintf(
                    '  %-14s %s  %s -> %s',
                    $field,
                    str_pad(number_format(strlen($bytes) / 1024, 0).' KB', 8, ' ', STR_PAD_LEFT),
                    number_format(strlen($optimised) / 1024, 0).' KB',
                    $target,
                ));

                if (! $dry) {
                    Storage::disk('public')->put($target, $optimised, 'public');
                }
                $paths[] = $target;
            }

            // gallery_json holds a list; the logo and hero hold one path.
            $stored[$field] = $field === 'gallery_json' ? $paths : ($paths[0] ?? null);
        }

        $write = array_merge($data, $stored);

        // ---- report ---------------------------------------------------------
        $this->newLine();
        $this->line('<options=bold>'.$university->name.'</> (id '.$university->id.')');
        foreach ($write as $key => $value) {
            $before = $university->getAttribute($key);
            $this->line(sprintf(
                '  %-22s %s  ->  %s',
                $key,
                str_pad($this->brief($before), 24),
                $this->brief($value),
            ));
        }

        if ($dry) {
            $this->newLine();
            $this->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $before = $university->only(array_keys($write));
        $university->fill($write)->save();

        ContentAuditLog::record(
            'university_profile', 'institutions', (string) $university->id,
            $before, $write + ['_source_file' => basename($path)],
        );

        $this->newLine();
        $this->info(count($write).' fields written to '.$university->name.'.');
        $this->line('Public page: /university.html?id='.$university->id);

        return self::SUCCESS;
    }

    /** A value shortened enough to scan a whole profile in one screen. */
    private function brief(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '<fg=gray>(empty)</>';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            return count($v).' item'.(count($v) === 1 ? '' : 's');
        }

        $s = preg_replace('/\s+/', ' ', (string) $v) ?? '';

        return mb_strlen($s) > 22 ? mb_substr($s, 0, 21).'…' : $s;
    }
}
