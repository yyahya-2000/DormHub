<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * FR-23, second criterion: «a request with a foreign document cannot be
 * approved with an interval extending beyond one day without a mark by the
 * responsible officer».
 *
 * **What the mark is and what it is not.** It is not a migration
 * notification. Art. 20 part 2 of Federal Law No. 109-FZ puts the arrival
 * notification on the receiving party, and §2.7.4 keeps its automatic
 * submission outside the perimeter (FR-W2); whether a dormitory falls within
 * the class of accommodation facilities that have one working day rather than
 * seven is a question for the legal service and not for a developer. The mark
 * is the duty officer recording that the officer responsible for migration
 * registration — stakeholder class S8 — has been told and has agreed, before
 * an interval that runs past midnight is approved for a foreign national.
 *
 * The program therefore refuses to be the place where that step is skipped
 * silently, and claims nothing further.
 *
 * 422: the decision asked for is not one the rules admit in the form it
 * arrived in. The same call with the mark attached succeeds.
 */
final class ResponsibleOfficerMarkRequiredException extends RuntimeException
{
    public function __construct(
        public readonly int $requestId,
        public readonly string $documentType,
    ) {
        parent::__construct(
            'This guest presents a foreign document and the interval runs past midnight. '
            .'Art. 20 of Federal Law No. 109-FZ makes the arrival notification the receiving '
            .'party\'s duty, so the approval needs a mark by the officer responsible for '
            .'migration registration before it can be given.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'guest_request_id' => $this->requestId,
            'guest_doc_type' => $this->documentType,
            'required_field' => 'responsible_officer_mark',
        ];
    }
}
