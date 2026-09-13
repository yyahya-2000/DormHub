<?php

declare(strict_types=1);

namespace App\Guests;

use App\Models\GuestRequest;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * `GUEST_REQUEST.access_code` (§3.4.3), issued on approval and never before
 * (§3.5.1: «so that before the duty officer decides nothing can be presented
 * at the post»).
 *
 * **The alphabet is the whole design.** The code is read aloud over a
 * telephone, copied off a screen onto paper and typed in by a person standing
 * at a desk with somebody waiting. So the confusable characters are gone —
 * `0` and `O`, `1` and `I` and `L`, `5` and `S`, `8` and `B` — which is what
 * Crockford's base 32 does and for the same reason. What is left is twenty-six
 * symbols; eight of them give about 2·10^11 combinations, and the code is a
 * lookup key for one evening rather than a password, so that is ample.
 *
 * **It is not a secret and the design does not pretend otherwise.** Presenting
 * the code admits nobody on its own: §3.5.1 splits `verify` from `check-in`
 * precisely so that a person appears at the desk, the officer compares the
 * card against the document in their hand, and only then records the entry. A
 * guessed code gets an attacker a name and a masked document number in front
 * of an officer who is looking at neither.
 *
 * **Uniqueness is asserted against the database rather than assumed.** The
 * column carries a unique index; this loop is what turns the collision the
 * index would refuse into another draw, and the ceiling is what stops it
 * spinning if the index is ever the thing that is wrong.
 */
final class AccessCodeGenerator
{
    private const ALPHABET = 'ACDEFGHJKMNPQRTUVWXYZ234679';

    private const LENGTH = 8;

    private const ATTEMPTS = 20;

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $code = $this->draw();

            if (! GuestRequest::query()->where('access_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException(sprintf(
            'No free access code after %d draws. Either the alphabet is exhausted, '
            .'which it cannot be at this size, or the uniqueness index is not what it should be.',
            self::ATTEMPTS,
        ));
    }

    private function draw(): string
    {
        $alphabet = self::ALPHABET;
        $last = strlen($alphabet) - 1;
        $code = '';

        for ($position = 0; $position < self::LENGTH; $position++) {
            $code .= $alphabet[random_int(0, $last)];
        }

        return $code;
    }

    /**
     * The shape a code presented at the post has to have, before anything is
     * looked up. Upper case, the alphabet above, exactly the declared length.
     */
    public static function pattern(): string
    {
        return '/^['.self::ALPHABET.']{'.self::LENGTH.'}$/';
    }

    /**
     * A code typed in lower case, or with the spaces a person puts in to read
     * it back, is the same code. Normalising here rather than in the request
     * keeps the rule beside the alphabet that defines it.
     */
    public static function normalise(string $code): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }
}
