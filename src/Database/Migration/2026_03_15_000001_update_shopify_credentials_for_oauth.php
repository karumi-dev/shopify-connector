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
            // Widen accessToken from VARCHAR(255) to TEXT for longer token values
            $table->text('accessToken')->change();
            $table->timestamp('tokenExpiresAt')->nullable()->after('accessToken');
        });

        // Ensure existing accessToken values are stored as plaintext.
        // Previous versions of this migration may have encrypted them; decrypt if needed.
        DB::table('wk_shopify_credentials_config')->orderBy('id')->each(function ($credential) {
            if (! $credential->accessToken) {
                return;
            }

            // Try to decrypt — if it succeeds, the value was encrypted and we store the plaintext.
            // If it fails, the value is already plaintext — leave it as-is.
            try {
                $decrypted = Crypt::decrypt($credential->accessToken);
                DB::table('wk_shopify_credentials_config')
                    ->where('id', $credential->id)
                    ->update(['accessToken' => $decrypted]);

                return;
            } catch (\Exception $e) {
                // Not encrypted with Crypt::encrypt()
            }

            try {
                $decrypted = Crypt::decryptString($credential->accessToken);
                DB::table('wk_shopify_credentials_config')
                    ->where('id', $credential->id)
                    ->update(['accessToken' => $decrypted]);
            } catch (\Exception $e) {
                // Already plaintext — leave as-is.
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wk_shopify_credentials_config', function (Blueprint $table) {
            $table->string('accessToken')->change();
            $table->dropColumn(['clientId', 'clientSecret', 'tokenExpiresAt']);
        });
    }
};
