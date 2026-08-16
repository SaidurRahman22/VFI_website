<?php

namespace App\Http\Controllers\Me;

use App\Http\Controllers\Controller;
use App\Models\Student\Student;
use App\Services\StudentProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5B — the seven profile cards (docs §1). Every endpoint is IMPLICIT-SELF:
 * the student is resolved from the session, never from a client id or
 * student_ref (no IDOR). Academic/tests are whole-collection replace with
 * per-section optimistic concurrency; a stale save is 409'd.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly StudentProfileService $profiles) {}

    /** GET /api/me — light identity for the portal page guard. */
    public function me(Request $request): JsonResponse
    {
        $student = $this->student($request);

        return $this->noStore([
            'student_ref' => $student->student_ref,
            'name' => $student->displayName(),
            'initials' => $student->initials(),
            'email_verified' => $request->user()->email_verified_at !== null,
            'must_verify' => $request->user()->email_verified_at === null,
        ]);
    }

    /** GET /api/me/profile — the full aggregate for all seven cards. */
    public function show(Request $request): JsonResponse
    {
        return $this->noStore($this->profiles->aggregate($this->student($request)));
    }

    /** GET /api/me/completeness — the 26-item meter only. */
    public function completeness(Request $request): JsonResponse
    {
        return $this->noStore($this->profiles->completeness($this->student($request)));
    }

    public function personal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string'],
            'first' => ['required', 'string', 'max:40'],
            'middle' => ['nullable', 'string', 'max:40'],
            'last' => ['required', 'string', 'max:70'],
            'dob' => ['nullable', 'date'],
            'nationality' => ['nullable', 'string', 'max:90'],
            'cc' => ['nullable', 'string', 'max:8'],
            'phone' => ['required', 'string', 'max:14', $this->minDigits(6)],
            'email' => ['required', 'email:rfc', 'max:190'],
        ]);

        $student = $this->student($request);
        if ($resp = $this->guardStale($student, $data['version'] ?? null, $this->profiles->rowVersion($student->profile))) {
            return $resp;
        }

        // NOTE: profile email is a contact field — it never rewrites the
        // sign-in identity (users.email) without re-verification (docs §1.2).
        $student->profile()->updateOrCreate(['student_id' => $student->id], [
            'first' => $data['first'], 'middle' => $data['middle'] ?? null, 'last' => $data['last'],
            'dob' => $data['dob'] ?? null, 'nationality' => $data['nationality'] ?? null,
            'cc' => $data['cc'] ?? null, 'phone' => $data['phone'], 'email' => $data['email'],
        ]);

        return $this->noStore($this->profiles->aggregate($student->fresh()));
    }

    public function address(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string'],
            'line1' => ['nullable', 'string', 'max:120'], 'line2' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:90'], 'district' => ['nullable', 'string', 'max:90'],
            'postcode' => ['nullable', 'string', 'max:16'], 'country' => ['nullable', 'string', 'max:90'],
        ]);

        $student = $this->student($request);
        if ($resp = $this->guardStale($student, $data['version'] ?? null, $this->profiles->rowVersion($student->address))) {
            return $resp;
        }

        $student->address()->updateOrCreate(['student_id' => $student->id], collect($data)->except('version')->all());

        return $this->noStore($this->profiles->aggregate($student->fresh()));
    }

    public function qualifications(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string'],
            'rows' => ['present', 'array'],
            'rows.*.qualification' => ['nullable', 'string', 'max:160'],
            'rows.*.institution' => ['nullable', 'string', 'max:160'],
            'rows.*.year' => ['nullable', 'string', 'regex:/^\d{4}$/'],   // exactly 4 digits if present
            'rows.*.grade' => ['nullable', 'string', 'max:60'],
        ]);

        $student = $this->student($request);
        if ($resp = $this->guardStale($student, $data['version'] ?? null, $this->profiles->collectionVersion($student->qualifications))) {
            return $resp;
        }

        DB::transaction(function () use ($student, $data) {
            $student->qualifications()->delete();
            $pos = 0;
            foreach ($data['rows'] as $r) {
                if (! $this->anyFilled($r, ['qualification', 'institution', 'year', 'grade'])) {
                    continue;   // drop all-empty rows (as the UI does today)
                }
                $student->qualifications()->create([
                    'qualification' => $r['qualification'] ?? null, 'institution' => $r['institution'] ?? null,
                    'year' => $r['year'] ?? null, 'grade' => $r['grade'] ?? null, 'position' => $pos++,
                ]);
            }
        });

        return $this->noStore($this->profiles->aggregate($student->fresh()));
    }

    public function testScores(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string'],
            'rows' => ['present', 'array'],
            'rows.*.test' => ['nullable', 'string', 'max:60'],
            'rows.*.score' => ['nullable', 'string', 'max:20'],
            'rows.*.date' => ['nullable', 'date'],
        ]);

        // If a test is named, a score is required (mirrors validateTests()).
        foreach ($data['rows'] as $i => $r) {
            if (! empty($r['test'] ?? null) && empty($r['score'] ?? null)) {
                return response()->json(['message' => 'Enter a score for each test you list.',
                    'errors' => ["rows.$i.score" => ['A score is required.']]], 422);
            }
        }

        $student = $this->student($request);
        if ($resp = $this->guardStale($student, $data['version'] ?? null, $this->profiles->collectionVersion($student->testScores))) {
            return $resp;
        }

        DB::transaction(function () use ($student, $data) {
            $student->testScores()->delete();
            $pos = 0;
            foreach ($data['rows'] as $r) {
                if (! $this->anyFilled($r, ['test', 'score', 'date'])) {
                    continue;
                }
                $raw = $r['score'] ?? null;
                $student->testScores()->create([
                    'test' => $r['test'] ?? null,
                    'score_raw' => $raw,
                    'score_numeric' => is_numeric(trim((string) $raw)) ? (float) $raw : null,
                    'taken_on' => $r['date'] ?? null,
                    'position' => $pos++,
                ]);
            }
        });

        return $this->noStore($this->profiles->aggregate($student->fresh()));
    }

    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string'],
            'countries' => ['present', 'array'],
            'countries.*' => ['string', 'max:90'],
            'intake' => ['nullable', 'string', 'max:40'],
            'budget' => ['nullable', 'string', 'max:40'],
            'field' => ['nullable', 'string', 'max:120'],
        ]);

        $student = $this->student($request);
        if ($resp = $this->guardStale($student, $data['version'] ?? null, $this->profiles->rowVersion($student->preference))) {
            return $resp;
        }

        DB::transaction(function () use ($student, $data) {
            $student->preference()->updateOrCreate(['student_id' => $student->id], [
                'intake' => $data['intake'] ?? null, 'budget' => $data['budget'] ?? null, 'field' => $data['field'] ?? null,
            ]);
            $student->destinations()->delete();
            $pos = 0;
            foreach (array_unique($data['countries']) as $dest) {
                if (trim($dest) !== '') {
                    $student->destinations()->create(['destination' => $dest, 'position' => $pos++]);
                }
            }
        });

        return $this->noStore($this->profiles->aggregate($student->fresh()));
    }

    // ---- helpers ----

    private function student(Request $request): Student
    {
        return Student::resolveFor($request->user());
    }

    private function noStore(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store');
    }

    /** 409 with the fresh aggregate when the client's version is stale. */
    private function guardStale(Student $student, ?string $sent, string $current): ?JsonResponse
    {
        if ($sent !== null && $sent !== '' && $sent !== $current) {
            return $this->noStore(array_merge(
                ['message' => 'This section was changed on another device. Review the latest and try again.'],
                $this->profiles->aggregate($student->fresh()),
            ), 409);
        }

        return null;
    }

    private function anyFilled(array $row, array $keys): bool
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && trim((string) $row[$k]) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Closure rule: at least N digits after stripping non-digits. */
    private function minDigits(int $n): \Closure
    {
        return function (string $attr, mixed $value, \Closure $fail) use ($n) {
            if (strlen(preg_replace('/\D/', '', (string) $value)) < $n) {
                $fail('Enter a valid phone number.');
            }
        };
    }
}
