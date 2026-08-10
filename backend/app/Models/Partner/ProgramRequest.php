<?php

namespace App\Models\Partner;

use App\Enums\EnquiryType;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Student\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 7 — an enquiry / program-options request (tenant-scoped). */
class ProgramRequest extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'created_by_user_id', 'enquiry_type', 'student_id',
        'first_name', 'last_name', 'email', 'country_of_education', 'highest_education_level',
        'destination', 'preferred_study_area', 'preferred_study_level',
        'program_label', 'additional_info', 'channel', 'status',
    ];

    protected function casts(): array
    {
        return ['enquiry_type' => EnquiryType::class];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProgramRequestDocument::class);
    }
}
