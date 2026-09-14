<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `LOST_FOUND_ITEM.kind` of the ER model (§3.4.3): «lost or found».
 *
 * The module carries one table and two directions of the same notice, which is
 * why the ER puts a discriminator on the row rather than drawing two entities.
 * A find and a loss share every attribute the diagram lists — the dormitory,
 * the person who wrote it, the place, the day, the photograph — and differ in
 * one thing only: which side of the exchange is holding the object.
 *
 * **Only a find can be claimed, and that is the whole practical weight of this
 * enumeration.** FR-26 is «the resident submits a claim describing identifying
 * features» and a claim is the sentence «that is mine»; there is nothing to
 * say it about on a notice whose author has lost something and holds nothing.
 * The rule is stated once, in `StoreLostFoundClaimRequest`, and the refusal is
 * a 422 naming the field rather than a 403, because what is wrong is the
 * object the request names and not the account that named it.
 */
enum LostFoundItemKind: string
{
    /** Somebody found the object and is holding it (FR-24). */
    case Found = 'found';

    /** Somebody lost the object and is looking for it. */
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Found => 'Found',
            self::Lost => 'Lost',
        };
    }

    /**
     * Whether a claim of FR-26 can be made against a notice of this kind.
     */
    public function admitsClaims(): bool
    {
        return $this === self::Found;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
