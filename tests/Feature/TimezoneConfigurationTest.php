<?php

use Illuminate\Support\Facades\DB;

it('stores time in UTC and evaluates business rules in Asia Jakarta', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.business_timezone'))->toBe('Asia/Jakarta')
        ->and(config('database.connections.pgsql.timezone'))->toBe('UTC');
});

it('sets only this PostgreSQL connection session to UTC', function () {
    expect(DB::selectOne('SHOW TIME ZONE')->TimeZone)->toBe('UTC');
});
