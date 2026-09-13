<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ConsentDocument;
use App\Services\ConsentTexts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * FR-35: `POST /api/v1/consents`.
 *
 * Two fields, and both of them are the requirement.
 *
 * `document` names **which** consent is being given, and it is required. Art. 9
 * part 1 of Federal Law No. 152-FZ has consent «executed separately from other
 * documents»: there is no «accept all», no array of documents in one request,
 * and no way to arrive here as a side effect of another call.
 *
 * `revision` is the text the person actually read. It is required, and it is
 * checked against the revision in force — not merely against the set of
 * revisions that exist. A client that held the screen open while the operator
 * published a new text would otherwise record consent to a wording that was
 * withdrawn from the screen, and the record would name a text this person
 * never saw. Refusing with 422 sends the client back for the current text,
 * which is the only honest thing to do.
 */
final class StoreConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', Rule::enum(ConsentDocument::class)],
            'revision' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $document = $this->document();
                $current = app(ConsentTexts::class)->currentRevision($document);

                if ($this->revision() !== $current) {
                    $validator->errors()->add('revision', sprintf(
                        'The text in force for this document is revision «%s». '
                        .'Read it and consent to that one.',
                        $current,
                    ));
                }

                /*
                 * The guest's document is given at the security post, in
                 * person, and not through the personal account of whoever is
                 * signed in. Recording it here would attach a guest's consent
                 * to a resident's account, which is neither what §2.7.1
                 * describes nor what the record would then mean.
                 */
                if (! $document->isAskedAtFirstLogin()) {
                    $validator->errors()->add('document', sprintf(
                        '«%s» is not given from a personal account.',
                        $document->title(),
                    ));
                }
            },
        ];
    }

    public function document(): ConsentDocument
    {
        return ConsentDocument::from((string) $this->input('document'));
    }

    public function revision(): string
    {
        return (string) $this->input('revision');
    }
}
