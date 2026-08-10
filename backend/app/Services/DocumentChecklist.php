<?php

namespace App\Services;

use App\Models\Student\DocumentType;
use App\Models\Student\Student;

/**
 * Phase 5 — joins the server-driven document_types with a student's own
 * status/file for each. `map()` is the compact {key:{status,file}} shape the
 * profile aggregate + completeness use; `full()` (P5-C) adds per-type metadata
 * and readable-file info for the checklist endpoint.
 */
class DocumentChecklist
{
    /** {typeKey: {status, file}} for one pack (matches the frontend state shape). */
    public function map(Student $student, string $pack): array
    {
        $types = DocumentType::where('pack', $pack)->orderBy('position')->get();
        $byType = $student->documents()->with('file')->get()->keyBy('document_type_id');

        $out = [];
        foreach ($types as $type) {
            $doc = $byType->get($type->id);
            $out[$type->key] = [
                'status' => $doc?->status->value ?? 'missing',
                'file' => $doc?->file?->original_name ?? '',
            ];
        }

        return $out;
    }

    /** Per-type rows with metadata + readable-file details (checklist endpoint). */
    public function full(Student $student, string $pack, ?array $destinations = null): array
    {
        $types = DocumentType::where('pack', $pack)->orderBy('position')->get();
        $byType = $student->documents()->with('file')->get()->keyBy('document_type_id');

        return $types->map(function (DocumentType $type) use ($byType) {
            $doc = $byType->get($type->id);
            $file = $doc?->file;

            return [
                'key' => $type->key,
                'name' => $type->name,
                'icon' => $type->icon,
                'note' => $type->note,
                'destination_dependent' => (bool) $type->destination_dependent,
                'status' => $doc?->status->value ?? 'missing',
                'rejection_reason' => $doc?->rejection_reason,
                'file' => $file ? [
                    'name' => $file->original_name,
                    'size' => $file->size,
                    'scan' => $file->scan_status->value,
                    'readable' => $file->isReadable(),
                ] : null,
            ];
        })->all();
    }
}
