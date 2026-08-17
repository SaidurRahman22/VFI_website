<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Student\DocumentType;
use App\Models\Student\Student;
use Illuminate\Database\Eloquent\Collection;

/**
 * Phase 9D — the single answer to "can this application actually be processed
 * yet".
 *
 * Documents belong to the STUDENT (Phase 5), never to the application: a case
 * draws on the student's checklist rather than carrying its own copies, so a
 * second application for the same person inherits the paperwork already
 * collected and staff never chase the same passport twice. This service is the
 * only place that turns that checklist into a yes/no, so the console, the 201
 * from a new application and the staff queue all answer it identically.
 */
class ApplicationReadiness
{
    /**
     * document_types is small, static reference data and identical for every
     * student, so a page of applications costs ONE types query rather than one
     * per row.
     *
     * @var array<string, Collection<int, DocumentType>>
     */
    private array $typeCache = [];

    /**
     * @return array{ready:bool,complete:bool,required:list<string>,present:list<string>,verified:list<string>,missing:list<string>,rejected:list<string>}
     */
    public function for(Student $student, string $pack = 'application'): array
    {
        $types = $this->types($pack);

        // Status alone is authoritative: the upload pipeline links a document
        // only once its blob has passed the scan gate, so there is no readable
        // file to re-check here and no reason to load the blob rows.
        $docs = $student->documents()
            ->whereIn('document_type_id', $types->pluck('id'))
            ->get()
            ->keyBy('document_type_id');

        $required = $present = $verified = $missing = $rejected = [];

        foreach ($types as $type) {
            $status = $docs->get($type->id)?->status ?? DocumentStatus::Missing;

            // REQUIRED = every type in the pack the schema does not flag
            // destination_dependent. Such a type (today only `medical`) is asked
            // for by some destinations and not others, so it can never gate a
            // case — but it is still reported below if the student supplied it.
            $isRequired = ! $type->destination_dependent;
            if ($isRequired) {
                $required[] = $type->key;
            }

            // The buckets are disjoint — a type is present, rejected or missing,
            // never two at once. `rejected` is split out from `missing` because
            // the agency has to do something different about it: replace a file
            // staff have already turned down, not go and collect a new one.
            if ($status->isPresent()) {
                $present[] = $type->key;
                if ($status === DocumentStatus::Verified) {
                    $verified[] = $type->key;
                }
            } elseif ($status === DocumentStatus::Rejected) {
                $rejected[] = $type->key;
            } elseif ($isRequired) {
                $missing[] = $type->key;
            }
        }

        return [
            // Both flags are measured over the REQUIRED set only: an optional
            // type left blank must never hold a case back.
            'ready' => count(array_intersect($required, $present)) === count($required),
            'complete' => count(array_intersect($required, $verified)) === count($required),
            'required' => $required,
            'present' => $present,
            'verified' => $verified,
            'missing' => $missing,
            'rejected' => $rejected,
        ];
    }

    /** @return Collection<int, DocumentType> */
    private function types(string $pack): Collection
    {
        return $this->typeCache[$pack] ??= DocumentType::where('pack', $pack)
            ->orderBy('position')
            ->get();
    }
}
