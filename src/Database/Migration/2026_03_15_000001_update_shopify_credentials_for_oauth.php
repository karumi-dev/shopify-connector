<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wk_shopify_credentials_config', function (Blueprint $table) {
            $table->string('clientId')->nullable()->after('shopUrl');
            $table->text('clientSecret')->nullable()->after('clientId');
            // Widen accessToken from VARCHAR(255) to TEXT to accommodate encrypted values
            $table->text('accessToken')->change();
            $table->timestamp('tokenExpiresAt')->nullable()->after('accessToken');
        });

        // Encrypt existing plaintext accessToken values so they work with the encrypted cast.
        // Uses Crypt::encrypt() (with PHP serialization) to match the model's 'encrypted' cast,
        // which reads values via Crypt::decrypt() with $unserialize = true.
        DB::table('wk_shopify_credentials_config')->orderBy('id')->each(function ($credential) {
            if (! $credential->accessToken) {
                return;
            }

            // Skip rows that are already properly encrypted (idempotency guard).
            // A value encrypted with Crypt::encrypt() will decrypt successfully here.
            try {
                Crypt::decrypt($credential->accessToken);

                return; // Already encrypted with the correct method, skip.
            } catch (\Exception $e) {
                // Not yet encrypted (or encrypted with encryptString); proceed.
            }

            // If the value was previously encrypted with Crypt::encryptString(), recover the
            // plain-text token first so we can re-encrypt it with the correct method.
            $plainToken = $credential->accessToken;
            try {
                $plainToken = Crypt::decryptString($credential->accessToken);
            } catch (\Exception $e) {
                // Value is plain text — use it as-is.
            }

            DB::table('wk_shopify_credentials_config')
                ->where('id', $credential->id)
                ->update([
                    'accessToken' => Crypt::encrypt($plainToken),
                ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Decrypt accessToken values back to plaintext before removing encrypted cast columns.
        DB::table('wk_shopify_credentials_config')->orderBy('id')->each(function ($credential) {
            if (! $credential->accessToken) {
                return;
            }

            // Try Crypt::decrypt() first (matches up() which uses Crypt::encrypt()).
            try {
                $decrypted = Crypt::decrypt($credential->accessToken);
                DB::table('wk_shopify_credentials_config')
                    ->where('id', $credential->id)
                    ->update(['accessToken' => $decrypted]);

                return;
            } catch (\Exception $e) {
                // Not encrypted with Crypt::encrypt(); try the older encryptString format.
            }

            try {
                $decrypted = Crypt::decryptString($credential->accessToken);
                DB::table('wk_shopify_credentials_config')
                    ->where('id', $credential->id)
                    ->update(['accessToken' => $decrypted]);
            } catch (\Exception $e) {
                // Already plaintext or unreadable; skip.
            }
        });

        Schema::table('wk_shopify_credentials_config', function (Blueprint $table) {
            $table->string('accessToken')->change();
            $table->dropColumn(['clientId', 'clientSecret', 'tokenExpiresAt']);
        });
    }
};
