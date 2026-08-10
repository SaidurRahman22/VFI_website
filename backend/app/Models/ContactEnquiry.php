<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A public contact-form lead (Phase 2 §7). Read-only in the staff inbox. */
class ContactEnquiry extends Model
{
    protected $fillable = ['fname', 'phone', 'email', 'dest', 'msg', 'status', 'source_page', 'ip', 'user_agent'];

    protected $attributes = ['status' => 'new'];
}
