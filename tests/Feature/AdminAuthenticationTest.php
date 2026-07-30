<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withSession(['_token' => 'test-token']);
});

test('guest can open the admin login page', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('admin\/login', false);
});

test('guest cannot access the admin dashboard', function () {
    $this->get('/admin')
        ->assertRedirect('/admin/login');
});

test('admin can log in with normalized email', function () {
    $admin = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('StrongPassword123!'),
    ]);

    $sessionId = session()->getId();

    $this->post('/admin/login', [
        '_token' => 'test-token',
        'email' => ' ADMIN@EXAMPLE.COM ',
        'password' => 'StrongPassword123!',
    ])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($admin);
    expect(session()->getId())->not->toBe($sessionId);
});

test('invalid credentials return a generic error', function () {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('StrongPassword123!'),
    ]);

    $this->from('/admin/login')->post('/admin/login', [
        '_token' => 'test-token',
        'email' => 'admin@example.com',
        'password' => 'wrong-password',
    ])
        ->assertRedirect('/admin/login')
        ->assertSessionHasErrors([
            'email' => 'Email atau kata sandi tidak sesuai.',
        ]);

    $this->assertGuest();
});

test('failed admin logins are rate limited with a useful error', function () {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->post('/admin/login', [
            '_token' => 'test-token',
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');
    }

    $response = $this->post('/admin/login', [
        '_token' => 'test-token',
        'email' => 'missing@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))
        ->toStartWith('Terlalu banyak percobaan login. Coba lagi dalam ');
});

test('authenticated admin can enter the dashboard shell', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertSee('admin\/dashboard', false)
        ->assertSee($admin->email);
});

test('authenticated admin is redirected away from login', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/login')
        ->assertRedirect('/admin');
});

test('guest cannot call the admin logout endpoint', function () {
    $this->post('/admin/logout', ['_token' => 'test-token'])
        ->assertRedirect('/admin/login');
});

test('admin can log out and the previous session data is invalidated', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['sensitive' => 'value'])
        ->post('/admin/logout', ['_token' => 'test-token'])
        ->assertRedirect('/admin/login')
        ->assertSessionMissing('sensitive');

    $this->assertGuest();
});
