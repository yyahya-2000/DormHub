<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ConsentDocument;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceLocation;
use App\Enums\MaintenanceUrgency;
use App\Enums\RoleCode;
use App\Models\Building;
use App\Models\ConsentRecord;
use App\Models\MaintenanceRequest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * The cast every maintenance scenario needs: a dormitory, a resident with a
 * bed in a numbered room, and the staff whose capabilities the module is drawn
 * around.
 *
 * It builds on `BuildsAGuestScenario` rather than repeating it, because the
 * three things both modules need — a dormitory, a resident who actually lives
 * in it, a member of staff holding one role in one building — are the same
 * three things, and two copies of `residentOf()` would be two definitions of
 * «lives here» for the one register both modules ask.
 *
 * Everyone and every defect it invents is invented (C-05).
 */
trait BuildsAMaintenanceScenario
{
    use BuildsAGuestScenario;

    /** A one-pixel PNG, the smallest thing the `image` rule will accept. */
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * The room of the Gherkin of §2.4.3: «a resident with an active residency
     * record in room 412».
     */
    protected function residentInRoom412(Building $building, string $email = 'resident@example.test'): User
    {
        return $this->residentOf($building, $email, '412');
    }

    /**
     * A member of staff who will actually receive the module's notifications.
     *
     * `maintenance_status` is an optional category, so it rests on the
     * person's consent (§2.7.1) and `User::notify()` silences it for an
     * account that has never given one. A warden created without it would
     * receive nothing, and a test of FR-36's last Gherkin line would then be
     * asserting the absence of a message for entirely the wrong reason.
     */
    protected function consentingStaff(RoleCode $code, ?Building $building, string $email): User
    {
        $person = $this->staff($code, $building, $email);

        ConsentRecord::query()->create([
            'user_id' => $person->getKey(),
            'document_code' => ConsentDocument::ResidentPersonalData->value,
            'document_revision' => (string) config(
                'dormitory.consent.revisions.'.ConsentDocument::ResidentPersonalData->value
            ),
            'accepted_at' => now(),
            'ip_address' => '192.0.2.11',
        ]);

        return $person->fresh() ?? $person;
    }

    protected function roomOf(User $resident): Room
    {
        $residency = $resident->openResidency()->with('bed.room')->firstOrFail();

        return $residency->bed?->room ?? throw new \RuntimeException('The resident holds no room.');
    }

    /**
     * The body of a well-formed submission, with the fields FR-36's third
     * criterion lists. Overrides go on top, so a test that is about one field
     * names one field.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function submission(Building $building, array $overrides = []): array
    {
        return array_merge([
            'building_id' => $building->getKey(),
            'category' => MaintenanceCategory::Plumbing->value,
            'location' => MaintenanceLocation::OwnRoom->value,
            'description' => 'The mixer tap in the washbasin drips and the washer does not hold any more.',
            'urgency' => MaintenanceUrgency::Urgent->value,
        ], $overrides);
    }

    /**
     * An invented photograph: a real one-pixel PNG, byte for byte.
     *
     * Not `UploadedFile::fake()->image()`, which draws the picture with GD and
     * throws where the extension is absent — the application image of this
     * project carries no GD, because nothing in the application renders an
     * image; it stores what the client uploads and hands back a link. A test
     * that needed an extension the deployment does not have would be a test
     * demanding a change to the runtime for its own sake.
     *
     * Not a text file with a `.png` name either. Laravel's `image` rule reads
     * the file through `fileinfo` rather than trusting the name, so a text
     * file would be refused and the test would pass for the wrong reason —
     * proving nothing about what the route accepts.
     */
    protected function photograph(string $name = 'tap.png'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'maintenance-photo');

        file_put_contents($path, (string) base64_decode(self::ONE_PIXEL_PNG, true));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function workLogOf(MaintenanceRequest $request): array
    {
        return $request->workLog()->get()->map(fn ($entry): array => [
            'from' => $entry->from_status?->value,
            'to' => $entry->to_status?->value,
            'actor' => $entry->actor_id,
            'comment' => $entry->comment,
        ])->all();
    }
}
