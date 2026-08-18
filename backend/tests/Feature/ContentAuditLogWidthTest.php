<?php

namespace Tests\Feature;

use App\Models\ContentAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A stopgap for a Postgres-only failure mode the rest of the suite cannot see.
 *
 * Production runs Postgres, which rejects a value longer than its varchar
 * declares (SQLSTATE 22001). Tests run SQLite, which ignores varchar length
 * entirely. So an audit write with an over-long `action` passes every test and
 * then takes down the request that made it — the audit row is written inside
 * the same unit of work as the thing it records, so its rejection rolls the
 * whole operation back. That is exactly how `partner_document_upload` (23 chars
 * into a varchar(20)) turned a working upload into a 500.
 *
 * This test reads the action/entity literals straight out of the source and
 * measures them, so it fails on SQLite for the same reason Postgres would.
 *
 * It is NOT the real fix. The real fix is running this suite against Postgres,
 * where the database itself is the assertion and this whole class of bug —
 * varchar widths, strict types, RLS — stops being invisible. Until then, this
 * covers the one table where the failure is silent and load-bearing.
 */
class ContentAuditLogWidthTest extends TestCase
{
    use RefreshDatabase;

    /** Must track the migrations; see 2026_08_17_120000 and the create migration. */
    private const ACTION_MAX = 64;

    private const ENTITY_MAX = 40;

    /** See 2026_08_19_000001. Holds a storage PATH for media, not just an id. */
    private const ENTITY_ID_MAX = 512;

    public function test_every_audit_action_and_entity_literal_fits_its_column(): void
    {
        $calls = $this->auditCalls();

        $this->assertNotEmpty($calls, 'Found no ContentAuditLog::record() calls — has the call shape changed?');

        foreach ($calls as [$file, $action, $entity]) {
            $this->assertLessThanOrEqual(
                self::ACTION_MAX,
                strlen($action),
                "Audit action '{$action}' is ".strlen($action).' chars but content_audit_log.action holds '
                .self::ACTION_MAX.". Postgres would reject this write and fail the request in {$file}."
            );

            $this->assertLessThanOrEqual(
                self::ENTITY_MAX,
                strlen($entity),
                "Audit entity '{$entity}' is ".strlen($entity).' chars but content_audit_log.entity holds '
                .self::ENTITY_MAX." ({$file})."
            );
        }
    }

    /**
     * The widths asserted above are only meaningful if they still match the
     * schema, so this pins them to the migration rather than to my memory of it.
     */
    public function test_the_asserted_widths_match_the_actual_schema(): void
    {
        $sql = file_get_contents(database_path('migrations/2026_08_17_120000_widen_content_audit_log_action.php'));

        $this->assertStringContainsString(
            "string('action', ".self::ACTION_MAX.')',
            $sql,
            'content_audit_log.action was resized without updating ACTION_MAX here.'
        );
    }

    /** The longest real action survives a round trip through the DB. */
    public function test_the_longest_action_actually_writes(): void
    {
        $longest = collect($this->auditCalls())->pluck(1)->sortByDesc(fn ($a) => strlen($a))->first();

        $row = ContentAuditLog::record($longest, 'student_document', '1', null, ['ok' => true]);

        $this->assertSame($longest, $row->fresh()->action);
    }

    /**
     * @return list<array{0:string,1:string,2:string}> [file, action, entity]
     */
    private function auditCalls(): array
    {
        $out = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $src = file_get_contents($file->getPathname());

            // Only literal-literal calls are checkable; a variable action is not
            // measurable here and is deliberately skipped rather than guessed at.
            if (preg_match_all(
                "/ContentAuditLog::record\(\s*'([^']*)'\s*,\s*'([^']*)'/",
                $src,
                $m,
                PREG_SET_ORDER
            )) {
                foreach ($m as $hit) {
                    $out[] = [basename($file->getPathname()), $hit[1], $hit[2]];
                }
            }
        }

        return $out;
    }

    /**
     * entity_id overflowed the same way `action` did, one column over. The media
     * library writes the stored path into it, and
     * /storage/media/<64-char hash>.jpg is 82 characters against a varchar(64) —
     * so on Postgres the audit insert failed and took the upload down with it,
     * while SQLite accepted it silently.
     */
    public function test_a_media_path_fits_in_entity_id(): void
    {
        $path = '/storage/media/'.str_repeat('a', 64).'.jpg';

        $this->assertLessThanOrEqual(
            self::ENTITY_ID_MAX,
            strlen($path),
            'A media path no longer fits content_audit_log.entity_id, which would fail the upload it audits.'
        );

        $row = ContentAuditLog::record('create', 'media', $path, null, ['bytes' => 1]);
        $this->assertSame($path, $row->fresh()->entity_id);
    }

    public function test_the_entity_id_width_matches_the_migration(): void
    {
        $sql = file_get_contents(database_path('migrations/2026_08_19_000001_widen_content_audit_log_entity_id.php'));

        $this->assertStringContainsString(
            "string('entity_id', ".self::ENTITY_ID_MAX.')',
            $sql,
            'content_audit_log.entity_id was resized without updating ENTITY_ID_MAX here.'
        );
    }
}
