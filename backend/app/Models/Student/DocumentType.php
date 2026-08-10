<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $fillable = ['key', 'pack', 'name', 'icon', 'note', 'destination_dependent', 'position'];

    protected function casts(): array
    {
        return ['destination_dependent' => 'boolean'];
    }
}
