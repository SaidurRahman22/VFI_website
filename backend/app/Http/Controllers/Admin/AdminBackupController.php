<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentAuditLog;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3G — backup export + guarded restore. OWNER ONLY (superadmin). Export
 * is a downloadable snapshot; import replaces all content and is the single
 * highest-risk action in the admin, so it: validates the payload shape, caps
 * its size, ALWAYS snapshots the current state first, and audits the restore.
 */
class AdminBackupController extends Controller
{
    private const MAX_IMPORT_BYTES = 8 * 1024 * 1024;   // 8 MB payload ceiling

    public function __construct(private readonly BackupService $backups) {}

    public function export(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isOwner(), 403);

        $payload = $this->backups->export();
        ContentAuditLog::record('export', 'backup', null, null, ['at' => $payload['exportedAt']]);

        return response()->json($payload)
            ->header('Cache-Control', 'no-store')
            ->header('Content-Disposition', 'attachment; filename="vfi-backup.json"');
    }

    public function import(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isOwner(), 403);

        $payload = $request->input('payload', $request->all());
        if (! is_array($payload)) {
            return response()->json(['message' => 'Backup payload must be an object.'], 422);
        }
        if (strlen(json_encode($payload)) > self::MAX_IMPORT_BYTES) {
            return response()->json(['message' => 'Backup is too large.'], 422);
        }

        try {
            $content = $this->backups->validate($payload);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // ALWAYS snapshot before mutating — a bad restore stays recoverable.
        $snapshot = $this->backups->snapshot('pre-restore by user '.$request->user()->id);
        $this->backups->restore($content);
        ContentAuditLog::record('restore', 'backup', null, null, ['snapshot' => $snapshot]);

        return response()->json(['ok' => true, 'snapshot' => $snapshot])->header('Cache-Control', 'no-store');
    }
}
