<?php

declare(strict_types=1);

namespace App\Services;

use App\Consent\ConsentText;
use App\Enums\ConsentDocument;
use RuntimeException;

/**
 * FR-35, third criterion: the record keeps «the fact, the date and the text
 * revision». This class is the other end of that — the place the revision
 * identifier points to.
 *
 * **Why the text is a file and not a row.** A consent text changes rarely,
 * changes by decision of the operator's responsible officer, and has to be
 * producible years later exactly as it was shown (art. 9 part 3 of Federal Law
 * No. 152-FZ puts the burden of proof on the operator). A file under
 * `resources/consent` has a commit, an author and a date behind it and cannot
 * be altered without that being visible; a row in a table can be updated by
 * anyone who can reach the table, and the record pointing at it would then say
 * the person agreed to something they never read.
 *
 * **The current revision is configuration.** `config/dormitory.php` names it,
 * so publishing a new text is: add the file, change the value. Everyone whose
 * last consent names the old revision is then pending again — which is what a
 * changed text ought to mean, and which falls out of
 * `ConsentRegistry::pendingFor()` without a line about it.
 */
final class ConsentTexts
{
    /** @var array<string, ConsentText> */
    private array $loaded = [];

    /**
     * The revision that is in force for this document today.
     */
    public function current(ConsentDocument $document): ConsentText
    {
        return $this->revision($document, $this->currentRevision($document));
    }

    public function currentRevision(ConsentDocument $document): string
    {
        $revision = config('dormitory.consent.revisions.'.$document->value);

        if (! is_string($revision) || $revision === '') {
            throw new RuntimeException(sprintf(
                'No current revision is configured for the consent document «%s».',
                $document->value,
            ));
        }

        return $revision;
    }

    /**
     * A named revision, current or superseded. A withdrawn consent from two
     * revisions ago is read back through this method, which is the whole
     * reason superseded files are kept rather than overwritten.
     */
    public function revision(ConsentDocument $document, string $revision): ConsentText
    {
        $key = $document->value.'@'.$revision;

        return $this->loaded[$key] ??= $this->read($document, $revision);
    }

    public function has(ConsentDocument $document, string $revision): bool
    {
        return is_file($this->path($document, $revision));
    }

    private function read(ConsentDocument $document, string $revision): ConsentText
    {
        $path = $this->path($document, $revision);

        if (! is_file($path)) {
            throw new RuntimeException(sprintf(
                'Revision «%s» of the consent document «%s» is not in the repository (%s). '
                .'A revision a record points at must remain readable: the operator has to be able '
                .'to show what the person actually agreed to.',
                $revision,
                $document->value,
                $path,
            ));
        }

        return new ConsentText(
            document: $document,
            revision: $revision,
            title: $document->title(),
            body: trim((string) file_get_contents($path)),
        );
    }

    /**
     * One directory per document, one file per revision.
     *
     * The revision is checked against a strict shape before it reaches the
     * filesystem. It arrives from a request body, and a path built out of
     * unvalidated input is the classic way to read a file nobody meant to
     * publish; the document code cannot be tampered with the same way, because
     * it has already been through `ConsentDocument::from()`.
     */
    private function path(ConsentDocument $document, string $revision): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', $revision) !== 1 || str_contains($revision, '..')) {
            throw new RuntimeException(sprintf('«%s» is not a well-formed revision identifier.', $revision));
        }

        return rtrim((string) config('dormitory.consent.path'), '/')
            .'/'.$document->value
            .'/'.$revision.'.md';
    }
}
