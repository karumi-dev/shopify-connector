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

        // Encrypt existing plaintext accessToken values so they work with the encrypted cast
        DB::table('wk_shopify_credentials_config')->orderBy('id')->each(function ($credential) {
            DB::table('wk_shopify_credentials_config')
                ->where('id', $credential->id)
                ->update([
                    'accessToken' => Crypt::encryptString($credential->accessToken),
                ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Decrypt accessToken values back to plaintext before removing encrypted cast columns
        DB::table('wk_shopify_credentials_config')->orderBy('id')->each(function ($credential) {
            try {
                $decrypted = Crypt::decryptString($credential->accessToken);
                DB::table('wk_shopify_credentials_config')
                    ->where('id', $credential->id)
                    ->update(['accessToken' => $decrypted]);
            } catch (\Exception $e) {
                // Already plaintext, skip
            }
        });

        Schema::table('wk_shopify_credentials_config', function (Blueprint $table) {
            $table->string('accessToken')->change();
            $table->dropColumn(['clientId', 'clientSecret', 'tokenExpiresAt']);
        });
    }
};
