<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The `result` field of the record format §3.9.6 prescribes:
 * «who, what, over which object, when, from which IP, result».
 */
enum AuditResult: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Denied = 'denied';
}
