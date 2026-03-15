<?php

use Illuminate\Support\Facades\Http;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

it('should returns the shopify credential index page', function () {
    $this->loginAsAdmin();

    get(route('shopify.credentials.index'))
        ->assertStatus(200)
        ->assertSeeText(trans('shopify::app.shopify.credential.index.title'));
});

it('should returns the shopify credential edit page', function () {
    $this->loginAsAdmin();

    $shopifyCredential = ShopifyCredentialsConfig::factory()->create();

    Http::fake([
        'https://demotest.myshopify.com/admin/api/2025-01/graphql.json' => Http::response(['data' => ['shopLocales' => []]], 200),
    ]);

    get(route('shopify.credentials.edit', ['id' => $shopifyCredential->id]))
        ->assertStatus(200);
});

it('should create the shopify credential with valid input', function () {
    $this->loginAsAdmin();

    Http::fake([
        'https://test.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'new_oauth_token',
            'expires_in'   => 86399,
            'scope'        => 'read_products,write_products',
        ], 200),
        'https://test.myshopify.com/admin/api/2025-01/graphql.json' => Http::response(['code' => 200], 200),
    ]);

    $shopifyCredential = [
        'clientId'     => 'test_client_id',
        'clientSecret' => 'test_client_secret',
        'apiVersion'   => '2025-01',
        'shopUrl'      => 'https://test.myshopify.com',
    ];

    post(route('shopify.credentials.store'), $shopifyCredential)
        ->assertStatus(200);

    $this->assertDatabaseHas('wk_shopify_credentials_config', [
        'shopUrl'  => 'https://test.myshopify.com',
        'clientId' => 'test_client_id',
    ]);
});

it('should return error for invalid URL during credential create', function () {
    $this->loginAsAdmin();

    $shopifyCredential = [
        'clientId'     => 'test_client_id',
        'clientSecret' => 'test_client_secret',
        'apiVersion'   => '2025-01',
        'shopUrl'      => 'test.myshopify.com',
    ];

    $response = post(route('shopify.credentials.store'), $shopifyCredential)
        ->assertStatus(422);

    $this->assertArrayHasKey('errors', $response->json());
    $this->assertEquals(trans('shopify::app.shopify.credential.invalidurl'), $response->json('errors.shopUrl.0'));
});

it('should return error for invalid credentials', function () {
    $this->loginAsAdmin();

    Http::fake([
        'https://test.myshopify.com/admin/oauth/access_token' => Http::response([
            'error' => 'invalid_client',
        ], 401),
    ]);

    $shopifyCredential = [
        'clientId'     => 'bad_client_id',
        'clientSecret' => 'bad_client_secret',
        'apiVersion'   => '2025-01',
        'shopUrl'      => 'https://test.myshopify.com',
    ];

    $response = post(route('shopify.credentials.store'), $shopifyCredential)
        ->assertStatus(422);

    $this->assertArrayHasKey('errors', $response->json());
    $this->assertEquals(trans('shopify::app.shopify.credential.invalid'), $response->json('errors.clientId.0'));
    $this->assertEquals(trans('shopify::app.shopify.credential.invalid'), $response->json('errors.clientSecret.0'));
});

it('should update the shopify credential successfully', function () {
    $this->loginAsAdmin();

    $credential = ShopifyCredentialsConfig::factory()->create([
        'shopUrl'      => 'https://test.myshopify.com',
        'apiVersion'   => '2025-01',
        'clientId'     => 'original_client_id',
        'clientSecret' => 'original_client_secret',
    ]);

    Http::fake([
        'https://test.myshopify.com/admin/api/2025-01/graphql.json' => Http::response(['code' => 200], 200),
    ]);

    $maskedSecret = str_repeat('*', 40);

    $updatedData = [
        'shopUrl'      => 'https://test.myshopify.com',
        'clientId'     => 'original_client_id',
        'clientSecret' => $maskedSecret,
        'storeLocales' => json_encode([['locale' => 'en', 'primary' => true]]),
        'salesChannel' => 'online',
        'locations'    => 'location1',
        'apiVersion'   => '2025-01',
    ];

    $response = $this->put(route('shopify.credentials.update', ['id' => $credential->id]), $updatedData);

    $response->assertRedirect(route('shopify.credentials.edit', ['id' => $credential->id]));
    $response->assertSessionHas('success', trans('shopify::app.shopify.credential.update-success'));

    $this->assertDatabaseHas('wk_shopify_credentials_config', [
        'id'       => $credential->id,
        'shopUrl'  => 'https://test.myshopify.com',
        'clientId' => 'original_client_id',
    ]);
});

it('should returns the shopify credential edit page, with validation', function () {
    $this->loginAsAdmin();

    $shopifyCredential = ShopifyCredentialsConfig::factory()->create();
    $updatedCredential = [
        'id'           => $shopifyCredential->id,
        'clientId'     => '',
        'clientSecret' => '',
        'apiVersion'   => $shopifyCredential->apiVersion,
        'shopUrl'      => $shopifyCredential->shopUrl,
        'storeLocales' => [],
        'active'       => 0,
    ];

    put(route('shopify.credentials.update', $shopifyCredential->id), $updatedCredential)
        ->assertStatus(302)
        ->assertSessionHasErrors(['clientId', 'clientSecret']);
});

it('should delete the shopify credential', function () {
    $this->loginAsAdmin();

    $shopifyCredential = ShopifyCredentialsConfig::factory()->create();

    delete(route('shopify.credentials.delete', $shopifyCredential->id))
        ->assertStatus(200);

    $this->assertDatabaseMissing($this->getFullTableName(ShopifyCredentialsConfig::class), [
        'id' => $shopifyCredential->id,
    ]);
});
