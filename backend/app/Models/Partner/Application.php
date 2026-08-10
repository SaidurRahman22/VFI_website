<?php

namespace App\Models\Partner;

use App\Enums\ApplicationStatus;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Student\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 7 — a student's application in the partner pipeline (tenant-scoped). */
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

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationStatusEvent::class)->orderBy('occurred_at');
    }
}
