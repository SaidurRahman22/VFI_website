<?php

namespace App\Models\Student;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Phase 5 — the portal anchor for a student user. Resolved implicitly from the
 * session (never a client id — see docs §Self-scope). `student_ref` is a
 * display value; it is guessable and MUST NOT be used as an access key.
 */
class Student extends Model
{
    protected $fillable = ['user_id', 'student_ref'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** Resolve-or-create the student row for a user. Never takes a client id. */
    public static function resolveFor(User $user): self
    {
        $student = static::firstOrNew(['user_id' => $user->id]);
        if (! $student->exists) {
            $student->student_ref = 'VFI-PENDING-'.Str::random(12);   // unique placeholder
            $student->save();
            // Sequential display ref derived from the id (guessable by design; not an access key).
            $student->forceFill(['student_ref' => sprintf('VFI-%d-%05d', now()->year, $student->id + 4870)])->save();
        }

        return $student;
    }

    public function initials(): string
    {
        $p = $this->profile;
        $i = mb_substr((string) ($p->first ?? ''), 0, 1).mb_substr((string) ($p->last ?? ''), 0, 1);

        return mb_strtoupper($i !== '' ? $i : mb_substr($this->user->name ?? 'S', 0, 1));
    }

    public function displayName(): string
    {
        $p = $this->profile;
        $name = trim(($p->first ?? '').' '.($p->last ?? ''));

        return $name !== '' ? $name : ($this->user->name ?? 'Student');
    }
}
