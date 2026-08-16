<?php

namespace App\Services;

use App\Models\Student\Student;
use Illuminate\Support\Facades\Date;

/**
 * Phase 5B — builds the one-shot profile aggregate the portal paints its seven
 * cards from, and reproduces the exact 26-item completeness scoring server-side
 * (docs §1.1, §1.7) so a future counsellor view agrees with the student's.
 */
class StudentProfileService
{
    public function __construct(private readonly DocumentChecklist $checklist) {}

    /** The full profile payload (shaped to the frontend `state` object). */
    public function aggregate(Student $student): array
    {
        $student->loadMissing(['profile', 'address', 'preference', 'qualifications', 'testScores', 'destinations', 'user']);
        $p = $student->profile;
        $a = $student->address;
        $pref = $student->preference;
        $user = $student->user;

        return [
            'student' => [
                'student_ref' => $student->student_ref,
                'name' => $student->displayName(),
                'initials' => $student->initials(),
            ],
            'personal' => [
                // email defaults to the sign-in identity but is a SEPARATE contact
                // field — editing it never changes the login email (docs §1.2).
                'first' => $p->first ?? $this->firstToken($user?->name),
                'middle' => $p->middle ?? '',
                'last' => $p->last ?? $this->restTokens($user?->name),
                'dob' => optional($p?->dob)->format('Y-m-d') ?? '',
                'nationality' => $p->nationality ?? '',
                'cc' => $p->cc ?? '',
                'phone' => $p->phone ?? '',
                'email' => $p->email ?? $user?->email ?? '',
            ],
            'address' => [
                'line1' => $a->line1 ?? '', 'line2' => $a->line2 ?? '',
                'city' => $a->city ?? '', 'district' => $a->district ?? '',
                'postcode' => $a->postcode ?? '', 'country' => $a->country ?? '',
            ],
            'academic' => $student->qualifications->map(fn ($q) => [
                'qualification' => $q->qualification ?? '', 'institution' => $q->institution ?? '',
                'year' => $q->year ?? '', 'grade' => $q->grade ?? '',
            ])->all(),
            'tests' => $student->testScores->map(fn ($t) => [
                'test' => $t->test ?? '', 'score' => $t->score_raw ?? '',
                'date' => optional($t->taken_on)->format('Y-m-d') ?? '',
            ])->all(),
            'prefs' => [
                'countries' => $student->destinations->pluck('destination')->all(),
                'intake' => $pref->intake ?? '', 'budget' => $pref->budget ?? '',
                'field' => $pref->field ?? '',
            ],
            'documents' => $this->checklist->map($student, 'application'),
            'visaDocuments' => $this->checklist->map($student, 'visa'),
            'completeness' => $this->completeness($student),
            'versions' => $this->versions($student),
            'intakeOptions' => $this->intakeOptions(),
            'must_verify' => $user?->email_verified_at === null,
        ];
    }

    /** The 26-item meter — reproduced verbatim from js/student-portal.js. */
    public function completeness(Student $student): array
    {
        $student->loadMissing(['profile', 'address', 'preference', 'qualifications', 'testScores', 'destinations']);
        $p = $student->profile;
        $a = $student->address;
        $pref = $student->preference;

        $done = 0;
        $total = 0;
        $score = function (bool $ok) use (&$done, &$total) {
            $total++;
            if ($ok) {
                $done++;
            }
        };
        $filled = fn ($v) => is_string($v) ? trim($v) !== '' : ! empty($v);

        // 6 personal (NOT cc)
        foreach (['first', 'last', 'dob', 'nationality', 'phone', 'email'] as $k) {
            $score($filled($p?->{$k === 'dob' ? 'dob' : $k}));
        }
        // 5 address (NOT line2)
        foreach (['line1', 'city', 'district', 'postcode', 'country'] as $k) {
            $score($filled($a?->{$k}));
        }
        // 3 academic — fully-complete rows only
        $acDone = $student->qualifications->filter(fn ($q) => $filled($q->qualification) && $filled($q->institution) && $filled($q->year) && $filled($q->grade))->count();
        for ($i = 0; $i < 3; $i++) {
            $score($i < $acDone);
        }
        // 2 tests — fully-complete rows only (test + score + date)
        $tDone = $student->testScores->filter(fn ($t) => $filled($t->test) && $filled($t->score_raw) && $filled($t->taken_on))->count();
        for ($j = 0; $j < 2; $j++) {
            $score($j < $tDone);
        }
        // 4 preferences
        $score($student->destinations->count() > 0);
        $score($filled($pref?->intake));
        $score($filled($pref?->budget));
        $score($filled($pref?->field));
        // 6 application documents (uploaded|verified)
        foreach ($this->checklist->map($student, 'application') as $rec) {
            $score(in_array($rec['status'], ['uploaded', 'verified'], true));
        }

        return [
            'pct' => $total ? (int) round($done / $total * 100) : 0,
            'done' => $done,
            'total' => $total,
        ];
    }

    /** Per-section concurrency tokens (docs §1.4 optimistic concurrency). */
    public function versions(Student $student): array
    {
        return [
            'personal' => $this->rowVersion($student->profile),
            'address' => $this->rowVersion($student->address),
            'prefs' => $this->rowVersion($student->preference),
            'academic' => $this->collectionVersion($student->qualifications),
            'tests' => $this->collectionVersion($student->testScores),
        ];
    }

    public function rowVersion($row): string
    {
        return $row?->updated_at?->toIso8601String() ?? '0';
    }

    public function collectionVersion($rows): string
    {
        if ($rows->isEmpty()) {
            return '0';
        }

        return md5($rows->map(fn ($r) => $r->id.':'.optional($r->updated_at)->timestamp)->sort()->implode('|'));
    }

    /** Forward intake list, served by the backend so it never goes stale. */
    public function intakeOptions(): array
    {
        $now = Date::now();
        $out = [];
        for ($y = $now->year; $y <= $now->year + 2; $y++) {
            foreach (['January' => 1, 'May' => 5, 'September' => 9] as $label => $month) {
                if ($y > $now->year || $month >= $now->month) {
                    $out[] = $label.' '.$y;
                }
            }
        }

        return array_slice($out, 0, 6);
    }

    private function firstToken(?string $name): string
    {
        return trim(explode(' ', trim((string) $name), 2)[0] ?? '');
    }

    private function restTokens(?string $name): string
    {
        $parts = explode(' ', trim((string) $name), 2);

        return trim($parts[1] ?? '');
    }
}
