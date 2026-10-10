<?php

namespace Tests;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Prevent network failures when Reverb daemon is offline during automated tests
        $broadcaster = $this->app->make(BroadcastManager::class)->connection('reverb');
        if ($broadcaster instanceof PusherBroadcaster) {
            $pusher = \Mockery::mock($broadcaster->getPusher())->makePartial();
            $pusher->shouldReceive('trigger')->byDefault()->andReturn((object) ['status' => 200]);
            $broadcaster->setPusher($pusher);
        }
    }
}
