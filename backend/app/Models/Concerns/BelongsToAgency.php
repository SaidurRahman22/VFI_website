<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * BelongsToAgency — the primary tenancy net (docs §5.1). Any model using this
 * trait is automatically constrained to the current session's agency_id, and
 * new rows are stamped with it. The agency id comes from TenantContext (session
 * only), never a request parameter.
 *
 * Fail-closed: with no tenant in context the scope yields a
 * `whereRaw('1 = 0')` — zero rows — rather than every tenant's rows.
 *
 * Cross-tenant staff reads (P6/P9) must opt out explicitly and audibly via
 * ->withoutGlobalScope(BelongsToAgencyScope::class); on Postgres the RLS FORCE
 * policy is the independent second net behind that.
 */
trait BelongsToAgency
{
    public static function bootBelongsToAgency(): void
    {
        static::addGlobalScope(new BelongsToAgencyScope);

        static::creating(function (Model $model) {
            if ($model->getAttribute('agency_id') === null) {
                $model->setAttribute('agency_id', app(TenantContext::class)->agencyId());
            }
        });
    }
}

class BelongsToAgencyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $agencyId = app(TenantContext::class)->agencyId();

        if ($agencyId === null) {
            $builder->whereRaw('1 = 0');   // fail closed — no tenant, no rows

            return;
        }

        $builder->where($model->getTable().'.agency_id', $agencyId);
    }
}
