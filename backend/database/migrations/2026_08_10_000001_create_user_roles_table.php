<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);                 // App\Enums\Role

            // Tenant-bound roles (partner_*) MUST carry a non-null agency_id.
            // No FK yet — the agencies table lands in P6. Enforced in the app
            // (UserRole model) now; a Postgres CHECK is added when on pgsql.
            $table->unsignedBigInteger('agency_id')->nullable()->index();

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();

            // one active grant per (user, role, tenant)
            $table->unique(['user_id', 'role', 'agency_id']);
            $table->index(['role', 'revoked_at']);
        });

        // Postgres-only second net: a role marked tenant-bound cannot exist with
        // a NULL agency_id, and a non-tenant role cannot carry one.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE user_roles ADD CONSTRAINT user_roles_agency_ck CHECK (
                    (role IN ('partner_owner','partner_counsellor') AND agency_id IS NOT NULL)
                    OR (role NOT IN ('partner_owner','partner_counsellor') AND agency_id IS NULL)
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
