<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\LostFoundCustody;
use App\Enums\LostFoundItemKind;
use App\Models\Building;
use App\Models\LostFoundClaim;
use App\Models\LostFoundItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * The cast every lost-and-found scenario needs: a dormitory, two residents who
 * actually live in it — one who found something and one who lost it — and the
 * staff whose two capabilities the module's two exceptions are drawn around.
 *
 * It builds on `BuildsAMaintenanceScenario` rather than repeating it, because
 * the four things all three modules need are the same four: a dormitory, a
 * resident the register knows, a member of staff holding one role in one
 * building, and a real one-pixel photograph the `image` rule will accept.
 *
 * Everyone and everything it invents is invented (C-05).
 */
trait BuildsALostFoundScenario
{
    use BuildsAMaintenanceScenario;

    /**
     * The body of a well-formed publication, with the three fields FR-24's
     * first criterion makes mandatory. Overrides go on top, so a test that is
     * about one field names one field.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function publication(Building $building, array $overrides = []): array
    {
        return array_merge([
            'building_id' => $building->getKey(),
            'title' => 'A black umbrella with a wooden handle',
            'place' => 'The landing between the third and fourth floors',
            'happened_on' => CarbonImmutable::now()->subDay()->toDateString(),
        ], $overrides);
    }

    /**
     * An invented photograph of a find: the same one-pixel PNG the maintenance
     * scenarios use, for the same reason — the application image carries no
     * GD, and a text file with a `.png` name would be refused by the `image`
     * rule and the test would pass for the wrong reason.
     */
    protected function photographOfAFind(string $name = 'umbrella.png'): UploadedFile
    {
        return $this->photograph($name);
    }

    /**
     * A real PNG that is too large for the route to take.
     *
     * Not `UploadedFile::fake()->image()->size()`, which draws the picture
     * with GD and throws where the extension is absent — the application image
     * of this project carries no GD, for the reason
     * `BuildsAMaintenanceScenario::photograph()` gives. Not a text file with a
     * `.png` name either: the `image` rule reads the file through `fileinfo`
     * and would refuse it before the size rule was ever reached, so the test
     * would pass for the wrong reason and prove nothing about the ceiling.
     *
     * So the one-pixel PNG is padded with a `tEXt` chunk of incompressible
     * bytes. The signature and the IHDR are untouched, `fileinfo` still reads
     * `image/png`, and the file is as large as the test needs — which is the
     * only arrangement that reaches the size rule with a valid image.
     */
    protected function oversizedPhotograph(int $kilobytes = 4096, string $name = 'huge.png'): UploadedFile
    {
        $png = (string) base64_decode(self::ONE_PIXEL_PNG, true);

        // The IEND chunk is the last twelve bytes; the padding goes in front
        // of it, which is where a PNG reader expects ancillary chunks.
        $body = substr($png, 0, -12);
        $end = substr($png, -12);

        $data = "Comment\0".random_bytes($kilobytes * 1024);
        $chunk = pack('N', strlen($data)).'tEXt'.$data;
        $chunk .= pack('N', crc32('tEXt'.$data));

        $path = (string) tempnam(sys_get_temp_dir(), 'lost-found-photo');

        file_put_contents($path, $body.$chunk.$end);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /**
     * A published find that stays with the resident who found it: §2.5.4's
     * default path, and the one the whole module is built around.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function findOf(User $finder, Building $building, array $overrides = []): LostFoundItem
    {
        return LostFoundItem::factory()
            ->forBuilding($building)
            ->from($finder)
            ->create($overrides);
    }

    /**
     * A find the administration is holding — an object handed in at the post
     * or deposited for safekeeping, which is Civil Code art. 227 cl. 1 para. 2
     * and §2.5.4's second exception.
     */
    protected function depositedFind(
        User $officer,
        Building $building,
        ?CarbonImmutable $declaredOn = null,
    ): LostFoundItem {
        return LostFoundItem::factory()
            ->forBuilding($building)
            ->from($officer)
            ->depositedWithTheAdministration($declaredOn)
            ->create();
    }

    /**
     * A notice of a loss rather than of a find: the other value of
     * `LOST_FOUND_ITEM.kind` in the ER model, which admits no claims.
     */
    protected function lossOf(User $owner, Building $building): LostFoundItem
    {
        return LostFoundItem::factory()
            ->forBuilding($building)
            ->from($owner)
            ->lost()
            ->create();
    }

    /**
     * The body of a well-formed claim: identifying marks long enough for the
     * person holding the object to judge them, which is what FR-26 asks a
     * claim to carry.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function claimBody(array $overrides = []): array
    {
        return array_merge([
            'message' => 'The handle is chipped on the underside and there is a strip of blue tape near the tip.',
        ], $overrides);
    }

    /**
     * An outstanding claim on an entry, written straight to the table.
     *
     * Used where the claim is the scenario's *given* rather than its subject.
     * Where the filing itself is under test the route is called, because a
     * claim written past the service would not have moved the entry into
     * `claimed` and the test would be asserting against a state the
     * application never produces.
     */
    protected function claimOn(LostFoundItem $item, User $claimant, ?string $marks = null): LostFoundClaim
    {
        $claim = LostFoundClaim::factory()->on($item)->by($claimant);

        return ($marks === null ? $claim : $claim->describing($marks))->create();
    }

    /**
     * The two enumerations spelled out where a test needs the value rather
     * than the case, so that a change to either is a compile error here and
     * not a silently passing assertion.
     */
    protected function foundKind(): string
    {
        return LostFoundItemKind::Found->value;
    }

    protected function administrationCustody(): string
    {
        return LostFoundCustody::Administration->value;
    }
}
