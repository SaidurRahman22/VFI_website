<?php

namespace Tests\Feature;

use App\Models\Content\Event;
use App\Models\ContentAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_update_delete_are_audited_with_before_after(): void
    {
        $e = Event::create(['legacy_id' => 'e1', 'position' => 0, 'title' => 'First']);
        $create = ContentAuditLog::where('entity', 'events')->where('action', 'create')->first();
        $this->assertNotNull($create);
        $this->assertNull($create->before);
        $this->assertSame('First', $create->after['title']);

        $e->update(['title' => 'Renamed']);
        $update = ContentAuditLog::where('action', 'update')->first();
        $this->assertSame('First', $update->before['title']);
        $this->assertSame('Renamed', $update->after['title']);

        $e->delete();
        $this->assertSame(1, ContentAuditLog::where('action', 'delete')->count());
    }

    public function test_audit_log_is_append_only(): void
    {
        $row = ContentAuditLog::record('create', 'events', 'e1', null, ['title' => 'x']);

        try {
            $row->update(['action' => 'tampered']);
            $this->fail('update should be blocked');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('append-only', $ex->getMessage());
        }

        try {
            $row->delete();
            $this->fail('delete should be blocked');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('append-only', $ex->getMessage());
        }
    }

    public function test_import_writes_single_summary_row_not_per_row(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp_').'.json';
        file_put_contents($path, json_encode(['content' => [
            'events' => [['id' => 'e1', 'title' => 'A'], ['id' => 'e2', 'title' => 'B']],
            'settings' => ['brand' => 'VFI'],
        ]]));

        Artisan::call('content:import', ['file' => $path]);
        @unlink($path);

        $this->assertSame(0, ContentAuditLog::where('action', 'create')->count());  // per-row muted
        $this->assertSame(1, ContentAuditLog::where('action', 'import')->count());
        $summary = ContentAuditLog::where('action', 'import')->first()->after;
        $this->assertSame(2, $summary['events']);
    }
}
