<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * synthetic_partner_rows — a tenancy test fixture (docs §5.3).
 * Real partner tables land in P6, but the tenancy machinery (BelongsToAgency
 * global scope + Postgres RLS) must be PROVEN now. This throwaway tenant-keyed
 * table lets the CI tenancy-guard test exercise both nets before then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synthetic_partner_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();   // the tenant key
            $table->string('label');
            $table->timestamps();
        });

        // Postgres second net: RLS FORCE keyed on SET LOCAL app.agency_id.
        // Removing the Eloquent scope must then return ZERO rows, not another
        // tenant's (docs §5.2 / §5.4). Guarded so SQLite/MySQL dev is unaffected;
        // the app-layer global scope is the primary enforcement everywhere.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE synthetic_partner_rows ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE synthetic_partner_rows FORCE ROW LEVEL SECURITY');
            DB::statement(<<<'SQL'
                CREATE POLICY synthetic_partner_rows_tenant ON synthetic_partner_rows
                USING (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('synthetic_partner_rows');
    }
};
