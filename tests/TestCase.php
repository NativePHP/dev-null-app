<?php

namespace Tests;

use App\Devnull\Timezone;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Native\Mobile\AsyncTask;
use Native\Mobile\Support\AsyncTaskTransport;

abstract class TestCase extends BaseTestCase
{
    /**
     * Background refreshes run inline, so a screen has its fresh answer by its
     * first frame. A test that wants to see the frame before it calls
     * holdRefreshes() (see Pest.php).
     *
     * The phone's timezone is unknown, so times show in UTC whatever this
     * machine's is, until a test puts the phone somewhere (see phoneIn()).
     */
    protected function setUp(): void
    {
        parent::setUp();

        AsyncTask::fake();
        $this->app->instance(Timezone::class, new Timezone(localtime: '/nonexistent'));
    }

    protected function tearDown(): void
    {
        AsyncTask::clearFake();
        File::deleteDirectory(AsyncTaskTransport::directory());

        parent::tearDown();
    }
}
