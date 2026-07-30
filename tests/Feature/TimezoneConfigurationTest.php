<?php

it('stores time in UTC and evaluates business rules in Asia Jakarta', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.business_timezone'))->toBe('Asia/Jakarta');
});
