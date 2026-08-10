<?php

namespace App\Http\Controllers\Me;

use App\Http\Controllers\Controller;
use App\Models\Student\Student;
use App\Services\DocumentChecklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5C — the document checklist (docs §2). Implicit-self. Returns the 12
 * server-driven types (two packs of 6) joined with this student's own status +
 * file for each. Upload / download / delete are added in P5-D.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentChecklist $checklist)
    {
    }

    /** GET /api/me/documents — both packs with per-type status/file. */
    public function index(Request $request): JsonResponse
    {
        $student = Student::resolveFor($request->user());
        $destinations = $student->destinations()->pluck('destination')->all();

        return response()->json([
            'application' => $this->checklist->full($student, 'application'),
            'visa' => $this->checklist->full($student, 'visa'),
            'destinations' => $destinations,   // lets the UI judge destination-dependent items (medical)
        ])->header('Cache-Control', 'no-store');
    }
}
