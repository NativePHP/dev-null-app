<?php

namespace Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Native\Mobile\AsyncTask;
use Native\Mobile\Support\AsyncTaskTransport;

abstract class TestCase extends BaseTestCase
{
    /**
     * The outbox lives in the app's database (see App\Devnull\Outbox), so a
     * test that stars, commits or pushes gets it migrated, in memory.
     */
    use LazilyRefreshDatabase;

    /**
     * Background refreshes run inline, so a screen has its fresh answer by its
     * first frame. A test that wants to see the frame before it calls
     * holdRefreshes() (see Pest.php).
     */
    protected function setUp(): void
    {
        parent::setUp();

        AsyncTask::fake();
    }

    protected function tearDown(): void
    {
        AsyncTask::clearFake();
        File::deleteDirectory(AsyncTaskTransport::directory());

        parent::tearDown();
    }
}
