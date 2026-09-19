<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fill the collections that are still empty on the live site.
 *
 * Events, blogs and news were populated long ago. Seven collections never were,
 * and each one is a screen somebody actually reaches:
 *
 *   photos          - gallery.html, a public page, currently showing nothing
 *   pp_managers     - the partner console's "your VFI contacts"
 *   pp_updates      - the partner console's updates feed
 *   pp_quicklinks   - the partner console's shortcut tiles
 *   pp_docs         - the partner console's document library
 *   pp_emails       - the partner console's email-updates list
 *   pp_notifs       - the partner console's notification bell
 *
 * So a partner agency signs in and finds six empty panels, which reads as a
 * broken product rather than a new account.
 *
 * WHY A MIGRATION. It is the only thing that runs by itself on deploy
 * (vfi-deploy.sh runs `migrate --force` and nothing else), and running an
 * artisan command on that box needs a root shell this project does not have.
 * A data migration is the honest vehicle here rather than a seeder nobody can
 * invoke.
 *
 * IDEMPOTENT AND NON-DESTRUCTIVE, which matters more than usual because this
 * writes content to a live site: each table is filled ONLY if it is completely
 * empty, counting soft-deleted rows too. If someone has already added a single
 * photo - or added six and removed them - this leaves that table alone. It can
 * never overwrite, duplicate, or resurrect anything.
 *
 * THE CONTENT IS PLACEHOLDER, and deliberately reads as such. The client has
 * confirmed there are no partner universities and the catalogue is demo data;
 * this is the same. Nothing here is attributed to a real, identifiable person,
 * no real employer names are used as if they were clients, and every row is
 * editable and removable from the console in the normal way. Photo rows point
 * at pictures already bundled in the repository, so nothing is uploaded and the
 * reference-counted delete never touches them.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Not in the test database.
         *
         * RefreshDatabase runs every migration before every test, so seeding
         * content here puts eight photos and seventeen partner rows into a
         * database the whole suite assumes is empty. It broke four tests
         * immediately, and one of them - "empty bundle has arrays and objects
         * faithfully" - exists precisely to check the EMPTY case, so there is
         * no expectation to adjust. A fixture is not a migration's job.
         *
         * The logic is not left untested by this: SeedEmptyCollectionsTest
         * invokes this migration directly and checks that it fills an empty
         * table, skips a populated one, and skips one whose rows were removed.
         */
        if (app()->environment('testing')) {
            return;
        }

        $now = now();

        // Bundled artwork, so no upload is involved and ImageService never
        // deletes these: isManaged() is false for an assets/ path.
        $this->fill('photos', [
            ['img_id' => 'assets/img/students-group.jpg', 'caption' => 'Spot admissions day, Dhaka', 'alt' => 'Students waiting to meet university representatives at a VFI spot admissions day'],
            ['img_id' => 'assets/img/students-collab.jpg', 'caption' => 'Application workshop', 'alt' => 'Students working through application forms together at a VFI workshop'],
            ['img_id' => 'assets/img/handshake.jpg', 'caption' => 'University partner meeting', 'alt' => 'A VFI counsellor shaking hands with a visiting university representative'],
            ['img_id' => 'assets/img/students-friends.jpg', 'caption' => 'Pre-departure briefing', 'alt' => 'Departing students at a VFI pre-departure briefing'],
            ['img_id' => 'assets/img/library.jpg', 'caption' => 'IELTS preparation class', 'alt' => 'Students studying in a library during an IELTS preparation course'],
            ['img_id' => 'assets/img/team-meeting.jpg', 'caption' => 'Counselling session', 'alt' => 'A one-to-one counselling session between a student and a VFI adviser'],
            ['img_id' => 'assets/img/city-uk.jpg', 'caption' => 'UK education fair', 'alt' => 'The VFI stand at a UK universities education fair'],
            ['img_id' => 'assets/img/campus.jpg', 'caption' => 'Campus visit', 'alt' => 'A university campus visited by VFI students'],
        ], $now);

        $this->fill('pp_managers', [
            ['name' => 'Your account manager', 'role' => 'Partner Success Manager', 'city' => 'Dhaka', 'phone' => '+880 1XXX-XXXXXX', 'email' => 'partners@vfi-fc.com'],
            ['name' => 'Admissions desk', 'role' => 'Applications & offers', 'city' => 'Dhaka', 'phone' => '+880 1XXX-XXXXXX', 'email' => 'admissions@vfi-fc.com'],
            ['name' => 'Compliance desk', 'role' => 'Documents & visa files', 'city' => 'Dhaka', 'phone' => '+880 1XXX-XXXXXX', 'email' => 'compliance@vfi-fc.com'],
        ], $now);

        $this->fill('pp_updates', [
            ['flag' => 'New', 'title' => 'Welcome to the VFI partner console', 'sub' => 'Submit applications, track offers and download the forms you need, in one place.', 'date' => 'This week'],
            ['flag' => 'Reminder', 'title' => 'Upload documents as PDFs where you can', 'sub' => 'Scans of passports and transcripts are checked faster when they are a single readable file.', 'date' => 'This week'],
            ['flag' => 'Guide', 'title' => 'What happens after you submit an application', 'sub' => 'The stages a case moves through, and who is responsible at each one.', 'date' => 'This month'],
        ], $now);

        $this->fill('pp_quicklinks', [
            ['label' => 'Add a student', 'url' => 'partner-students.html'],
            ['label' => 'Start an application', 'url' => 'partner-applications.html'],
            ['label' => 'Search programmes', 'url' => 'partner-search.html'],
            ['label' => 'Raise an enquiry', 'url' => 'partner-enquiries.html'],
            ['label' => 'Resources & forms', 'url' => 'partner-resources.html'],
        ], $now);

        $this->fill('pp_docs', [
            ['title' => 'Partner agreement (template)', 'country' => 'All', 'category' => 'Agreements', 'size' => 'PDF', 'date' => 'Current', 'url' => 'partner-resources.html'],
            ['title' => 'Student application checklist', 'country' => 'All', 'category' => 'Checklists', 'size' => 'PDF', 'date' => 'Current', 'url' => 'partner-resources.html'],
            ['title' => 'Document requirements by country', 'country' => 'All', 'category' => 'Checklists', 'size' => 'PDF', 'date' => 'Current', 'url' => 'partner-resources.html'],
            ['title' => 'Commission and invoicing guide', 'country' => 'All', 'category' => 'Finance', 'size' => 'PDF', 'date' => 'Current', 'url' => 'partner-resources.html'],
        ], $now);

        $this->fill('pp_emails', [
            ['subject' => 'Monthly partner bulletin', 'date' => 'Monthly'],
            ['subject' => 'Intake deadlines and closing dates', 'date' => 'As they change'],
            ['subject' => 'New universities and programmes added', 'date' => 'Monthly'],
        ], $now);

        $this->fill('pp_notifs', [
            ['title' => 'Your console is ready', 'message' => 'Add your first student, then start an application from their record.', 'date' => 'Today'],
            ['title' => 'Keep your contact details current', 'message' => 'Offers and document requests go to the email on your agency profile.', 'date' => 'Today'],
        ], $now);
    }

    /**
     * Insert only into a table that is completely empty, soft-deleted rows
     * included.
     *
     * The soft-delete check is the point: a table whose rows have all been
     * REMOVED is not the same as one nobody has touched, and re-filling it
     * would undo a deliberate clear-out on a live site.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    private function fill(string $table, array $rows, mixed $now): void
    {
        if (DB::table($table)->count() > 0) {
            return;
        }

        $position = 0;
        $payload = [];

        foreach ($rows as $row) {
            $payload[] = $row + [
                // legacy_id is what the console shows and what the audit log
                // records against, so it has to be there and has to be unique.
                'legacy_id' => 'seed_'.$table.'_'.(++$position),
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table($table)->insert($payload);
    }

    /**
     * Removes only what this migration added, and only if it is untouched.
     *
     * Matching on the legacy_id prefix rather than truncating: by the time
     * anyone rolls back, an editor may have added rows of their own beside
     * these, and a truncate would take those too.
     */
    public function down(): void
    {
        foreach (['photos', 'pp_managers', 'pp_updates', 'pp_quicklinks', 'pp_docs', 'pp_emails', 'pp_notifs'] as $table) {
            DB::table($table)->where('legacy_id', 'like', 'seed_'.$table.'_%')->delete();
        }
    }
};
