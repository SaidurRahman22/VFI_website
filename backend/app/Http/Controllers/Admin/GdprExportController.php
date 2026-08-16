<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentAuditLog;
use App\Models\DataSubjectRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 9B — hands the finished subject-access bundle to the staff member who
 * has to send it on.
 *
 * This is the single most sensitive download in the system: one file containing
 * everything VFI holds about one person. So it is deliberately not a link
 * anybody can keep — the bytes only leave on an admin-gated, session-
 * authenticated request (the route group does that), the artefact stays on the
 * private disk, and the fetch itself is written to the content audit log so
 * "who took a copy of this person's file, and when" is answerable.
 */
class GdprExportController extends Controller
{
    /** GET /manage-files/gdpr-export/{record} — download one export bundle. */
    public function download(Request $request, DataSubjectRequest $record): Response
    {
        // Deny by default: only a completed export with an artefact is fetchable.
        // 404 rather than 403 — a pending or erasure row should not even confirm
        // it exists as a downloadable thing.
        abort_if(! $record->isDownloadable(), 404);

        $disk = Storage::disk('local');
        abort_if(! $disk->exists($record->artifact_path), 404);

        // Audited BEFORE the bytes go out, so a download cannot happen without a
        // trail. No subject email or payload in the entry — the request id ties
        // it back to the register, which is where the PII belongs.
        ContentAuditLog::record(
            'gdpr_export_download',
            'data_subject_request',
            (string) $record->id,
            null,
            ['actor_user_id' => $request->user()?->id],
        );

        // Streamed rather than read into a string: a full subject bundle for a
        // long-running case can be large, and this endpoint sits on a panel used
        // by several staff at once.
        return $disk->download($record->artifact_path, 'vfi-data-export-'.$record->id.'.json', [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
