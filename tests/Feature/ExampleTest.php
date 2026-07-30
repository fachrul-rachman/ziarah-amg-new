<?php

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('"component":"booking"', false)
        ->assertSee('turnstileSiteKey');
});

test('management link renders the booking management application', function () {
    $this->get('/manage/example-token')
        ->assertOk()
        ->assertSee('"component":"manage-booking"', false)
        ->assertSee('"token":"example-token"', false);
});
