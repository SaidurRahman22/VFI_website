<?php

namespace Database\Seeders;

use App\Models\Student\DocumentType;
use Illuminate\Database\Seeder;

/**
 * Phase 5 — the server-driven document checklist (replaces the hardcoded
 * DOC_DEFS / VISA_DEFS). Idempotent (upsert on `key`). `medical` is flagged
 * destination_dependent (docs §2.1).
 */
class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // application pack
            ['key' => 'passport',    'pack' => 'application', 'name' => 'Passport (bio page)',       'icon' => 'i-passport', 'note' => 'Colour scan, valid for at least six months past your intake.'],
            ['key' => 'transcripts', 'pack' => 'application', 'name' => 'Academic transcripts',      'icon' => 'i-cap',      'note' => 'Every year of study plus the degree certificate.'],
            ['key' => 'sop',         'pack' => 'application', 'name' => 'Statement of purpose',      'icon' => 'i-doc',      'note' => 'Around 800 to 1,000 words, tailored to each course.'],
            ['key' => 'lor',         'pack' => 'application', 'name' => 'Letters of recommendation', 'icon' => 'i-mail',     'note' => 'Two academic referees, on institution letterhead.'],
            ['key' => 'financials',  'pack' => 'application', 'name' => 'Financial documents',       'icon' => 'i-money',    'note' => 'Six months of bank statements and the sponsor affidavit.'],
            ['key' => 'testreport',  'pack' => 'application', 'name' => 'Test report form',          'icon' => 'i-award',    'note' => 'The official score report, not the online preview.'],
            // visa pack
            ['key' => 'offer',       'pack' => 'visa', 'name' => 'Offer / CAS / I-20 letter',   'icon' => 'i-award',    'note' => 'The confirmation your university issues once you accept your place.'],
            ['key' => 'visaform',    'pack' => 'visa', 'name' => 'Visa application form',        'icon' => 'i-doc',      'note' => 'Your completed online form (DS-160, UKVI, IRCC…) saved as a PDF.'],
            ['key' => 'visafee',     'pack' => 'visa', 'name' => 'Visa fee & surcharge receipt', 'icon' => 'i-money',   'note' => 'Proof you paid the application fee and any health surcharge.'],
            ['key' => 'finproof',    'pack' => 'visa', 'name' => 'Proof of funds',              'icon' => 'i-money',    'note' => 'Bank statements or a loan sanction letter covering tuition and living costs.'],
            ['key' => 'photo',       'pack' => 'visa', 'name' => 'Passport-size photograph',    'icon' => 'i-passport', 'note' => "A recent photo that meets the embassy's size and background rules."],
            ['key' => 'medical',     'pack' => 'visa', 'name' => 'Medical / police clearance',  'icon' => 'i-check-c',  'note' => 'Only where your destination asks for a medical certificate or police clearance.', 'destination_dependent' => true],
        ];

        $appPos = 0;
        $visaPos = 0;
        foreach ($types as $t) {
            $t['position'] = $t['pack'] === 'application' ? $appPos++ : $visaPos++;
            $t['destination_dependent'] = $t['destination_dependent'] ?? false;
            DocumentType::updateOrCreate(['key' => $t['key']], $t);
        }
    }
}
