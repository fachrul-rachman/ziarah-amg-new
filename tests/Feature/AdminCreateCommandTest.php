<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('the first admin can be created securely from the console', function () {
    $this->artisan('admin:create', [
        'email' => 'ADMIN@EXAMPLE.COM',
        '--name' => 'Administrator',
    ])
        ->expectsQuestion('Password', 'StrongPassword123!')
        ->assertSuccessful();

    $admin = User::query()->sole();

    expect($admin->name)->toBe('Administrator')
        ->and($admin->email)->toBe('admin@example.com')
        ->and(Hash::check('StrongPassword123!', $admin->password))->toBeTrue();
});
