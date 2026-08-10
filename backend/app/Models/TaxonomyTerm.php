<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Lookup terms (blog categories, event types, destination countries, …). */
class TaxonomyTerm extends Model
{
    protected $fillable = ['kind', 'value', 'label', 'position'];
}
