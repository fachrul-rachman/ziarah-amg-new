<?php

use Illuminate\Support\Facades\DB;

test('the automated suite only uses the isolated testing database', function () {
    expect(DB::connection()->getDatabaseName())->toEndWith('_testing');
});
