<?php

namespace Database\Seeders;

use App\Models\Student\Student;
use Illuminate\Database\Seeder;

/**
 * Phase 5E — demo tracking for a student (the write side is Phase 9). Dates are
 * RELATIVE to now() so `is_overdue` / journey % are meaningful whenever this
 * runs. Idempotent per student (clears then re-seeds).
 */
class StudentTrackingSeeder extends Seeder
{
    public function seedFor(Student $student): void
    {
        $student->journeyStages()->delete();
        $student->applications()->delete();
        $student->timeline()->delete();
        $student->actions()->delete();

        $stages = [
            ['Counselling', 'done', 'Completed'],
            ['Documents', 'done', 'Completed'],
            ['Application Sent', 'done', 'Completed'],
            ['Offer Received', 'now', 'In progress'],
            ['Visa', 'todo', 'Expected soon'],
            ['Departure', 'todo', 'Expected later'],
        ];
        foreach ($stages as $i => [$name, $state, $label]) {
            $student->journeyStages()->create(['name' => $name, 'state' => $state, 'when_label' => $label, 'position' => $i]);
        }

        $apps = [
            ['University of Glasgow', 'Glasgow, United Kingdom', 'MSc Data Analytics', 'offer', 80, 'Offer issued, deposit pending'],
            ['University of Birmingham', 'Birmingham, United Kingdom', 'MSc Computer Science', 'conditional', 65, 'One condition outstanding'],
            ['Trinity College Dublin', 'Dublin, Ireland', 'MSc Computer Science', 'review', 45, 'With the academic panel'],
            ['Queen Mary University of London', 'London, United Kingdom', 'MSc Big Data Science', 'submitted', 25, 'Acknowledged by admissions'],
        ];
        foreach ($apps as $i => [$uni, $place, $course, $status, $pct, $stage]) {
            $student->applications()->create([
                'university' => $uni, 'place' => $place, 'course' => $course, 'intake' => 'September 2026',
                'sent_on' => now()->subDays(40 - $i * 4), 'status' => $status, 'pct' => $pct, 'stage' => $stage,
                'note' => 'Sample tracking record.', 'position' => $i,
            ]);
        }

        $events = [
            [now()->subDays(5), 'ok', 'i-check-c', 'Unconditional offer from the University of Glasgow'],
            [now()->subDays(9), 'part', 'i-checks', 'Conditional offer from the University of Birmingham'],
            [now()->subDays(13), 'info', 'i-search', 'Trinity College Dublin moved your file to review'],
            [now()->subDays(20), 'wait', 'i-money', 'Financial documents requested'],
        ];
        foreach ($events as $i => [$on, $tone, $icon, $title]) {
            $student->timeline()->create([
                'occurred_on' => $on, 'tone' => $tone, 'icon' => $icon, 'title' => $title,
                'body' => 'Sample activity entry.', 'position' => $i,
            ]);
        }

        $actions = [
            ['i-award', 'Upload the official IELTS test report', now()->subDays(7)],   // overdue
            ['i-money', 'Upload your financial documents', now()->addDays(10)],
            ['i-passport', 'Book your visa appointment', null],                        // no date → never overdue
        ];
        foreach ($actions as $i => [$icon, $title, $due]) {
            $student->actions()->create([
                'icon' => $icon, 'title' => $title, 'body' => 'Sample pending action.',
                'due_at' => $due, 'done' => false, 'position' => $i,
            ]);
        }
    }
}
