<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * A disk that takes no file and says so the quiet way.
 *
 * **It reproduces the acceptance finding of 15.09.2026 exactly.** The
 * deployment pointed the photograph disk at the S3-compatible store, nobody
 * created the bucket, and every write came back as `NoSuchBucket`; the disk was
 * configured with `'throw' => false`, so Laravel turned the exception into a
 * `false` and `PhotoStore` dropped the path. The route answered 201 with no
 * photographs attached and the resident had no way of knowing.
 *
 * **Why a disk and not a wrong bucket name.** `tests/TestCase.php` pins the two
 * photograph disks to `local` on purpose — a submission test must not write its
 * invented pictures into the real MinIO bucket — and that pinning is what kept
 * the defect invisible: every photograph assertion was made against a disk the
 * application does not use outside the test suite. The pinning stays; this
 * class is the other half of the answer. A test that wants a refused write
 * registers this disk under a name of its own and points the module's
 * configuration at it, so the refusal is reproduced without a real store and
 * without loosening the guard around the real one.
 *
 * `putFile` and `putFileAs` answer `false` rather than throwing, because
 * `false` is the *harder* case of the two: an exception would be noticed by any
 * caller at all, and the return value is what a caller has to remember to look
 * at. A store that turns this into a refusal of the request turns the thrown
 * kind into one as well.
 */
final class RefusesEveryWrite extends FilesystemAdapter
{
    /**
     * Register the disk under `$name` and answer the name, so a test reads
     * `config([... => RefusesEveryWrite::registerAs('refusing')])`.
     */
    public static function registerAs(string $name): string
    {
        $root = storage_path('framework/testing/disks/'.$name);
        $adapter = new LocalFilesystemAdapter($root);

        Storage::set($name, new self(new Filesystem($adapter), $adapter, ['root' => $root]));

        return $name;
    }

    /**
     * @param  string  $path
     * @param  mixed  $file
     * @param  mixed  $options
     */
    public function putFile($path, $file = null, $options = []): string|false
    {
        return false;
    }

    /**
     * @param  string  $path
     * @param  mixed  $file
     * @param  mixed  $name
     * @param  mixed  $options
     */
    public function putFileAs($path, $file, $name = null, $options = []): string|false
    {
        return false;
    }
}
