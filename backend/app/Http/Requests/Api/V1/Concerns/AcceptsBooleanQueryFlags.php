<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Concerns;

/**
 * `?archived=true`, which is what a generated client actually sends.
 *
 * **The acceptance finding of 15.09.2026.** Laravel's `boolean` rule admits
 * `true`, `false`, `1`, `0`, `"1"` and `"0"` — and not the strings `"true"`
 * and `"false"`. A query string carries strings and nothing else, the contract
 * declares these parameters `type: boolean`, and the generated client
 * serialises a query parameter with `String(value)`. So the Archive button on
 * the announcements screen sent `archived=true` and was answered 422 by a
 * route whose own contract says the value is a boolean. The test suite passed
 * throughout, because the tests wrote `archived=1` — the one spelling nobody's
 * client produces.
 *
 * The normalisation happens before validation rather than in the rule, so that
 * the rule still refuses what it should: `archived=maybe` is not a boolean in
 * any spelling and stays a 422 naming the field. `filter_var` answers null for
 * anything it does not recognise, and the original value is then left standing
 * for the validator to reject.
 *
 * A trait and not two copies, because the two routes that take a flag this way
 * — the announcement feed and the room register — would otherwise be two
 * places for the next one to be forgotten in.
 */
trait AcceptsBooleanQueryFlags
{
    protected function normaliseBooleanFlags(string ...$keys): void
    {
        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if (! is_string($value)) {
                continue;
            }

            $asBoolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($asBoolean !== null) {
                $this->merge([$key => $asBoolean]);
            }
        }
    }
}
