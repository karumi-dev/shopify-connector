<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;
use Webkul\Shopify\Services\ShopifyTokenService;

it('should fetch a token with valid client credentials', function () {
    Http::fake([
        'https://test.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_new_token_123',
            'expires_in'   => 86399,
            'scope'        => 'read_products,write_products',
        ], 200),
    ]);

    $service = new ShopifyTokenService;

    $result = $service->fetchToken(
        'https://test.myshopify.com',
        'valid_client_id',
        'valid_client_secret'
    );

    expect($result)->toHaveKey('access_token', 'shpat_new_token_123');
    expect($result)->toHaveKey('expires_in', 86399);
    expect($result)->toHaveKey('scope', 'read_products,write_products');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://test.myshopify.com/admin/oauth/access_token'
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'valid_client_id'
            && $request['client_secret'] === 'valid_client_secret';
    });
});

it('should throw InvalidCredential on 401 response', function () {
    Http::fake([
        'https://test.myshopify.com/admin/oauth/access_token' => Http::response([
            'error' => 'invalid_client',
        ], 401),
    ]);

    $service = new ShopifyTokenService;

    $service->fetchToken(
        'https://test.myshopify.com',
        'bad_client_id',
        'bad_client_secret'
    );
})->throws(InvalidCredential::class, 'Invalid client credentials');

it('should throw InvalidCredential on 403 response', function () {
    Http::fake([
        'https://test.myshopify.com/admin/oauth/access_token' => Http::response([
            'error' => 'insufficient_scope',
        ], 403),
    ]);

    $service = new ShopifyTokenService;

    $service->fetchToken(
        'https://test.myshopify.com',
        'client_id',
        'client_secret'
    );
})->throws(InvalidCredential::class, 'Insufficient access scopes');

it('should return cached token when not expired', function () {
    $credential = ShopifyCredentialsConfig::factory()->oauth()->create([
        'shopUrl'        => 'https://cached.myshopify.com',
        'accessToken'    => 'cached_valid_token',
        'tokenExpiresAt' => Carbon::now()->addHours(12),
        'clientId'       => 'some_client_id',
        'clientSecret'   => 'some_client_secret',
    ]);

    Http::fake();

    $service = new ShopifyTokenService;

    $token = $service->refreshIfNeeded($credential);

    expect($token)->toBe('cached_valid_token');

    Http::assertNothingSent();
});

it('should auto-refresh when token is expired', function () {
    $credential = ShopifyCredentialsConfig::factory()->oauth()->create([
        'shopUrl'        => 'https://expired.myshopify.com',
        'accessToken'    => 'old_expired_token',
        'tokenExpiresAt' => Carbon::now()->subHour(),
        'clientId'       => 'refresh_client_id',
        'clientSecret'   => 'refresh_client_secret',
    ]);

    Http::fake([
        'https://expired.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'fresh_new_token',
            'expires_in'   => 86399,
            'scope'        => 'read_products',
        ], 200),
    ]);

    $service = new ShopifyTokenService;

    $token = $service->refreshIfNeeded($credential);

    expect($token)->toBe('fresh_new_token');

    $credential->refresh();
    expect($credential->accessToken)->toBe('fresh_new_token');
    expect($credential->tokenExpiresAt)->toBeInstanceOf(Carbon::class);
    expect($credential->tokenExpiresAt->isFuture())->toBeTrue();
});

it('should apply 60-second expiry buffer', function () {
    $credential = ShopifyCredentialsConfig::factory()->oauth()->create([
        'shopUrl'        => 'https://buffer.myshopify.com',
        'accessToken'    => 'old_token',
        'tokenExpiresAt' => Carbon::now()->subMinute(),
        'clientId'       => 'buffer_client_id',
        'clientSecret'   => 'buffer_client_secret',
    ]);

    Http::fake([
        'https://buffer.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'buffered_token',
            'expires_in'   => 86399,
            'scope'        => 'read_products',
        ], 200),
    ]);

    $service = new ShopifyTokenService;
    $service->refreshIfNeeded($credential);

    $credential->refresh();

    // Token expiry should be ~86339 seconds from now (86399 - 60 buffer)
    $expectedExpiry = Carbon::now()->addSeconds(86399 - 60);
    expect($credential->tokenExpiresAt->diffInSeconds($expectedExpiry))->toBeLessThan(5);
});

it('should refresh when tokenExpiresAt is null', function () {
    $credential = ShopifyCredentialsConfig::factory()->oauth()->create([
        'shopUrl'        => 'https://null-expiry.myshopify.com',
        'accessToken'    => 'some_token',
        'tokenExpiresAt' => null,
        'clientId'       => 'null_client_id',
        'clientSecret'   => 'null_client_secret',
    ]);

    Http::fake([
        'https://null-expiry.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'token_after_null',
            'expires_in'   => 86399,
            'scope'        => 'read_products',
        ], 200),
    ]);

    $service = new ShopifyTokenService;

    $token = $service->refreshIfNeeded($credential);

    expect($token)->toBe('token_after_null');
});

it('should normalize http to https in shop URL', function () {
    Http::fake([
        'https://http-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'https_token',
            'expires_in'   => 86399,
            'scope'        => 'read_products',
        ], 200),
    ]);

    $service = new ShopifyTokenService;

    $result = $service->fetchToken(
        'http://http-shop.myshopify.com',
        'client_id',
        'client_secret'
    );

    expect($result['access_token'])->toBe('https_token');
});
