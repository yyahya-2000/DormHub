<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Building;
use App\Models\User;
use App\Notifications\ResidentAccountIssued;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Password;

/**
 * FR-42: the one-time credential leaves the application here, and is created
 * here.
 *
 * **Why this class exists at all (acceptance of 14.09.2026).** The
 * notification used to be the queued thing and used to carry the code as a
 * public property. A queue serialises the object it is given, so the code was
 * written in plain text into the queue payload — readable to anything that can
 * read the store, and on a failed attempt copied into `failed_jobs`, where it
 * would sit long after the hour it was valid for. The response body and the
 * audit record were clean the whole time, which is what made the leak easy to
 * miss: the secret never travelled the road anybody was watching.
 *
 * **What travels instead.** Two model identifiers. `SerializesModels` writes
 * the resident and the dormitory as a class name and a primary key, so the
 * payload of this job is two integers and holds nothing that is worth reading.
 *
 * **The code is minted in the worker.** `Password::createToken()` runs inside
 * `handle()`, which means the plain text exists in one process, for the length
 * of one render, and is never handed to anything that stores it — the token
 * store itself keeps a hash. Three consequences follow and all three are
 * improvements. The hour the code lives starts when it is sent rather than
 * when the account was created, so a slow queue no longer eats the window. A
 * retry after a failed delivery mints a fresh code and invalidates the one
 * that never arrived, because the token repository keeps one token per
 * account. And a job that dies in `failed_jobs` leaves no credential behind,
 * because at the moment it was queued no credential existed.
 *
 * The notification the worker then builds is deliberately **not** `ShouldQueue`
 * — it is already inside the worker, and making it queueable again would put
 * the code back on the wire. `ResidentAccountIssued` says so in its own
 * docblock, and a test asserts it.
 */
final class DeliverResidentCredential implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $resident,
        public Building $building,
    ) {}

    public function handle(): void
    {
        $this->resident->notifyNow(new ResidentAccountIssued(
            token: Password::createToken($this->resident),
            buildingName: (string) $this->building->name,
            expiresInMinutes: (int) config('auth.passwords.users.expire', 60),
        ));
    }
}
