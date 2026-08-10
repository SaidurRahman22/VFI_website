<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/** Tenancy test fixture only (docs §5.3). Not a product entity. */
class SyntheticPartnerRow extends Model
{
    use BelongsToAgency;

    protected $fillable = ['agency_id', 'label'];
}
