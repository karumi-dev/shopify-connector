<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\DataGrids\Catalog\CredentialDataGrid;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Helpers\ShoifyApiVersion;
use Webkul\Shopify\Http\Requests\CredentialForm;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\ShopifyTokenService;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class CredentialController extends Controller
{
    use ShopifyGraphqlRequest;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShopifyTokenService $tokenService,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        if (request()->ajax()) {
            return app(CredentialDataGrid::class)->toJson();
        }

        $apiVersion = (new ShoifyApiVersion)->getApiVersion();

        return view('shopify::credential.index', compact('apiVersion'));
    }

    /**
     * Create a new Shopify credential.
     */
    public function store(CredentialForm $request): JsonResponse
    {
        $data = $request->all();
        $url = $data['shopUrl'];
        $url = $data['shopUrl'] = rtrim($url, '/');

        if (strpos($url, 'http') !== 0) {
            return new JsonResponse([
                'errors' => [
                    'shopUrl' => [trans('shopify::app.shopify.credential.invalidurl')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $credential = $this->shopifyRepository->findWhere(['shopUrl' => $url])->first();

        if ($credential) {
            return new JsonResponse([
                'errors' => [
                    'shopUrl' => [trans('shopify::app.shopify.credential.already_taken')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $tokenData = $this->tokenService->fetchToken($url, $data['clientId'], $data['clientSecret']);
        } catch (InvalidCredential $e) {
            return new JsonResponse([
                'errors' => [
                    'clientId'     => [trans('shopify::app.shopify.credential.invalid')],
                    'clientSecret' => [trans('shopify::app.shopify.credential.invalid')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data['accessToken'] = $tokenData['access_token'];
        $expiresIn = $tokenData['expires_in'] ?? 86399;
        $data['tokenExpiresAt'] = Carbon::now()->addSeconds($expiresIn - 60);
        $data['active'] = 1;

        // Validate the token works by making a test GraphQL call
        $response = $this->requestGraphQlApiAction('getOneProduct', $data);

        if ($response['code'] != JsonResponse::HTTP_OK) {
            return new JsonResponse([
                'errors' => [
                    'clientId'     => [trans('shopify::app.shopify.credential.invalid')],
                    'clientSecret' => [trans('shopify::app.shopify.credential.invalid')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $credentialCreate = $this->shopifyRepository->create($data);

            session()->flash('success', trans('shopify::app.shopify.credential.created'));
        } catch (\Exception $e) {
            return new JsonResponse([
                'errors' => [
                    'shopUrl' => [$e->getMessage()],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'redirect_url' => route('shopify.credentials.edit', $credentialCreate->id),
        ]);
    }

    /**
     * Delete a Shopify credential by ID.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->shopifyRepository->delete($id);

        return new JsonResponse([
            'message' => trans('shopify::app.shopify.credential.delete-success'),
        ]);
    }

    /**
     * Edit a Shopify credential by ID.
     *
     * @return View
     */
    public function edit(int $id)
    {
        $credential = $this->shopifyRepository->find($id);

        if (! $credential) {
            abort(404);
        }

        $credentialData = $credential->getAttributes();

        $response = $this->requestGraphQlApiAction('getShopPublishedLocales', $credentialData);

        $publishing = $this->requestGraphQlApiAction('getPublications', $credentialData);

        $locationGetting = $this->requestGraphQlApiAction('getignLocations', $credentialData);

        $locationAll = $locationGetting['body']['data']['locations']['edges'] ?? [];

        $publishingChannel = $publishing['body']['data']['publications']['edges'] ?? [];

        $shopLocales = $response['body']['data']['shopLocales'] ?? [];

        $apiVersion = (new ShoifyApiVersion)->getApiVersion();

        $credential->clientSecret = str_repeat('*', 40);

        return view('shopify::credential.edit', compact('credential', 'shopLocales', 'publishingChannel', 'locationAll', 'apiVersion'));
    }

    /**
     * Update a Shopify credential by ID.
     *
     * @return JsonResponse
     */
    public function update(int $id)
    {
        $requestData = request()->except(['code']);
        $credential = $this->shopifyRepository->find($id);

        if (! $credential) {
            abort(404);
        }

        $params = $this->validate(request(), [
            'shopUrl'      => 'required|url',
            'clientId'     => 'required',
            'clientSecret' => 'required',
        ]);

        $maskedSecret = str_repeat('*', 40);

        if (str_contains($requestData['clientSecret'] ?? '', $maskedSecret)) {
            $requestData['clientSecret'] = $credential->clientSecret;
        }

        // Re-validate credentials if clientId or clientSecret changed
        $credentialsChanged = $requestData['clientId'] !== $credential->clientId
            || $requestData['clientSecret'] !== $credential->clientSecret;

        if ($credentialsChanged) {
            try {
                $tokenData = $this->tokenService->fetchToken(
                    $requestData['shopUrl'],
                    $requestData['clientId'],
                    $requestData['clientSecret']
                );

                $requestData['accessToken'] = $tokenData['access_token'];
                $expiresIn = $tokenData['expires_in'] ?? 86399;
                $requestData['tokenExpiresAt'] = Carbon::now()->addSeconds($expiresIn - 60);
            } catch (InvalidCredential $e) {
                return redirect()->route('shopify.credentials.edit', $id)
                    ->withErrors([
                        'clientId'     => trans('shopify::app.shopify.credential.invalid'),
                        'clientSecret' => trans('shopify::app.shopify.credential.invalid'),
                    ])
                    ->withInput();
            }
        }

        // Validate the credentials work with a test GraphQL call
        $testData = $requestData;
        $testData['accessToken'] = $testData['accessToken'] ?? $credential->accessToken;
        $testData['apiVersion'] = $testData['apiVersion'] ?? $credential->apiVersion;

        $response = $this->requestGraphQlApiAction('getOneProduct', $testData);

        if ($response['code'] != 200) {
            return redirect()->route('shopify.credentials.edit', $id)
                ->withErrors([
                    'clientId'     => trans('shopify::app.shopify.credential.invalid'),
                    'clientSecret' => trans('shopify::app.shopify.credential.invalid'),
                ])
                ->withInput();
        }

        $keyOrder = ['name', 'locale', 'primary', 'published'];

        $languages = json_decode($requestData['storeLocales'], true);

        $languages = array_map(function ($item) use ($keyOrder) {
            return array_merge(array_flip($keyOrder), $item);
        }, $languages);

        $languages = array_map(function ($language) {
            if ($language['primary']) {
                $language['defaultlocale'] = true;
            }

            return $language;
        }, $languages);

        $requestData['storeLocales'] = $languages;

        $extras = $credential->extras;

        $extras['locations'] = $requestData['locations'];

        $extras['salesChannel'] = $requestData['salesChannel'];

        $requestData['extras'] = $extras;

        unset($requestData['salesChannel']);
        unset($requestData['locations']);

        $this->shopifyRepository->update($requestData, $id);

        session()->flash('success', trans('shopify::app.shopify.credential.update-success'));

        return redirect()->route('shopify.credentials.edit', $id);
    }
}
