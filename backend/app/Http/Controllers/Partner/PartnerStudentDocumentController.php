<?php

namespace App\Http\Controllers\Partner;

use App\Enums\DocumentStatus;
use App\Enums\ScanStatus;
use App\Http\Controllers\Controller;
use App\Models\ContentAuditLog;
use App\Models\Student\DocumentAccessLog;
use App\Models\Student\DocumentFile;
use App\Models\Student\DocumentType;
use App\Models\Student\Student;
use App\Models\Student\StudentDocument;
use App\Services\DocumentScanner;
use App\Services\DocumentStorage;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Phase 9D — the agency supplies its student's paperwork.
 *
 * The real workflow is that an agency files an application ON THE STUDENT'S
 * BEHALF, but until now only the student could upload (through their own
 * portal), so a partner-filed application reached staff with nothing to check.
 * This is the missing half.
 *
 * Documents stay owned by the STUDENT (Phase 5: one checklist per student, not
 * per application) — this controller is a second door onto the SAME pipeline as
 * App\Http\Controllers\Me\DocumentController, never a second pipeline: same size
 * cap, same content-based mime + magic-byte sniff, same private-disk UUID key,
 * same scan-gate, same sha256 dedupe, same append-only access log. The only
 * difference is who the actor is and how the student is resolved: the id in the
 * URL is client-controlled, so it is fenced to the SESSION agency and a foreign
 * or unknown id 404s — a 403 would confirm the row exists under someone else.
 */
class PartnerStudentDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DocumentScanner $scanner,
    ) {}

    /** GET /api/partner/students/{student}/documents — both packs, one flat list. */
    public function index(Request $request, int $student): JsonResponse
    {
        return $this->payload($this->ownedStudent($student));
    }

    /** POST /api/partner/students/{student}/documents/{type} — multipart upload. */
    public function store(Request $request, int $student, string $type): JsonResponse
    {
        $owned = $this->ownedStudent($student);
        $docType = $this->docType($type);

        $existing = StudentDocument::withTrashed()
            ->where('student_id', $owned->id)->where('document_type_id', $docType->id)->first();
        if ($existing && ! $existing->trashed() && $existing->status === DocumentStatus::Verified) {
            return $this->error('A verified document is locked. Ask the VFI desk to reopen it.', 409);
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

        // Magic-byte sniff — the declared type and the extension are both the
        // uploader's word for it, and here the uploader is a third party.
        if (! $this->magicBytesAllowed($bytes)) {
            return $this->error('Only PDF, JPG or PNG files are accepted.', 422);
        }

        $sha = hash('sha256', $bytes);

        // Idempotency: re-sending the same bytes for the same slot (double-click,
        // retried request) reuses the blob instead of storing it twice.
        $file = DocumentFile::where('student_id', $owned->id)
            ->where('document_type_id', $docType->id)
            ->where('sha256', $sha)->where('scan_status', ScanStatus::Clean)->first();

        if (! $file) {
            $key = $this->storage->put($bytes);
            $file = DocumentFile::create([
                'student_id' => $owned->id, 'document_type_id' => $docType->id,
                'storage_key' => $key,
                'original_name' => $this->safeName($upload->getClientOriginalName()),
                'mime' => $upload->getMimeType(), 'size' => strlen($bytes), 'sha256' => $sha,
                'scan_status' => ScanStatus::Pending,
            ]);
            $this->log($file, $owned, $request, 'upload');

            // Scan-gate: on private storage but unreadable until clean.
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
                $this->log($file, $owned, $request, 'quarantine');

                return $this->error('That file did not pass our security scan and was not saved.', 422);
            }

            $file->forceFill(['scan_status' => ScanStatus::Clean])->save();
        }

        $before = $existing && ! $existing->trashed()
            ? ['status' => $existing->status->value, 'file_id' => $existing->file_id, 'rejection_reason' => $existing->rejection_reason]
            : ['status' => 'missing', 'file_id' => null, 'rejection_reason' => null];

        // Link (restore a soft-deleted row rather than violating the unique index).
        // Replacing a REJECTED slot clears the reason: the objection was to the
        // old file, and leaving it would keep showing the student a stale refusal.
        $doc = StudentDocument::withTrashed()
            ->firstOrNew(['student_id' => $owned->id, 'document_type_id' => $docType->id]);
        $doc->forceFill([
            'status' => DocumentStatus::Uploaded, 'file_id' => $file->id,
            'uploaded_at' => now(), 'rejection_reason' => null, 'deleted_at' => null,
        ])->save();

        // Someone other than the data subject changed the record — audit the state
        // move as well as the blob access.
        ContentAuditLog::record('partner_document_upload', 'student_document', (string) $doc->id, $before, [
            'status' => DocumentStatus::Uploaded->value,
            'file_id' => $file->id,
            'rejection_reason' => null,
            'agency_id' => $owned->agency_id,
        ]);

        return $this->payload($owned, 201);
    }

    /** DELETE /api/partner/students/{student}/documents/{type} — back to missing. */
    public function destroy(Request $request, int $student, string $type): JsonResponse
    {
        $owned = $this->ownedStudent($student);
        $docType = $this->docType($type);

        $doc = StudentDocument::with('file')
            ->where('student_id', $owned->id)->where('document_type_id', $docType->id)->first();
        if (! $doc) {
            return $this->payload($owned);   // already missing — no-op
        }
        if ($doc->status === DocumentStatus::Verified) {
            return $this->error('A verified document is locked and cannot be removed.', 409);
        }

        if ($doc->file) {
            $this->log($doc->file, $owned, $request, 'delete');
        }
        ContentAuditLog::record('partner_document_delete', 'student_document', (string) $doc->id, [
            'status' => $doc->status->value, 'file_id' => $doc->file_id,
        ], ['status' => 'missing', 'agency_id' => $owned->agency_id]);

        $doc->delete();   // soft-delete; blob + file row + audit retained

        return $this->payload($owned);
    }

    /** GET /api/partner/students/{student}/documents/{type}/download — mint a single-use URL. */
    public function download(Request $request, int $student, string $type): JsonResponse
    {
        $owned = $this->ownedStudent($student);
        $docType = $this->docType($type);

        $doc = StudentDocument::with('file')
            ->where('student_id', $owned->id)->where('document_type_id', $docType->id)->first();
        $file = $doc?->file;
        if (! $file || ! $file->isReadable()) {
            return $this->error('That document is not available to download.', 404);
        }

        // Same opaque-token capability the student path mints, redeemed by the one
        // existing public stream route. Never a durable URL: the token is single-use
        // (the stream pulls it from the cache) and expires in seconds.
        $token = Str::random(48);
        $ttl = (int) config('documents.download_ttl', 120);
        Cache::put("docdl:$token", [
            'file_id' => $file->id, 'student_id' => $owned->id, 'actor' => $request->user()->id,
        ], now()->addSeconds($ttl));

        $this->log($file, $owned, $request, 'presign');

        return response()->json([
            'url' => url('/api/documents/dl/'.$token),
            'expires_in' => $ttl,
            'name' => $file->original_name,
        ])->header('Cache-Control', 'no-store');
    }

    // ---- helpers ----

    /**
     * The student id comes from the URL, so it is untrusted. Student carries no
     * global scope (the portal reads it self-scoped with no tenant in context),
     * so the console fences it EXPLICITLY by the session agency. Cast the tenant:
     * with no agency bound this matches nothing and 404s rather than opening up.
     */
    private function ownedStudent(int $student): Student
    {
        $agencyId = (int) app(TenantContext::class)->agencyId();

        return Student::forAgency($agencyId)->whereKey($student)->firstOr(function () {
            abort(404, 'Student not found.');
        });
    }

    private function docType(string $key): DocumentType
    {
        return DocumentType::where('key', $key)->firstOr(fn () => abort(404, 'Unknown document type.'));
    }

    /**
     * Both packs in one list, each row flagged with its pack — the console shows
     * application readiness and visa readiness on the same student card.
     *
     * Two queries joined in PHP (types, then this student's rows with their files
     * eager-loaded). A query per type would be a dozen round-trips every time a
     * case is opened, on a console doing thousands of requests an hour.
     */
    private function payload(Student $student, int $status = 200): JsonResponse
    {
        $types = DocumentType::orderBy('pack')->orderBy('position')->get();   // 'application' sorts before 'visa'
        $byType = StudentDocument::with('file')
            ->where('student_id', $student->id)->get()->keyBy('document_type_id');

        $data = $types->map(function (DocumentType $type) use ($byType) {
            $doc = $byType->get($type->id);
            $file = $doc?->file;

            return [
                'type' => $type->key,
                'name' => $type->name,
                'pack' => $type->pack,
                'status' => $doc?->status->value ?? 'missing',
                'uploaded_at' => optional($doc?->uploaded_at)->toIso8601String(),
                'rejection_reason' => $doc?->rejection_reason,
                'file' => $file ? [
                    'original_name' => $file->original_name,
                    'size' => $file->size,
                    'mime' => $file->mime,
                    'scan_status' => $file->scan_status->value,
                ] : null,
            ];
        })->values()->all();

        return response()->json(['data' => $data], $status)->header('Cache-Control', 'no-store');
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

    /** The PARTNER user is the actor — the student did not touch this file. */
    private function log(DocumentFile $file, Student $student, Request $request, string $action): void
    {
        DocumentAccessLog::record([
            'document_file_id' => $file->id, 'student_id' => $student->id,
            'actor_user_id' => $request->user()?->id, 'action' => $action,
            'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
