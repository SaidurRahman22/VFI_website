<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * Append-only (docs §document_access_log). Every read of a sensitive blob is
 * recorded here; the app DB role has no UPDATE/DELETE grant in production. The
 * model blocks both at the app layer too.
 */
class DocumentAccessLog extends Model
{
    protected $table = 'document_access_log';

    public $timestamps = false;

    protected $fillable = ['document_file_id', 'student_id', 'actor_user_id', 'action', 'ip', 'user_agent', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (DocumentAccessLog $e) => $e->created_at ??= Date::now());
        static::updating(fn () => throw new \RuntimeException('document_access_log is append-only.'));
        static::deleting(fn () => throw new \RuntimeException('document_access_log is append-only.'));
    }

    public static function record(array $attrs): self
    {
        return static::create($attrs);
    }
}
