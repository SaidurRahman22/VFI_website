<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 5D — the private blob store (docs §3). The storage key is a server UUID
 * (NEVER the client filename — that is user-controlled and a traversal risk);
 * the disk is private and never web-served. Swap the `documents` disk to s3+R2
 * via env and nothing here changes.
 */
class DocumentStorage
{
    private function disk(): Filesystem
    {
        return Storage::disk(config('documents.disk', 'documents'));
    }

    /** Write bytes under a fresh server-generated key; return the key. */
    public function put(string $bytes): string
    {
        $key = 'blob/'.Str::uuid()->toString();
        $this->disk()->put($key, $bytes);

        return $key;
    }

    public function get(string $key): ?string
    {
        return $this->disk()->exists($key) ? $this->disk()->get($key) : null;
    }

    public function delete(string $key): void
    {
        if ($this->disk()->exists($key)) {
            $this->disk()->delete($key);
        }
    }
}
