<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jcc_transactions', function (Blueprint $table) {
            $table->string('client_id')->nullable()->after('currency_code');
            $table->string('binding_id')->nullable()->after('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('jcc_transactions', function (Blueprint $table) {
            $table->dropColumn(['client_id', 'binding_id']);
        });
    }
};
