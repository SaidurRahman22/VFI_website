<?php

namespace App\Http\Controllers\Me;

use App\Enums\DocumentStatus;
use App\Enums\ScanStatus;
use App\Http\Controllers\Controller;
use App\Models\Student\DocumentAccessLog;
use App\Models\Student\DocumentFile;
use App\Models\Student\DocumentType;
use App\Models\Student\Student;
use App\Models\Student\StudentDocument;
use App\Services\DocumentChecklist;
use App\Services\DocumentScanner;
use App\Services\DocumentStorage;
use App\Services\StudentProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 5C/5D — the document checklist + the scan-gated upload pipeline
 * (docs §2–3). Implicit-self throughout. A blob is written to private storage
 * but is unreadable until the scanner returns clean; downloads are single-use,
 * short-TTL, opaque-token URLs and every read is logged.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentChecklist $checklist,
        private readonly DocumentStorage $storage,
        private readonly DocumentScanner $scanner,
        private readonly StudentProfileService $profiles,
    ) {
    }

    /** GET /api/me/documents */
    public function index(Request $request): JsonResponse
    {
        return $this->payload(Student::resolveFor($request->user()));
    }

    /** POST /api/me/documents/{type} — multipart upload (must_verify-gated route). */
    public function store(Request $request, string $type): JsonResponse
    {
        $student = Student::resolveFor($request->user());
        $docType = $this->docType($type);

        $existing = StudentDocument::withTrashed()
            ->where('student_id', $student->id)->where('document_type_id', $docType->id)->first();
        if ($existing && ! $existing->trashed() && $existing->status === DocumentStatus::Verified) {
            return $this->error('A verified document is locked. Ask your counsellor to update it.', 409);
        }

        $request->validate([
            'file' => [
                'required', 'file',
                'max:'.intdiv((int) config('documents.max_bytes'), 1024),        // KB
                'mimetypes:'.implode(',', config('documents.allowed_mimes')),    // content-based
            ],
        ]);

        $upload = $request->file('file');
        $bytes = (string) file_get_contents($upload->getRealPath());

        // Magic-byte sniff — never trust the extension (the <input> has no accept attr).
        if (! $this->magicBytesAllowed($bytes)) {
            return $this->error('Only PDF, JPG or PNG files are accepted.', 422);
        }

        $sha = hash('sha256', $bytes);

        // Idempotency: re-picking the same bytes for the same type reuses the blob.
        $file = DocumentFile::where('student_id', $student->id)
            ->where('document_type_id', $docType->id)
            ->where('sha256', $sha)->where('scan_status', ScanStatus::Clean)->first();

        if (! $file) {
            $key = $this->storage->put($bytes);
            $file = DocumentFile::create([
                'student_id' => $student->id, 'document_type_id' => $docType->id,
                'storage_key' => $key,
                'original_name' => $this->safeName($upload->getClientOriginalName()),
                'mime' => $upload->getMimeType(), 'size' => strlen($bytes), 'sha256' => $sha,
                'scan_status' => ScanStatus::Pending,
            ]);
            $this->log($file, $student, $request, 'upload');

            // Scan-gate: unreadable until clean.
            try {
                $verdict = $this->scanner->scan($bytes);
            } catch (\Throwable $e) {
                // No verdict → fail closed: drop the bytes, leave nothing readable.
                $this->storage->delete($key);
                $file->forceFill(['scan_status' => ScanStatus::Infected])->save();

                return $this->error('We could not scan that file right now. Please try again shortly.', 503);
            }

            if ($verdict === DocumentScanner::INFECTED) {
                $this->storage->delete($key);                       // never keep infected bytes
                $file->forceFill(['scan_status' => ScanStatus::Infected])->save();
                $this->log($file, $student, $request, 'quarantine');

                return $this->error('That file did not pass our security scan and was not saved.', 422);
            }

            $file->forceFill(['scan_status' => ScanStatus::Clean])->save();
        }

        // Link (restore a soft-deleted row rather than violating the unique index).
        $doc = StudentDocument::withTrashed()
            ->firstOrNew(['student_id' => $student->id, 'document_type_id' => $docType->id]);
        $doc->forceFill([
            'status' => DocumentStatus::Uploaded, 'file_id' => $file->id,
            'uploaded_at' => now(), 'rejection_reason' => null, 'deleted_at' => null,
        ])->save();

        return $this->payload($student, 201);
    }

    /** GET /api/me/documents/{type}/download — mint a single-use, short-TTL URL. */
    public function download(Request $request, string $type): JsonResponse
    {
        $student = Student::resolveFor($request->user());
        $docType = $this->docType($type);

        $doc = StudentDocument::with('file')
            ->where('student_id', $student->id)->where('document_type_id', $docType->id)->first();
        $file = $doc?->file;
        if (! $file || ! $file->isReadable()) {
            return $this->error('That document is not available to download.', 404);
        }

        $token = Str::random(48);
        $ttl = (int) config('documents.download_ttl', 120);
        Cache::put("docdl:$token", [
            'file_id' => $file->id, 'student_id' => $student->id, 'actor' => $request->user()->id,
        ], now()->addSeconds($ttl));

        $this->log($file, $student, $request, 'presign');

        return response()->json([
            'url' => url('/api/documents/dl/'.$token),
            'expires_in' => $ttl,
            'name' => $file->original_name,
        ])->header('Cache-Control', 'no-store');
    }

    /** GET /api/documents/dl/{token} — PUBLIC, single-use. The token is the capability. */
    public function stream(Request $request, string $token): Response|StreamedResponse
    {
        $data = Cache::pull("docdl:$token");   // pull = read + forget → single-use
        if (! $data) {
            abort(404);
        }

        $file = DocumentFile::find($data['file_id']);
        if (! $file || ! $file->isReadable()) {
            abort(404);
        }

        $bytes = $this->storage->get($file->storage_key);
        if ($bytes === null) {
            abort(404);
        }

        DocumentAccessLog::record([
            'document_file_id' => $file->id, 'student_id' => $data['student_id'],
            'actor_user_id' => $data['actor'] ?? null, 'action' => 'download',
            'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response($bytes, 200, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => 'attachment; filename="'.addslashes($file->original_name).'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** DELETE /api/me/documents/{type} — soft-delete → back to missing (blob kept). */
    public function destroy(Request $request, string $type): JsonResponse
    {
        $student = Student::resolveFor($request->user());
        $docType = $this->docType($type);

        $doc = StudentDocument::where('student_id', $student->id)
            ->where('document_type_id', $docType->id)->first();
        if (! $doc) {
            return $this->payload($student);   // already missing — no-op
        }
        if ($doc->status === DocumentStatus::Verified) {
            return $this->error('A verified document is locked and cannot be removed.', 409);
        }

        if ($doc->file) {
            $this->log($doc->file, $student, $request, 'delete');
        }
        $doc->delete();   // soft-delete; blob + file row + audit retained

        return $this->payload($student);
    }

    // ---- helpers ----

    private function docType(string $key): DocumentType
    {
        return DocumentType::where('key', $key)->firstOr(fn () => abort(404, 'Unknown document type.'));
    }

    /** Both packs + completeness (so the client re-renders docs and the meter). */
    private function payload(Student $student, int $status = 200): JsonResponse
    {
        return response()->json([
            'application' => $this->checklist->full($student, 'application'),
            'visa' => $this->checklist->full($student, 'visa'),
            'destinations' => $student->destinations()->pluck('destination')->all(),
            'completeness' => $this->profiles->completeness($student->fresh()),
        ], $status)->header('Cache-Control', 'no-store');
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status)->header('Cache-Control', 'no-store');
    }

    private function magicBytesAllowed(string $bytes): bool
    {
        return str_starts_with($bytes, '%PDF-')                          // PDF
            || str_starts_with($bytes, "\xFF\xD8\xFF")                   // JPEG
            || str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A");       // PNG
    }

    /** Sanitised display name only — basename, control chars stripped, ≤120. */
    private function safeName(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '', basename($name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return mb_substr(trim($name) ?: 'document', 0, 120);
    }

    private function log(DocumentFile $file, Student $student, Request $request, string $action): void
    {
        DocumentAccessLog::record([
            'document_file_id' => $file->id, 'student_id' => $student->id,
            'actor_user_id' => $request->user()?->id, 'action' => $action,
            'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
