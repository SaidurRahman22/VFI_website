<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5A — student profile (docs §1). One `students` anchor per user + 1:1
 * personal/address/preferences rows (each with its own timestamps for
 * per-section optimistic concurrency) + the two repeatable collections
 * (qualifications, test_scores). All self-scoped via student_id; the
 * `student_ref` is a display value, NEVER an access key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('student_ref')->unique();   // VFI-2026-04871 — display only, guessable
            $table->timestamps();
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first', 40)->nullable();
            $table->string('middle', 40)->nullable();
            $table->string('last', 70)->nullable();
            $table->date('dob')->nullable();
            $table->string('nationality', 90)->nullable();
            $table->string('cc', 8)->nullable();        // dialling code, e.g. +880
            $table->string('phone', 14)->nullable();
            $table->string('email', 190)->nullable();
            $table->timestamps();                        // updated_at = concurrency token
        });

        Schema::create('student_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('line1', 120)->nullable();
            $table->string('line2', 120)->nullable();
            $table->string('city', 90)->nullable();
            $table->string('district', 90)->nullable();
            $table->string('postcode', 16)->nullable();
            $table->string('country', 90)->nullable();
            $table->timestamps();
        });

        Schema::create('student_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('qualification', 160)->nullable();
            $table->string('institution', 160)->nullable();
            $table->string('year', 4)->nullable();       // validated exactly 4 digits
            $table->string('grade', 60)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('student_id');
        });

        Schema::create('student_test_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('test', 60)->nullable();
            $table->string('score_raw', 20)->nullable();       // must hold '7.5' AND '318'
            $table->decimal('score_numeric', 5, 2)->nullable(); // parsed where possible
            $table->date('taken_on')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('student_id');
        });

        Schema::create('student_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('intake', 40)->nullable();
            $table->string('budget', 40)->nullable();
            $table->string('field', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('student_preference_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('destination', 90);          // display name, e.g. "United Kingdom"
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_preference_destinations');
        Schema::dropIfExists('student_preferences');
        Schema::dropIfExists('student_test_scores');
        Schema::dropIfExists('student_qualifications');
        Schema::dropIfExists('student_addresses');
        Schema::dropIfExists('student_profiles');
        Schema::dropIfExists('students');
    }
};
