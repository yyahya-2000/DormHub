<?php

declare(strict_types=1);

namespace App\Maintenance;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * FR-36's photographs, on their way from the form to the object store.
 *
 * It sits in `App\Maintenance` and not in `App\Services` on purpose. §3.3.1
 * keeps HTTP objects out of the application layer, and `UploadedFile` is one:
 * `MaintenanceService::submit()` therefore takes paths, which is all the
 * register needs to know, and this class is the adapter the controller calls
 * on the way in. The alternative — a service method taking uploaded files —
 * would make the register unusable from a console command or a seeder, which
 * are exactly the two callers that already exist.
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
     */
    public function store(array $files): array
    {
        $paths = [];

        foreach (array_slice($files, 0, $this->maximum) as $file) {
            $path = Storage::disk($this->disk)->putFile($this->directory, $file);

            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * A temporary URL for reading one photograph back, where the disk can
     * produce one, and a plain path where it cannot.
     *
     * The S3-compatible disk of the deployment signs a URL that expires; the
     * local disk of the test suite does not implement signing at all, and the
     * whole point of returning null there is that a test must not silently
     * assert a link that only works because the file happened to be public.
     */
    public function temporaryUrl(string $path, int $minutes = 15): ?string
    {
        $disk = Storage::disk($this->disk);

        if (! $disk->exists($path)) {
            return null;
        }

        try {
            return $disk->temporaryUrl($path, now()->addMinutes($minutes));
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function disk(): string
    {
        return $this->disk;
    }
}
