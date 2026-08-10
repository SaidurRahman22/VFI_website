<?php

namespace App\Http\Controllers\Partner;

use App\Enums\ScanStatus;
use App\Http\Controllers\Controller;
use App\Models\AuthEvent;
use App\Models\Partner\ProgramRequest;
use App\Models\Partner\ProgramRequestDocument;
use App\Models\Student\Student;
use App\Services\DocumentScanner;
use App\Services\DocumentStorage;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 7 — enquiries (program requests) + scan-gated academic-doc intake
 * (docs §5). Tenant-scoped. Uploaded transcripts go through the exact Phase 5
 * pipeline: content-mime + magic-byte → private disk → scan-gate (unreadable
 * until clean; EICAR quarantined) → single-use signed retrieval.
 */
class PartnerEnquiryController extends Controller
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DocumentScanner $scanner,
    ) {
    }

    /** POST /api/partner/enquiries — multipart (fields + #ppProgFiles). */
    public function store(Request $request): JsonResponse
    {
        $agencyId = app(TenantContext::class)->agencyId();

        $data = $request->validate([
            'enquiry_type' => ['required', Rule::in(['new', 'existing'])],
            'student_id' => ['nullable', 'integer', 'required_if:enquiry_type,existing'],
            'first_name' => ['nullable', 'string', 'max:60', 'required_if:enquiry_type,new'],
            'last_name' => ['nullable', 'string', 'max:70'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'country_of_education' => ['nullable', 'string', 'max:90'],
            'highest_education_level' => ['nullable', 'string', 'max:90'],
            'destination' => ['nullable', 'string', 'max:90'],
            'preferred_study_area' => ['nullable', 'string', 'max:120'],
            'preferred_study_level' => ['nullable', 'string', 'max:90'],
            'program_label' => ['nullable', 'string', 'max:160'],
            'additional_info' => ['nullable', 'string', 'max:4000'],
            'channel' => ['nullable', Rule::in(['console', 'whatsapp'])],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:'.intdiv((int) config('documents.max_bytes'), 1024), 'mimetypes:'.implode(',', config('documents.allowed_mimes'))],
        ]);

        $studentId = null;
        if ($data['enquiry_type'] === 'existing') {
            // Resolve the student WITHIN the acting tenant only (docs §5).
            $student = Student::forAgency($agencyId)->whereKey($data['student_id'])->first();
            if (! $student) {
                return response()->json(['message' => 'Student not found.'], 404)->header('Cache-Control', 'no-store');
            }
            $studentId = $student->id;
        }

        $pr = ProgramRequest::create([
            'agency_id' => $agencyId,
            'created_by_user_id' => $request->user()->id,
            'enquiry_type' => $data['enquiry_type'],
            'student_id' => $studentId,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => isset($data['email']) ? mb_strtolower($data['email']) : null,
            'country_of_education' => $data['country_of_education'] ?? null,
            'highest_education_level' => $data['highest_education_level'] ?? null,
            'destination' => $data['destination'] ?? null,
            'preferred_study_area' => $data['preferred_study_area'] ?? null,
            'preferred_study_level' => $data['preferred_study_level'] ?? null,
            'program_label' => $data['program_label'] ?? null,
            'additional_info' => $data['additional_info'] ?? null,
            'channel' => $data['channel'] ?? 'console',
            'status' => 'open',
        ]);

        $rejected = 0;
        foreach ($request->file('files', []) as $upload) {
            if (! $this->intake($pr, $agencyId, $upload, $request->user()->id)) {
                $rejected++;
            }
        }

        return response()->json([
            'enquiry' => $this->present($pr->fresh('documents')),
            'files_rejected' => $rejected,
        ], 201)->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/enquiries — paged, tenant-scoped list. */
    public function index(Request $request): JsonResponse
    {
        $page = ProgramRequest::with('documents')->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (ProgramRequest $p) => $this->present($p)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ])->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/enquiries/documents/{doc}/download — mint a single-use URL. */
    public function download(Request $request, int $doc): JsonResponse
    {
        // Explicit lookup (not route-model binding) so the tenant scope — set by
        // EnsurePartner — is definitely applied: a foreign doc id → 404.
        $doc = ProgramRequestDocument::find($doc);
        if (! $doc || ! $doc->isReadable()) {
            return response()->json(['message' => 'That document is not available.'], 404)->header('Cache-Control', 'no-store');
        }

        $token = Str::random(48);
        Cache::put("prdl:$token", ['doc_id' => $doc->id, 'agency_id' => $doc->agency_id, 'actor' => $request->user()->id], now()->addSeconds((int) config('documents.download_ttl', 120)));
        AuthEvent::record('enquiry_doc_presign', ['user_id' => $request->user()->id, 'context' => ['doc' => $doc->id]]);

        return response()->json(['url' => url('/api/partner/documents/dl/'.$token), 'name' => $doc->original_filename])
            ->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/documents/dl/{token} — PUBLIC, single-use stream. */
    public function stream(Request $request, string $token): Response
    {
        $data = Cache::pull("prdl:$token");
        if (! $data) {
            abort(404);
        }
        $doc = ProgramRequestDocument::withoutGlobalScopes()->find($data['doc_id']);
        if (! $doc || ! $doc->isReadable()) {
            abort(404);
        }
        $bytes = $this->storage->get($doc->storage_key);
        if ($bytes === null) {
            abort(404);
        }
        AuthEvent::record('enquiry_doc_download', ['user_id' => $data['actor'] ?? null, 'context' => ['doc' => $doc->id]]);

        return response($bytes, 200, [
            'Content-Type' => $doc->content_type,
            'Content-Disposition' => 'attachment; filename="'.addslashes($doc->original_filename).'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ---- helpers ----

    /** Scan-gated intake of one file → true if kept (clean), false if rejected. */
    private function intake(ProgramRequest $pr, int $agencyId, $upload, int $userId): bool
    {
        $bytes = (string) file_get_contents($upload->getRealPath());
        if (! $this->magicBytesAllowed($bytes)) {
            return false;
        }

        $key = $this->storage->put($bytes);
        $doc = ProgramRequestDocument::create([
            'program_request_id' => $pr->id, 'agency_id' => $agencyId,
            'storage_key' => $key, 'original_filename' => $this->safeName($upload->getClientOriginalName()),
            'content_type' => $upload->getMimeType(), 'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'scan_status' => ScanStatus::Pending,
            'uploaded_by' => $userId, 'uploaded_at' => now(),
        ]);

        try {
            $verdict = $this->scanner->scan($bytes);
        } catch (\Throwable $e) {
            $this->storage->delete($key);
            $doc->forceFill(['scan_status' => ScanStatus::Infected])->save();

            return false;
        }

        if ($verdict === DocumentScanner::INFECTED) {
            $this->storage->delete($key);                     // never keep infected bytes
            $doc->forceFill(['scan_status' => ScanStatus::Infected])->save();

            return false;
        }

        $doc->forceFill(['scan_status' => ScanStatus::Clean])->save();

        return true;
    }

    private function present(ProgramRequest $p): array
    {
        return [
            'id' => $p->id,
            'enquiry_type' => $p->enquiry_type?->value,
            'name' => trim(($p->first_name ?? '').' '.($p->last_name ?? '')),
            'email' => $p->email,
            'destination' => $p->destination,
            'preferred_study_area' => $p->preferred_study_area,
            'program_label' => $p->program_label,
            'channel' => $p->channel,
            'status' => $p->status,
            'documents' => $p->documents->map(fn (ProgramRequestDocument $d) => [
                'id' => $d->id, 'name' => $d->original_filename,
                'scan' => $d->scan_status?->value, 'readable' => $d->isReadable(),
            ])->all(),
            'created_at' => optional($p->created_at)->toIso8601String(),
        ];
    }

    private function magicBytesAllowed(string $bytes): bool
    {
        return str_starts_with($bytes, '%PDF-')
            || str_starts_with($bytes, "\xFF\xD8\xFF")
            || str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A");
    }

    private function safeName(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '', basename($name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return mb_substr(trim($name) ?: 'document', 0, 120);
    }
}
