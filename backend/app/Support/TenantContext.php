<?php

namespace App\Support;

/**
 * Holds the current request's tenant (agency) id. Registered as a singleton so
 * the BelongsToAgency global scope and the Postgres RLS binding read the same
 * value. It is set ONLY from the authenticated session (partner auth middleware,
 * P6) or explicitly in tests — NEVER from a request parameter (docs §5.1).
 *
 * Fail-closed: when no agency is set, tenant-scoped queries match nothing rather
 * than leaking across tenants.
 */
class TenantContext
{
    private ?int $agencyId = null;

    public function setAgencyId(?int $id): void
    {
        $this->agencyId = $id;
    }

    public function agencyId(): ?int
    {
        return $this->agencyId;
    }

    public function has(): bool
    {
        return $this->agencyId !== null;
    }

    public function clear(): void
    {
        $this->agencyId = null;
    }
}
