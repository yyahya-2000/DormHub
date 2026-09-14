<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Files\PhotoStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handing one stored photograph back to the client that is about to show it
 * (FR-36, FR-24).
 *
 * **There was no way to do this at all until the acceptance of 15.09.2026.**
 * The schemas of `MaintenanceRequest` and `LostFoundItem` have always said that
 * `photo_paths` and `photo_path` carry paths rather than URLs, and that the
 * client asks for the image when it is about to draw it. There was nothing to
 * ask: no route, and `PhotoStore::temporaryUrl()` was called from nowhere. A
 * photograph a resident attached to a request could be read by nobody —
 * including the warden who was supposed to triage on it.
 *
 * **The route answers the bytes and not a signed link, and the second
 * acceptance pass of 15.09.2026 is why.** The first fix handed out a presigned
 * URL, which is what the schema descriptions had in mind and what saves the
 * API from carrying the image. It does not work: the store is signed for as
 * `minio:9000`, a name that exists only inside the Compose network, and SigV4
 * covers the Host header — so a browser cannot follow the link and cannot be
 * given a rewritten one either (`localhost:9000` answers
 * `SignatureDoesNotMatch`). Making it work meant a reverse proxy in front of
 * the object store, putting the Host header back, plus a second base URL in
 * configuration: a good deal of machinery for a deployment nobody outside this
 * repository runs.
 *
 * Streaming works unchanged on both disks the project has — the S3-compatible
 * store of §3.2.2 and the local disk of a stand without one — and it keeps the
 * object store unreachable from outside the network, which is the arrangement
 * §3.2.2 draws. It also keeps the photograph behind the same token as the
 * record: a presigned URL is a bearer credential in a query string, and one
 * forwarded out of a chat window is a photograph of somebody's room readable
 * by a stranger.
 *
 * What it costs is that the image passes through the application. A photograph
 * is a few hundred kilobytes and a dormitory files a few thousand requests a
 * year; the route streams rather than reading the file into memory, so the
 * cost is a socket held open and not a request-sized allocation.
 *
 * The authorisation is the policy of the record the photograph belongs to and
 * never a policy of its own. A photograph is part of the request or of the
 * find, so whoever may read the card may see the picture on it and nobody else
 * may — one question, asked in one place, and no second definition of «this
 * dormitory» to drift away from the first.
 */
trait ServesPhotographs
{
    protected function photographResponse(PhotoStore $photos, string $path): StreamedResponse
    {
        // `stream()` answers null where the path is on the record and the
        // object is not in the store, which is a 404 and not a 500: the record
        // is intact and the file behind it is missing.
        return $photos->stream($path) ?? abort(404, 'The photograph is not in the store.');
    }
}
