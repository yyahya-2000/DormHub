<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceLocation;
use App\Enums\MaintenanceUrgency;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * FR-36, `POST /api/v1/maintenance-requests`, multipart.
 *
 * **The third acceptance criterion is settled here and the first is not**, and
 * the split is the one §3.3.3 draws. «The submission carries category,
 * location, description, urgency and up to three photographs» is a property of
 * the input, so it is a rule and the answer is 422. «The request is bound to
 * the room of the submitter's active residency record, and a resident without
 * one cannot submit» is a question put to the housing register: the client
 * never sends a room, so there is no field to validate, and the answer is 403
 * from `MaintenanceRequestPolicy::create`.
 *
 * **The fourth photograph is refused here and refused again by the database.**
 * `max:3` on the array produces the 422 a client can read; the CHECK
 * constraint `maintenance_requests_at_most_three_photographs` is what a
 * mistake in this class or a caller that is not a form would run into. Both
 * read the same figure from `config/dormitory.php`.
 *
 * **`location_note` is required for a common area and ignored for an own
 * room.** The register holds rooms and beds and knows nothing about the
 * kitchen on the fourth floor, so a common area has to be named in words; an
 * own room is resolved from the residency record and a note about it would be
 * the resident's guess written beside the register's answer.
 */
final class StoreMaintenanceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->building();

        if ($building === null) {
            // Nothing to decide on; the missing building is reported as a 422
            // by the rules below, which is what it is.
            return true;
        }

        return $this->user()?->can('create', [MaintenanceRequest::class, $building]) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'category' => ['required', 'string', 'in:'.implode(',', MaintenanceCategory::values())],
            'location' => ['required', 'string', 'in:'.implode(',', MaintenanceLocation::values())],
            'location_note' => [
                'required_if:location,'.MaintenanceLocation::CommonArea->value,
                'nullable',
                'string',
                'max:255',
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Long enough to say what is wrong and short enough not to be a
            // place to paste a document into. The minimum keeps «broken» out
            // of the queue, which is the one description a warden cannot act
            // on at all.
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'urgency' => ['sometimes', 'string', 'in:'.implode(',', MaintenanceUrgency::values())],
            'photos' => ['sometimes', 'array', 'max:'.MaintenanceRequest::MAX_PHOTOS],
            'photos.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.$this->maximumPhotoKilobytes(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photos.max' => sprintf(
                'A request carries at most %d photograph(s).',
                MaintenanceRequest::MAX_PHOTOS,
            ),
            'location_note.required_if' => 'A common area has to be named: the register holds rooms, not kitchens.',
        ];
    }

    public function building(): ?Building
    {
        $id = $this->input('building_id');

        return is_numeric($id) ? Building::query()->find((int) $id) : null;
    }

    public function category(): MaintenanceCategory
    {
        return MaintenanceCategory::from((string) $this->validated('category'));
    }

    public function location(): MaintenanceLocation
    {
        return MaintenanceLocation::from((string) $this->validated('location'));
    }

    public function urgency(): MaintenanceUrgency
    {
        $urgency = $this->validated('urgency');

        return is_string($urgency)
            ? MaintenanceUrgency::from($urgency)
            : MaintenanceUrgency::Routine;
    }

    public function description(): string
    {
        return trim((string) $this->validated('description'));
    }

    public function title(): ?string
    {
        $title = $this->validated('title');

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    /**
     * Null for an own room, whatever the client sent: the note belongs to the
     * common-area path and a note beside a resolved room would be a second,
     * unreliable answer to «where».
     */
    public function locationNote(): ?string
    {
        if ($this->location() === MaintenanceLocation::OwnRoom) {
            return null;
        }

        $note = $this->validated('location_note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }

    /**
     * @return list<UploadedFile>
     */
    public function photos(): array
    {
        $files = $this->file('photos');

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        return is_array($files)
            ? array_values(array_filter($files, static fn ($file): bool => $file instanceof UploadedFile))
            : [];
    }

    private function maximumPhotoKilobytes(): int
    {
        return max(1, (int) config('dormitory.maintenance.max_photo_kilobytes'));
    }
}
