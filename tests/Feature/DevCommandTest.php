<?php

test('artisan dev dry run lists all standard dev processes including schedule:work', function () {
    $this->artisan('dev', ['--dry-run' => true])
        ->expectsTable(['Process', 'Command'], [
            ['server', 'php artisan serve --host=127.0.0.1 --port=8000'],
            ['queue', 'php artisan queue:listen --tries=1 --timeout=0'],
            ['schedule', 'php artisan schedule:work'],
            ['reverb', 'php artisan reverb:start'],
        ])
        ->assertSuccessful();
});

test('artisan dev dry run respects exclusion flags', function () {
    $this->artisan('dev', [
        '--dry-run' => true,
        '--no-reverb' => true,
        '--no-schedule' => true,
        '--port' => '8080',
    ])
        ->expectsTable(['Process', 'Command'], [
            ['server', 'php artisan serve --host=127.0.0.1 --port=8080'],
            ['queue', 'php artisan queue:listen --tries=1 --timeout=0'],
        ])
        ->assertSuccessful();
});

test('artisan dev returns success with warning when all processes are disabled', function () {
    $this->artisan('dev', [
        '--dry-run' => true,
        '--no-server' => true,
        '--no-queue' => true,
        '--no-schedule' => true,
        '--no-reverb' => true,
    ])
        ->expectsOutput('No development processes selected to run.')
        ->assertSuccessful();
});
