<?php

namespace Tests;

use App\Devnull\Account;
use App\Devnull\Timezone;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Native\Mobile\AsyncTask;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\System;

abstract class TestCase extends BaseTestCase
{
    /**
     * The outbox lives in the app's database (see App\Devnull\Outbox), so a
     * test that stars, commits or pushes gets it migrated, in memory.
     */
    use LazilyRefreshDatabase;

    /**
     * The storage directory this worker has emptied and set up, once it has.
     */
    protected static ?string $workerStorage = null;

    /**
     * Under `pest --parallel` each worker gets a storage directory of its own,
     * storage/framework/testing/parallel/{token}, so one worker's tests can't
     * delete the async spool, draft images or cached files another worker's
     * test is still using. It's emptied when the worker starts. A serial run
     * uses storage/ as it always has.
     *
     * The path is set before the app boots, so the config built from
     * storage_path() (disks, logs, compiled views) follows it.
     */
    public function createApplication(): Application
    {
        if ($storage = static::workerStorage()) {
            $_ENV['LARAVEL_STORAGE_PATH'] = $storage;
        }

        return parent::createApplication();
    }

    /**
     * Background refreshes run inline, so a screen has its fresh answer by its
     * first frame. A test that wants to see the frame before it calls
     * holdRefreshes() (see Pest.php).
     *
     * Each test is a fresh launch, so the Guilds tab is decided afresh.
     *
     * The phone's timezone is unknown, so times show in UTC whatever this
     * machine's is, until a test puts the phone somewhere (see phoneIn()).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Account::forgetGuildsTabShown();
        AsyncTask::fake();
        $this->app->instance(Timezone::class, new Timezone(localtime: '/nonexistent'));
    }

    /**
     * The framework's teardown runs even when this cleanup throws. It rolls
     * back the test's transaction, and without that every later test in the
     * process fails on the one left open.
     */
    protected function tearDown(): void
    {
        try {
            AsyncTask::clearFake();
            System::rememberPlatform(null);
            File::deleteDirectory(AsyncTaskTransport::directory());
        } finally {
            parent::tearDown();
        }
    }

    /**
     * This parallel worker's storage directory, or null outside a parallel run.
     * It runs before the first app exists, so it can't use the File facade.
     */
    protected static function workerStorage(): ?string
    {
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');

        if ($token === false || $token === '') {
            return null;
        }

        if (static::$workerStorage === null) {
            $storage = Application::inferBasePath().'/storage/framework/testing/parallel/'.$token;
            $files = new Filesystem;

            $files->deleteDirectory($storage);

            foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
                $files->ensureDirectoryExists($storage.'/'.$directory);
            }

            static::$workerStorage = $storage;
        }

        return static::$workerStorage;
    }
}
