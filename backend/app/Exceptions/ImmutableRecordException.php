<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

/**
 * An append-only table was asked to change or drop a row (§4.4.4).
 *
 * Unlike the other exceptions in this namespace this one carries no status
 * code and is mapped to none. It is not an answer to a caller — no route
 * offers the operation — but a fault in the code that issued it, and it should
 * surface as one.
 */
final class ImmutableRecordException extends LogicException
{
    public static function for(string $model, string $operation): self
    {
        return new self(sprintf(
            '%s is append-only: %s is refused. The database refuses it as well (§4.4.4).',
            class_basename($model),
            $operation,
        ));
    }
}
