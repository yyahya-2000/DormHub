<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Consent\ConsentText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A consent text awaiting a decision (FR-35, first criterion).
 *
 * The body travels with it. The screen that asks for consent has to show the
 * wording — art. 9 part 1 of Federal Law No. 152-FZ requires consent to be
 * informed, and a client that rendered a title and a checkbox would be asking
 * for something else. The revision travels too, and the acceptance sends it
 * back: that is how the record ends up naming the text the person actually
 * read rather than whichever text was current when their request arrived.
 *
 * @mixin ConsentText
 */
final class ConsentTextResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'document' => $this->document->value,
            'revision' => $this->revision,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
