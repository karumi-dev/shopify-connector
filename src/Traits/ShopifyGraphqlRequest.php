<?php

namespace Webkul\Shopify\Traits;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Models\JobTrack;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Http\Client\GraphQLApiClient;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;
use Webkul\Shopify\Services\ShopifyTokenService;

/**
 * Trait for making GraphQL API requests to Shopify.
 */
trait ShopifyGraphqlRequest
{
    /**
     * Sends a GraphQL API request to Shopify based on the provided mutation type and credentials.
     *
     * @param  string  $mutationType  The GraphQL mutation type or query to execute.
     * @param  array|null  $credential  Optional. Shopify credentials including 'shopUrl', 'clientId', 'clientSecret', and 'apiVersion'.
     * @param  array|null  $formatedVariable  Optional. Variables to be sent with the GraphQL query or mutation.
     * @return array The response from Shopify's GraphQL API.
     */
    protected function requestGraphQlApiAction(string $mutationType, ?array $credential = [], ?array $formatedVariable = []): array
    {
        if (! $credential || ! isset($credential['shopUrl'], $credential['apiVersion'])) {
            throw new \InvalidArgumentException('Invalid Shopify credentials provided.');
        }

        $accessToken = $this->resolveAccessToken($credential);

        $client = new GraphQLApiClient($credential['shopUrl'], $accessToken, $credential['apiVersion']);

        $response = $client->request($mutationType, $formatedVariable);

        if (
            (! $response['code'] || in_array($response['code'], [401, 404]))
            && property_exists($this, 'export')
            && $this->export instanceof JobTrack
        ) {
            $this->export->state = ExportHelper::STATE_FAILED;
            $this->export->errors = [trans('shopify::app.shopify.export.errors.invalid-credential')];
            $this->export->save();

            throw new InvalidCredential;
        }

        return $response;
    }

    /**
     * Resolve a valid access token, refreshing via OAuth if the credential has an ID in the database.
     */
    protected function resolveAccessToken(array $credential): string
    {
        if (isset($credential['id'])) {
            $model = ShopifyCredentialsConfig::find($credential['id']);

            if ($model) {
                try {
                    if ($model->clientId && $model->clientSecret) {
                        $tokenService = app(ShopifyTokenService::class);

                        return $tokenService->refreshIfNeeded($model);
                    }

                    $token = $model->accessToken;

                    // Handle the case where the token was encrypted without PHP serialization
                    // (e.g. via Crypt::encryptString() in older migrations). In this case
                    // decrypt() returns false instead of the actual token string.
                    if ($token === false || $token === null) {
                        $rawToken = $model->getRawOriginal('accessToken');
                        if ($rawToken) {
                            return Crypt::decryptString($rawToken);
                        }

                        throw new InvalidCredential(
                            'No valid access token found. Please re-save your Shopify credentials.'
                        );
                    }

                    return $token;
                } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                    // Decryption failed — the APP_KEY has likely changed since the credentials
                    // were saved (e.g. after running php artisan key:generate).
                    throw new InvalidCredential(
                        'Shopify credentials cannot be decrypted. This is usually caused by the '
                        .'application encryption key (APP_KEY) changing after the credentials were '
                        .'saved. Please re-save your Shopify credentials to resolve this issue.'
                    );
                }
            }
        }

        if (isset($credential['accessToken'])) {
            return $credential['accessToken'];
        }

        throw new \InvalidArgumentException('No access token or OAuth credentials available.');
    }

    /**
     * Attempts to download the image from the provided URL.
     */
    public function handleUrlField(mixed $imageUrl, string $imagePath): string|bool
    {

        try {
            $response = Http::get($imageUrl);

            if ($response->failed()) {
                return false;
            }

            $imageContents = $response->body();

            $path = parse_url($imageUrl, PHP_URL_PATH);

            $fileName = basename($path);

            if (! preg_match('/\.[a-zA-Z0-9]+$/', $fileName)) {
                $fileName .= '.png';
            }

            $path = $imagePath.$fileName;

            StorageFacade::disk('public')->put($path, $imageContents);

            return $path;
        } catch (\Exception $e) {
            return false;
        }
    }
}
