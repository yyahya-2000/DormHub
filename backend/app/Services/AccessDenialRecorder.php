<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The refusal half of §3.9.6 (FR-33).
 *
 * A successful action records itself in the service that performs it. A
 * refusal has no such service: authorisation fails before the request reaches
 * one, so without this class the log holds only what people were allowed to
 * do. The administrator would then see nothing of the resident who walked
 * through the building identifiers one after another, which is precisely the
 * pattern the log exists to reveal.
 *
 * It is called from the exception handler, once, for every 403 the application
 * produces — not from the authorisation checks themselves, which would mean
 * repeating the call in every form request and every policy and forgetting it
 * in the next one.
 *
 * The subject is taken from the route, where model binding has already
 * resolved it: a refused `GET /buildings/2` is recorded against building 2 and
 * not against the string «2», so the entries line up with the successful ones
 * on the same object.
 */
final readonly class AccessDenialRecorder
{
    public function __construct(private AuditRecorder $audit) {}

    public function record(Request $request, Throwable $exception): void
    {
        try {
            $this->audit->record(
                action: AuditAction::AccessDenied,
                actor: $this->actor($request),
                subject: $this->subject($request),
                payload: array_filter([
                    'method' => $request->getMethod(),
                    'path' => $request->path(),
                    'route' => $request->route()?->getName(),
                    'reason' => $exception->getMessage(),
                ], fn (?string $value): bool => $value !== null && $value !== ''),
                result: AuditResult::Denied,
                ipAddress: $request->ip(),
            );
        } catch (Throwable $failure) {
            /*
             * A log that cannot be written must not turn a 403 into a 500: the
             * caller was going to be refused either way, and swallowing the
             * refusal itself would be the worse failure. The problem still
             * reaches the application log, where it is an operational fault
             * rather than an answer to the request.
             */
            Log::error('The refusal could not be written to the audit log.', [
                'exception' => $failure,
                'path' => $request->path(),
            ]);
        }
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The first route parameter that model binding resolved to a record. The
     * routes of §3.3.6 bind at most one, so «first» and «the» coincide.
     */
    private function subject(Request $request): ?Model
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return $parameter;
            }
        }

        return null;
    }
}
