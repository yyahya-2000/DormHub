<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Jobs\AnnounceToAudience;
use App\Models\Announcement;
use App\Models\Building;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The write side of the announcement module (FR-09).
 *
 * *Purpose*: publishes an announcement.
 * *Subordinates*: `AuditRecorder`, and the queue for the fan-out.
 * *Dependencies*: the domain layer only; no controller, no HTTP object, no
 * status code (§3.3.1).
 *
 * The module is the lightest in the MVP (§4.6.1): no state machine, no
 * external actor, no scheduled job. Two properties of the one method below are
 * nevertheless the same ones the guest module is careful about — the audit
 * record is written **inside** the transaction of the change it describes, and
 * the notification is dispatched **after** that transaction has committed.
 */
final readonly class AnnouncementService
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * FR-09: the warden, the manager or the administrator publishes.
     *
     * `$building` null addresses every dormitory (§3.4.2). Whether this author
     * is allowed to leave it null is settled by `AnnouncementPolicy::publish`
     * before this method is reached, because it is a question about the
     * caller's grant and 403 is the answer (§3.3.3).
     *
     * **`published_at` is the moment of publication and is not accepted from
     * the client.** The column exists so that the feed can be sorted and so
     * that a seeded stand can hold notices of different ages; a route that let
     * a warden set it would let him post a notice dated last week, and the
     * audit record of the publication — «this was posted on the ninth» — would
     * then describe a date he had chosen rather than one the system observed.
     *
     * **`$category` is a free label.** It arrives trimmed and bounded at 32
     * characters by `StoreAnnouncementRequest`; the column takes it as it
     * stands.
     */
    public function publish(
        User $author,
        ?Building $building,
        string $title,
        string $body,
        string $category,
        ?CarbonInterface $expiresAt = null,
        ?string $ipAddress = null,
    ): Announcement {
        $announcement = DB::transaction(function () use (
            $author, $building, $title, $body, $category, $expiresAt, $ipAddress
        ): Announcement {
            $announcement = Announcement::query()->create([
                'building_id' => $building?->getKey(),
                'author_id' => $author->getKey(),
                'title' => $title,
                'body' => $body,
                'category' => $category,
                'published_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $this->audit->record(
                action: AuditAction::AnnouncementPublished,
                actor: $author,
                subject: $announcement,
                payload: [
                    // Null in the payload as in the column, and for the same
                    // reason: «every dormitory» is an addressee and not an
                    // omission, and the log has to be able to say which of the
                    // two a notice was.
                    'building_id' => $building?->getKey(),
                    'title' => $title,
                    'category' => $category,
                    'expires_at' => $expiresAt?->toIso8601String(),
                ],
                ipAddress: $ipAddress,
            );

            return $announcement;
        });

        /*
         * After the commit, never inside it. A worker that reached the row
         * before the commit would find no such announcement, and a fan-out
         * queued from inside a transaction that then rolled back would be
         * several hundred messages about a notice that does not exist.
         *
         * The dispatch itself is one insert; resolving the audience and
         * passing it through the notification gate happens in the worker,
         * which is what keeps the publishing request inside NFR-02 for a
         * dormitory of several hundred places (§4.6.1).
         */
        AnnounceToAudience::dispatch($announcement);

        return $announcement;
    }
}
