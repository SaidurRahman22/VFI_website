<?php

namespace App\Console\Commands;

use App\Models\Catalogue\Institution;
use Illuminate\Console\Command;

/**
 * Phase 8+ — bulk-load the editorial university profile from a CSV, so staff can
 * fill ranking / logos / placement copy for hundreds of universities at once
 * instead of opening each record in the admin.
 *
 * Match a row to a university by `id`, or by `name` (+ optional `country`).
 * Only the columns present in the CSV are touched, and a blank cell is skipped —
 * so a partial sheet (say, just rankings) never wipes other content.
 *
 *   php artisan universities:import rankings.csv
 *   php artisan universities:import rankings.csv --overwrite   (replace existing values)
 *   php artisan universities:import rankings.csv --dry-run
 *
 * Supported columns (header row, any subset):
 *   id, name, country, tagline, website, logo_url, hero_url,
 *   ranking_world, ranking_national, ranking_note,
 *   overview, cost_note, living_cost_note, accommodation_note,
 *   admission_academic, admission_english,
 *   placement_rate, salary_note, alumni_note
 */
class ImportUniversityProfiles extends Command
{
    protected $signature = 'universities:import
        {file : path to a UTF-8 CSV with a header row}
        {--overwrite : replace values that are already set (default: only fill blanks)}
        {--dry-run : report what would change without writing}';

    protected $description = 'Bulk-import editorial university profile fields from a CSV';

    /** Columns that map straight onto an institution attribute. */
    private const FIELDS = [
        'tagline' => 190, 'website' => 190, 'ranking_world' => 60, 'ranking_national' => 60,
        'ranking_note' => 190, 'overview' => 5000, 'cost_note' => 5000, 'living_cost_note' => 190,
        'accommodation_note' => 190, 'admission_academic' => 5000, 'admission_english' => 5000,
        'placement_rate' => 30, 'salary_note' => 190, 'alumni_note' => 190,
    ];

    /** CSV header => column, for the image fields (stored as a key/URL). */
    private const IMAGE_FIELDS = ['logo_url' => 'logo_key', 'hero_url' => 'hero_image_key'];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        if (! $header) {
            $this->error('Empty CSV.');
            fclose($fh);

            return self::FAILURE;
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $header);

        $overwrite = (bool) $this->option('overwrite');
        $dry = (bool) $this->option('dry-run');
        $updated = $skipped = $missing = 0;
        $line = 1;

        while (($raw = fgetcsv($fh)) !== false) {
            $line++;
            if ($raw === [null] || $raw === []) {
                continue;
            }
            $row = @array_combine($header, array_pad(array_slice($raw, 0, count($header)), count($header), null));
            if (! $row) {
                $this->warn("  line {$line}: column count mismatch — skipped");
                $skipped++;

                continue;
            }

            $inst = $this->resolve($row);
            if (! $inst) {
                $this->warn("  line {$line}: no university matched (".trim((string) ($row['name'] ?? $row['id'] ?? '?')).')');
                $missing++;

                continue;
            }

            $patch = [];
            foreach (self::FIELDS as $col => $max) {
                if (! array_key_exists($col, $row)) {
                    continue;
                }
                $v = trim((string) $row[$col]);
                if ($v === '' || (! $overwrite && filled($inst->{$col}))) {
                    continue;
                }
                $patch[$col] = mb_substr($v, 0, $max);
            }
            foreach (self::IMAGE_FIELDS as $csv => $col) {
                if (! array_key_exists($csv, $row)) {
                    continue;
                }
                $v = trim((string) $row[$csv]);
                if ($v === '' || (! $overwrite && filled($inst->{$col}))) {
                    continue;
                }
                $patch[$col] = mb_substr($v, 0, 190);
            }

            if ($patch === []) {
                $skipped++;

                continue;
            }
            if ($dry) {
                $this->line("  would update #{$inst->id} {$inst->name}: ".implode(', ', array_keys($patch)));
            } else {
                $inst->forceFill($patch)->save();
            }
            $updated++;
        }
        fclose($fh);

        $this->info(($dry ? '[dry run] ' : '')."Updated {$updated}, skipped {$skipped}, unmatched {$missing}.");

        return self::SUCCESS;
    }

    private function resolve(array $row): ?Institution
    {
        if (! empty($row['id']) && is_numeric($row['id'])) {
            return Institution::find((int) $row['id']);
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $q = Institution::whereRaw('lower(name) = ?', [mb_strtolower($name)]);
        if (! empty($row['country'])) {
            $q->where('country', trim((string) $row['country']));
        }

        return $q->first();
    }
}
