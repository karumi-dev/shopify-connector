# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel/PHP package (`unopim/shopify-connector`) that synchronizes product data between UnoPim (PIM system) and Shopify via Shopify's GraphQL API. Installs as a Laravel module into an UnoPim application.

## Build & Development Commands

```bash
# Frontend assets (CSS via Vite)
npm run dev          # Dev server with HMR
npm run build        # Production build

# Package installation (run from UnoPim root)
php artisan shopify-package:install
php artisan optimize:clear
composer dump-autoload
php artisan migrate
php artisan db:seed --class="Webkul\Shopify\Database\Seeders\ShopifySettingConfigurationValuesSeeder"

# Queue worker (required for import/export jobs)
php artisan queue:work
```

## Testing

### Feature Tests (Pest PHP)

```bash
# All tests (run from UnoPim root)
./vendor/bin/pest ./vendor/unopim/shopify-connector/tests

# Single test file
./vendor/bin/pest ./vendor/unopim/shopify-connector/tests/Feature/CredentialTest.php
```

Tests extend `ShopifyTestCase` (in `tests/ShopifyTestCase.php`) which extends UnoPim's base `Tests\TestCase`. Uses `Http::fake()` to mock Shopify API calls. Tests use the `UserAssertions` trait from `Webkul\User\Tests\Concerns`.

### E2E Tests (Playwright)

```bash
npx playwright install                    # Install browsers
npx playwright test                       # Run all E2E tests
npx playwright test tests/shopify/mapping/shopify-import.spec.js  # Single test
```

E2E tests live in `tests/e2e-pw/` and run against Firefox and Chromium in headless mode.

## Architecture

### Data Flow

Routes (`src/Routes/shopify-routes.php`) → Controllers (`src/Http/Controllers/`) → Helpers (Exporters/Importers in `src/Helpers/`) → `GraphQLApiClient` (`src/Http/Client/`) → Shopify GraphQL API

All admin routes are under `/admin/shopify`.

### Import/Export System

The core of the connector. Configured in `src/Config/exporters.php` (3 types) and `src/Config/importers.php` (5 types).

- **Exporters** (`src/Helpers/Exporters/`): Product, Category, MetaField — extend `AbstractExporter`, push UnoPim data to Shopify
- **Importers** (`src/Helpers/Importers/`): Product, Category, Attribute, Family, Metafield — extend `AbstractImporter`, pull Shopify data into UnoPim (product batch size: 50)
- **Iterators** (`src/Helpers/Iterator/`): Handle Shopify API pagination for products and categories
- **Validators** (`src/Validators/`): Job validators that run before import/export processing

### Key Patterns

- **Repository Pattern**: `src/Repositories/` — data access layer for credentials, mappings, export mappings, metafields
- **GraphQL Client**: `src/Http/Client/GraphQLApiClient.php` — handles auth headers, URL construction, request/response for Shopify API (API version: 2025-01)
- **Token Service**: `src/Services/ShopifyTokenService.php` — manages OAuth 2.0 client credentials grant tokens (24-hour expiry, auto-refresh with 60s buffer)
- **Trait Composition**: `ShopifyGraphqlRequest` (API calls with auto token refresh), `DataMappingTrait` (field mapping), `TranslationTrait` (i18n), `ValidatedBatched` (batch processing with validation)
- **Service Provider**: `ShopifyServiceProvider` registers all configs, migrations, views, routes, translations, and console commands

### Models & Database

4 Eloquent models in `src/Models/`: ShopifyCredentialsConfig, ShopifyMappingConfig, ShopifyExportMappingConfig, ShopifyMetaFieldConfig. Migrations in `src/Database/Migration/`.

### Authentication

Uses OAuth 2.0 client credentials grant. Credentials are stored as `clientId` and `clientSecret` (encrypted at rest via Laravel's `encrypted` cast). Access tokens are auto-managed:
- Token endpoint: `POST https://{shop}/admin/oauth/access_token` with `grant_type=client_credentials`
- Tokens expire every 24 hours (86399 seconds)
- `ShopifyTokenService` automatically refreshes tokens 60 seconds before expiry
- Token refresh is transparent — integrated into `ShopifyGraphqlRequest` trait's `requestGraphQlApiAction()` method
- Both `accessToken` and `clientSecret` fields use Laravel's `encrypted` cast for at-rest encryption

### Localization

8 languages supported (ar_AE, de_DE, en_US, es_ES, fr_FR, hi_IN, ru_RU, zh_CN). Translation keys follow: `shopify::app.shopify.[section].[field]`.
