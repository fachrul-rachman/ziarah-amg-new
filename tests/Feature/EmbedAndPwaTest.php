<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    Setting::query()->create([
        'id' => 1,
        'daily_booking_limit' => 20,
        'operations_email' => 'operations@example.com',
        'embed_allowed_origins' => [],
    ]);
});

test('booking routes deny embedding when no origin is approved', function (
    string $route,
) {
    $this->get($route)
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'")
        ->assertSee('"component":"booking"', false);
})->with(['/', '/embed/booking']);

test('booking routes allow only configured frame ancestors', function () {
    Setting::query()->findOrFail(1)->update([
        'embed_allowed_origins' => [
            'https://www.alazharmemorialgarden.com',
            'https://preview.example.com:8443',
        ],
    ]);

    $expected = 'frame-ancestors https://www.alazharmemorialgarden.com '
        .'https://preview.example.com:8443';

    $this->get('/embed/booking')
        ->assertOk()
        ->assertHeader('Content-Security-Policy', $expected)
        ->assertHeaderMissing('X-Frame-Options');

    expect($expected)->not->toContain('https://attacker.example');
});

test('dynamic private and availability responses cannot be cached', function () {
    $this->get('/admin/login')
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->getJson('/api/public/booking-options')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->post('/api/public/bookings')
        ->assertUnsupportedMediaType()
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('responses include baseline security headers without weakening private routes', function () {
    $this->get('/')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader(
            'Permissions-Policy',
            'camera=(), geolocation=(), microphone=()',
        )
        ->assertHeader(
            'Referrer-Policy',
            'strict-origin-when-cross-origin',
        );

    $this->get('/admin/login')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

test('health endpoint is ready when the configured database responds', function () {
    $this->get('/up')->assertOk();
});

test('pwa manifest icons offline shell and service worker are complete and safe', function () {
    $manifest = json_decode(
        file_get_contents(public_path('manifest.webmanifest')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest)
        ->toMatchArray([
            'name' => 'Ziarah Al Azhar Memorial Garden',
            'short_name' => 'Ziarah AMG',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => '#1796C7',
            'background_color' => '#FFFFFF',
        ])
        ->and(collect($manifest['icons'])->pluck('sizes')->all())
        ->toContain('192x192', '512x512')
        ->and(file_exists(public_path('offline.html')))->toBeTrue();

    foreach ($manifest['icons'] as $icon) {
        $path = public_path(ltrim($icon['src'], '/'));
        $dimensions = getimagesize($path);

        expect(file_exists($path))->toBeTrue()
            ->and($dimensions)->not->toBeFalse()
            ->and($dimensions[0].'x'.$dimensions[1])->toBe($icon['sizes']);
    }

    $serviceWorker = file_get_contents(public_path('sw.js'));
    $app = file_get_contents(resource_path('js/app.tsx'));

    expect($serviceWorker)
        ->toContain('/offline.html', '/build/assets/')
        ->not->toContain('caches.put(request')
        ->and($app)->toContain("navigator.serviceWorker.register('/sw.js')");
});
