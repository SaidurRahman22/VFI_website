<?php

namespace App\Models\Student;

use App\Enums\StudentSource;
use App\Models\Partner\PartnerAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Phase 5 anchor for a portal student — evolved in Phase 7 into a dual-purpose
 * row that ALSO holds an agency-registered lead (owned by `agency_id`, maybe no
 * login). It is NOT BelongsToAgency-scoped: the portal reads it self-scoped by
 * user_id with no tenant in context, so the console scopes it EXPLICITLY by the
 * session agency (scopeForAgency). `email` is the cross-channel collision key.
 */
class Student extends Model
{
    protected $fillable = [
        'user_id', 'student_ref', 'agency_id', 'source', 'registered_by_user_id',
        'email', 'first_name', 'middle_name', 'last_name', 'phone_cc', 'phone',
        'destination_country', 'intake_month', 'intake_year', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['source' => StudentSource::class, 'archived_at' => 'datetime', 'intake_year' => 'integer'];
    }

    /** Console reads ONLY: constrain to the session agency (id from session, never client). */
    public function scopeForAgency(Builder $query, int $agencyId): Builder
    {
        return $query->where('students.agency_id', $agencyId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(PartnerAgency::class, 'agency_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function address(): HasOne
    {
        return $this->hasOne(StudentAddress::class);
    }

    public function preference(): HasOne
    {
        return $this->hasOne(StudentPreference::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(StudentQualification::class)->orderBy('position')->orderBy('id');
    }

    public function testScores(): HasMany
    {
        return $this->hasMany(StudentTestScore::class)->orderBy('position')->orderBy('id');
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(StudentPreferenceDestination::class)->orderBy('position')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(StudentApplication::class)->orderBy('position')->orderBy('id');
    }

    public function journeyStages(): HasMany
    {
        return $this->hasMany(StudentJourneyStage::class)->orderBy('position')->orderBy('id');
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(StudentTimelineEvent::class)->orderByDesc('occurred_on')->orderBy('position');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(StudentAction::class)->orderBy('position')->orderBy('id');
    }

    /** Resolve-or-create the portal student row for a user. Never takes a client id. */
    public static function resolveFor(User $user): self
    {
        $student = static::firstOrNew(['user_id' => $user->id]);
        if (! $student->exists) {
            // A self-signup portal student is unowned; email is the collision key.
            $student->forceFill(['email' => $user->email, 'source' => StudentSource::SelfSignup->value]);
            $student->student_ref = 'VFI-PENDING-'.Str::random(12);   // unique placeholder
            $student->save();
            // Sequential display ref derived from the id (guessable by design; not an access key).
            $student->forceFill(['student_ref' => sprintf('VFI-%d-%05d', now()->year, $student->id + 4870)])->save();
        }

        return $student;
    }

    public function initials(): string
    {
        [$f, $l] = $this->nameParts();
        $i = mb_substr($f, 0, 1).mb_substr($l, 0, 1);

        return mb_strtoupper($i !== '' ? $i : mb_substr($this->user->name ?? 'S', 0, 1));
    }

    public function displayName(): string
    {
        [$f, $l] = $this->nameParts();
        $name = trim($f.' '.$l);

        return $name !== '' ? $name : ($this->user->name ?? 'Student');
    }

    /** Name from the profile (portal) or the direct fields (agency lead). */
    private function nameParts(): array
    {
        $p = $this->relationLoaded('profile') ? $this->profile : $this->profile()->first();

        return [
            (string) ($p->first ?? $this->first_name ?? ''),
            (string) ($p->last ?? $this->last_name ?? ''),
        ];
    }
}
