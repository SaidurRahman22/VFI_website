<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student\DocumentAccessLog;
use App\Models\Student\StudentDocument;
use App\Services\DocumentStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 9A — lets a reviewing staff member open the file they are being asked to
 * judge. Deliberately NOT a durable link: the bytes are streamed on a
 * session-authenticated, admin-gated request and every open is written to
 * `document_access_log`, so "who looked at this passport, and when" is always
 * answerable. Nothing is cached and the file is never made public.
 */
class StaffDocumentController extends Controller
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /** GET /manage-files/documents/{document} — stream one student document. */
    public function download(Request $request, StudentDocument $document): Response
    {
        $file = $document->file;
        abort_if(! $file || ! $file->isReadable(), 404);

        $bytes = $this->storage->get($file->storage_key);
        abort_if($bytes === null, 404);

        DocumentAccessLog::record([
            'document_file_id' => $file->id,
            'student_id' => $document->student_id,
            'actor_user_id' => $request->user()?->id,
            'action' => 'staff_download',
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response($bytes, 200, [
            'Content-Type' => $file->mime,
            // inline so a reviewer can eyeball a scan without saving it locally
            'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; object-src 'none'",
        ]);
    }
}
