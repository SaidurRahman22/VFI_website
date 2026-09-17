<?php

namespace App\Models\Partner;

use App\Enums\ApplicationStatus;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Student\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Phase 7 — a student's application in the partner pipeline (tenant-scoped).
 *
 * The @property block is not decoration. Larastan cannot see through the
 * casts() METHOD form, so without it `status` is inferred as a plain string and
 * every `$app->status->value` in the codebase is reported as "cannot access
 * property on string" - correct at runtime, noise in the analyser, and it
 * buries the findings that are real. Declaring the types here fixes the cause
 * rather than silencing the symptom.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $student_id
 * @property int|null $program_id
 * @property int|null $institution_id
 * @property string|null $intake_month
 * @property int|null $intake_year
 * @property ApplicationStatus $status
 * @property string|null $ack_no
 * @property Carbon|null $submitted_at
 * @property Carbon|null $deadline_at
 * @property string|null $deferred_to_intake
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Student|null $student
 * @property-read PartnerAgency|null $agency
 * @property-read Collection<int, ApplicationNote> $notes
 * @property-read Collection<int, ApplicationStatusEvent> $events
 * @property-read int|null $notes_count
 */
class Application extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'student_id', 'program_id', 'institution_id', 'intake_month',
        'intake_year', 'status', 'ack_no', 'submitted_at', 'deadline_at', 'deferred_to_intake',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'submitted_at' => 'datetime',
            'deadline_at' => 'datetime',
            'intake_year' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(PartnerAgency::class, 'agency_id');
    }

    /**
     * Staff-internal counsellor notes (Phase 9A). Present so the admin queue can
     * withCount() them in one query. NEVER eager-load or serialise this from a
     * Partner\* or Me\* controller — the notes are not agency- or student-visible.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ApplicationNote::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationStatusEvent::class)->orderBy('occurred_at');
    }
}
