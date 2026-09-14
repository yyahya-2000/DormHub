<?php

declare(strict_types=1);

namespace App\Files;

use App\Exceptions\PhotoStorageFailedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photographs on their way from a form to the object store: FR-36's up to
 * three, and FR-24's optional one.
 *
 * It sits outside `App\Services` on purpose. §3.3.1 keeps HTTP objects out of
 * the application layer, and `UploadedFile` is one: `MaintenanceService::
 * submit()` and `LostFoundService::publish()` therefore take paths, which is
 * all either register needs to know, and this class is the adapter the
 * controller calls on the way in. The alternative — a service method taking
 * uploaded files — would make the register unusable from a console command or
 * a seeder, which are exactly the two callers that already exist.
 *
 * **It sits in `App\Files` and not in `App\Maintenance`, which is where it was
 * written.** The lost-and-found module of increment 4 stores one photograph
 * per find on the same terms — a generated name, a configured disk, a path in
 * the column — and the choice was between a second copy of this class and a
 * neutral home for the one that exists. A copy would have been two definitions
 * of «how a photograph is stored», and the second of them would have been the
 * one nobody updated when the disk changed. The disk, the directory and the
 * ceiling are constructor arguments precisely so that one class can serve two
 * modules with two configurations, and `AppServiceProvider` binds each module
 * its own instance.
 *
 * **The database holds paths and never the files.** §3.2.2 puts the object
 * store in a container of its own; a photograph of a burst pipe is a few
 * hundred kilobytes and a dormitory files a few thousand requests a year, so a
 * BLOB column would put a gigabyte of binary into every backup and every
 * replication stream of a database whose rows are otherwise tiny.
 *
 * The name is generated and never taken from the client. A file name arrives
 * from a browser and can be anything at all — a path traversal, a name that
 * collides with somebody else's upload, or simply the resident's own name;
 * `Storage::putFile()` draws a random one and keeps only the extension.
 */
final readonly class PhotoStore
{
    public function __construct(
        private string $disk,
        private string $directory,
        private int $maximum,
    ) {}

    /**
     * Store at most `$maximum` of the uploaded files and return their paths.
     *
     * The ceiling is applied here as well as in the form request and in the
     * CHECK constraint, and the repetition is deliberate: the form rule
     * produces the 422 a client can read, the constraint is what a mistake in
     * this class would run into, and this line is what keeps a caller that is
     * not a form — a seeder, a later import — inside FR-36's «up to three».
     *
     * @param  list<UploadedFile>  $files
     * @return list<string>
     *
     * @throws PhotoStorageFailedException the disk refused the write (503)
     */
    public function store(array $files): array
    {
        $paths = [];

        foreach (array_slice($files, 0, $this->maximum) as $file) {
            $paths[] = $this->write($file);
        }

        return $paths;
    }

    /**
     * FR-24's single photograph, which is optional: null in, null out.
     *
     * A method of its own rather than `store([$file])[0] ?? null` at the call
     * site, because the find's column holds one path and the unwrapping would
     * then be repeated by every caller — the controller, the seeder and
     * whatever imports a backlog later. The ceiling still applies: a store
     * configured with `maximum: 1` keeps this the only file it will take.
     *
     * @throws PhotoStorageFailedException the disk refused the write (503)
     */
    public function storeOne(?UploadedFile $file): ?string
    {
        if ($file === null) {
            return null;
        }

        return $this->write($file);
    }

    /**
     * One file onto the disk, or an exception — and never a quiet nothing.
     *
     * **This method is the acceptance finding of 15.09.2026.** The loop above
     * used to keep the path when `putFile()` returned a string and to skip it
     * otherwise, which reads as caution and behaves as data loss: the
     * deployment's bucket did not exist, `'throw' => false` turned every
     * `UnableToWriteFile` into a `false`, and the route answered 201 with an
     * empty list of photographs. A resident cannot tell that answer from a
     * submission they forgot to attach anything to.
     *
     * Two things were wrong and both are fixed here. The disks now throw
     * (`config/filesystems.php`), so a refused write arrives as an exception
     * carrying the reason; and a `false` that survives anyway — a driver that
     * reports failure by return value — is turned into the same exception, so
     * there is no path by which a lost file becomes a 201.
     */
    private function write(UploadedFile $file): string
    {
        try {
            $path = Storage::disk($this->disk)->putFile($this->directory, $file);
        } catch (FilesystemException|RuntimeException $failure) {
            throw new PhotoStorageFailedException($this->disk, $failure);
        }

        if (! is_string($path) || $path === '') {
            throw new PhotoStorageFailedException($this->disk);
        }

        return $path;
    }

    /**
     * The file itself, streamed back to the reader.
     *
     * **A signed link would be the cheaper answer and it does not work here**
     * (acceptance of 15.09.2026). The S3-compatible store is signed for as
     * `minio:9000`, a name that exists only inside the Compose network, and
     * SigV4 covers the Host header — so a browser can neither resolve the link
     * nor be handed a rewritten one. Streaming is the one answer that works
     * unchanged on both disks the project has, and it keeps the photograph
     * behind the same token as the record it belongs to. See
     * `App\Http\Controllers\Api\V1\Concerns\ServesPhotographs`.
     *
     * Streamed and not read into a string: a photograph is a few hundred
     * kilobytes and there is no reason for it to pass through the request's
     * memory limit on its way out.
     */
    public function stream(string $path): ?StreamedResponse
    {
        $disk = Storage::disk($this->disk);

        if (! $disk->exists($path)) {
            return null;
        }

        return $disk->response($path);
    }

    public function disk(): string
    {
        return $this->disk;
    }
}
