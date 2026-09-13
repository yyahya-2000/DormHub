<?php

declare(strict_types=1);

namespace App\Consent;

use App\Enums\ConsentDocument;

/**
 * One revision of one consent text: what the person was shown, and the
 * identifier the record keeps.
 *
 * A value object rather than a model, because a revision is not a row. It is a
 * file under version control, and that is the point: art. 9 part 3 of Federal
 * Law No. 152-FZ puts the burden of proving consent on the operator, and a
 * record that stored only «revision 2026-09-01» would prove nothing unless the
 * wording of 2026-09-01 could still be produced. Under `resources/consent` it
 * can, with the commit that introduced it and the date it was introduced on.
 */
final readonly class ConsentText
{
    public function __construct(
        public ConsentDocument $document,
        public string $revision,
        public string $title,
        public string $body,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'document' => $this->document->value,
            'revision' => $this->revision,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
