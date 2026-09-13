<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ConsentDocument;
use RuntimeException;

/**
 * FR-35, first criterion, at the point where it bites: the processing was
 * asked for and there is no consent on record for it.
 *
 * The one caller in increment 0 is a test; the one caller of increment 1 is
 * `CheckpointService::checkIn()`, which asks before it writes an entry into
 * the visitor register. §2.7.1 is why it has to be refused rather than
 * recorded with a note: a guest is not a party to the accommodation contract,
 * so consent is the only ground there is, and an entry written without it is
 * processing without a ground.
 *
 * **Not 403.** A 403 in this application means «your role does not cover this
 * object», it is recorded in the audit log as `access.denied`, and the
 * security officer's role covers the checkpoint perfectly well. What is
 * missing is a document, and the state of the world is what stands in the way
 * — which is 409, the same status FR-01's blocked deletion answers with, and
 * for the same reason. The body names the document and the revision, so the
 * client knows which text to put on the screen.
 */
final class ConsentRequiredException extends RuntimeException
{
    public function __construct(
        public readonly ConsentDocument $document,
        public readonly string $revision,
    ) {
        parent::__construct(sprintf(
            'This cannot be recorded without consent to the processing of personal data: '
            .'«%s» has not been given, or has been withdrawn.',
            $this->document->title(),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return [
            'document' => $this->document->value,
            'revision' => $this->revision,
        ];
    }
}
