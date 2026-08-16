<?php

namespace App\Http\Controllers\Me;

use App\Http\Controllers\Controller;
use App\Models\Student\Student;
use App\Services\TrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5E — read-only application tracking (docs §4). Implicit-self. The write
 * side (staff advancing stages/statuses) is Phase 9.
 */
class TrackingController extends Controller
{
    public function __construct(private readonly TrackingService $tracking) {}

    /** GET /api/me/tracking — journey + applications + timeline + actions. */
    public function index(Request $request): JsonResponse
    {
        $student = Student::resolveFor($request->user());

        return response()->json($this->tracking->forStudent($student))->header('Cache-Control', 'no-store');
    }
}
