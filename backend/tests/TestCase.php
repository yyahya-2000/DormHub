<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pin the stores a test run must not share with the running application.
     *
     * The <env> entries of phpunit.xml cannot do this on their own: they do not
     * overwrite a variable that the container already exports, so a run inside
     * the development environment inherits CACHE_STORE=redis. The rate limiter
     * then carries its counters from one test into the next and from one run
     * into the next, and the login tests of FR-08 fail on a 429 that has nothing
     * to do with the behaviour they assert.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'filesystems.default' => 'local',
            /*
             * FR-36's photographs. The disk is named in `config/dormitory.php`
             * and the deployment points it at the S3-compatible store, so
             * pinning `filesystems.default` alone is not enough: a submission
             * test would have written its invented photographs into the real
             * MinIO bucket, and `Storage::fake('local')` would have asserted
             * against an empty disk while the files piled up elsewhere.
             */
            'dormitory.maintenance.photo_disk' => 'local',
        ]);

        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance('Illuminate\Cache\RateLimiter');
    }
}
