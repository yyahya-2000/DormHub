<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\LostFoundCustody;
use App\Enums\LostFoundItemKind;
use App\Models\Building;
use App\Models\LostFoundItem;
use App\Support\DormitoryClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * FR-24, `POST /api/v1/lost-found`, multipart.
 *
 * **The first criterion is settled here and the second is not**, and the split
 * is the one §3.3.3 draws. «The record is created with mandatory fields
 * category, place and date of finding» is a property of the input, so it is a
 * rule and the answer is 422. «The record is bound to the publishing user as
 * the finder» is not a field at all — the client never sends a reporter, the
 * token is the reporter, and there is nothing here to validate.
 *
 * **The fourth criterion is settled by the absence of a field.** «Publication
 * passes through no staff approval step» — so there is no `status` in the
 * rules below, no way for a client to ask for a draft, and no way for one to
 * ask for a review. The entry is `published` because that is the only state
 * publication produces (§2.5.4).
 *
 * **`custody` is validated here and authorised in the policy.** Any client may
 * *say* `administration`; only an account holding the capability in that
 * dormitory may *mean* it, and the refusal is a 403 rather than a 422 because
 * nothing about the value is malformed — it is the account that may not assert
 * it. §2.7.5: that value is the record of an object handed to the person
 * representing the owner of the premises, which is a statement about the
 * university and not about the resident.
 *
 * **`declared_on` is offered on both paths and required on neither.** It is
 * the day the find was declared to the police or to a local self-government
 * body (Civil Code art. 227 cl. 2), and the duty to declare falls on the
 * finder where the entitled person is unknown — so the resident who declared
 * it themselves may state the date, and so may the officer who filed on the
 * administration's behalf. Whether the university files such declarations at
 * all is the university's to settle; the system provides the field (§2.7.5).
 */
final class StoreLostFoundItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->building();

        if ($building === null) {
            // Nothing to decide on; the missing building is reported as a 422
            // by the rules below, which is what it is.
            return true;
        }

        $user = $this->user();

        if ($user?->can('create', [LostFoundItem::class, $building]) !== true) {
            return false;
        }

        // The deposited path of §2.5.4, which is a second and narrower
        // question. A resident may publish; a resident may not publish an
        // entry asserting that the administration is holding something.
        return $this->custody() !== LostFoundCustody::Administration
            || $user->can('deposit', [LostFoundItem::class, $building]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'building_id' => ['required', 'integer', 'exists:buildings,id'],

            // FR-24's «category»: the short line naming the object. Long
            // enough to say «a black umbrella with a wooden handle» and short
            // enough not to be the description.
            'title' => ['required', 'string', 'min:3', 'max:255'],

            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // FR-24, mandatory: where it was found, in words. The register
            // holds rooms and knows nothing about the landing between the
            // third and fourth floors.
            'place' => ['required', 'string', 'min:3', 'max:255'],

            /*
             * FR-24, mandatory: the day of the finding, which is not the day
             * of the entry. A date in the future is refused because a find
             * cannot have happened tomorrow; nothing here refuses an old one,
             * because somebody clearing a desk drawer in December may well be
             * publishing what they picked up in September.
             *
             * **«Today» is the dormitory's and not the server's** (acceptance
             * of 15.09.2026). The rule used to read `before_or_equal:today`,
             * which Laravel resolves with `strtotime()` — the server's clock,
             * in the server's zone. The application ran in UTC, so after 21:00
             * in Moscow a find picked up that evening was refused as happening
             * «later than today». `DormitoryClock` answers the day the people
             * filling in the form are living in, and it answers through Carbon,
             * so a test can stand on a day boundary and see it.
             */
            'happened_on' => ['required', 'date', 'before_or_equal:'.DormitoryClock::todayAsDate()],

            /*
             * NULL unless a declaration has actually been made. Not before the
             * finding — the CHECK constraint says the same — and not in the
             * future, because a declaration that has not happened yet is not a
             * declaration.
             */
            'declared_on' => [
                'sometimes',
                'nullable',
                'date',
                'before_or_equal:'.DormitoryClock::todayAsDate(),
                'after_or_equal:happened_on',
            ],

            'kind' => ['sometimes', 'string', 'in:'.implode(',', LostFoundItemKind::values())],
            'custody' => ['sometimes', 'string', 'in:'.implode(',', LostFoundCustody::values())],

            // FR-24, third criterion: «the photograph is optional». One, and
            // validated for type and size when it is there.
            'photo' => [
                'sometimes',
                'nullable',
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
            'happened_on.before_or_equal' => 'A find cannot have happened later than today.',
            'declared_on.after_or_equal' => 'A declaration cannot precede the finding it is about.',
        ];
    }

    public function building(): ?Building
    {
        $id = $this->input('building_id');

        return is_numeric($id) ? Building::query()->find((int) $id) : null;
    }

    public function kind(): LostFoundItemKind
    {
        $kind = $this->input('kind');

        return is_string($kind)
            ? (LostFoundItemKind::tryFrom($kind) ?? LostFoundItemKind::Found)
            : LostFoundItemKind::Found;
    }

    /**
     * Read from the raw input rather than from the validated set, because
     * `authorize()` runs before validation and needs the answer.
     */
    public function custody(): LostFoundCustody
    {
        $custody = $this->input('custody');

        return is_string($custody)
            ? (LostFoundCustody::tryFrom($custody) ?? LostFoundCustody::Finder)
            : LostFoundCustody::Finder;
    }

    public function title(): string
    {
        return trim((string) $this->validated('title'));
    }

    public function place(): string
    {
        return trim((string) $this->validated('place'));
    }

    public function description(): ?string
    {
        $description = $this->validated('description');

        return is_string($description) && trim($description) !== '' ? trim($description) : null;
    }

    public function happenedOn(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('happened_on'))->startOfDay();
    }

    public function declaredOn(): ?CarbonImmutable
    {
        $declared = $this->validated('declared_on');

        return is_string($declared) && $declared !== ''
            ? CarbonImmutable::parse($declared)->startOfDay()
            : null;
    }

    public function photo(): ?UploadedFile
    {
        $file = $this->file('photo');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function maximumPhotoKilobytes(): int
    {
        return max(1, (int) config('dormitory.lost_found.max_photo_kilobytes'));
    }
}
