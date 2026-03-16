<?php

namespace Webkul\Shopify\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;

class ShopifyTokenService
{
    /**
     * Buffer in seconds to refresh the token before it actually expires.
     */
    protected const EXPIRY_BUFFER_SECONDS = 60;

    /**
     * Fetch a new access token from Shopify using the client credentials grant.
     *
     * @return array{access_token: string, expires_in: int, scope: string}
     *
     * @throws InvalidCredential
     */
    public function fetchToken(string $shopUrl, string $clientId, string $clientSecret): array
    {
        $shopUrl = rtrim(str_replace('http://', 'https://', $shopUrl), '/');
        $tokenUrl = $shopUrl.'/admin/oauth/access_token';

        try {
            $response = Http::asForm()
                ->timeout(30)
                ->retry(3, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException)
                ->post($tokenUrl, [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                ]);
        } catch (\Exception $e) {
            throw new InvalidCredential('Failed to connect to Shopify: '.$e->getMessage());
        }

        if ($response->status() === 401) {
            throw new InvalidCredential('Invalid client credentials: Client ID or Client Secret is incorrect.');
        }

        if ($response->status() === 403) {
            throw new InvalidCredential('Insufficient access scopes for the provided credentials.');
        }

        if (! $response->successful()) {
            throw new InvalidCredential('Token request failed (HTTP '.$response->status().'): '.$response->body());
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new InvalidCredential('Token response did not contain an access token.');
        }

        return $data;
    }

    /**
     * Ensure the credential has a valid (non-expired) access token.
     * Refreshes the token if it is expired or missing.
     *
     * @return string The valid access token.
     *
     * @throws InvalidCredential
     */
    public function refreshIfNeeded(ShopifyCredentialsConfig $credential): string
    {
        if (! $credential->isTokenExpired()) {
            return $credential->accessToken;
        }

        $tokenData = $this->fetchToken(
            $credential->shopUrl,
            $credential->clientId,
            $credential->clientSecret
        );

        $expiresIn = $tokenData['expires_in'] ?? 86399;

        $credential->update([
            'accessToken'    => $tokenData['access_token'],
            'tokenExpiresAt' => Carbon::now()->addSeconds($expiresIn - self::EXPIRY_BUFFER_SECONDS),
        ]);

        return $tokenData['access_token'];
    }
}
