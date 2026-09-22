<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // The plaintext key is only ever shown once, right after
            // creation — never stored. Verifying a submitted key means
            // re-hashing it and comparing against `key_hash`, the same
            // convention Laravel's own API tokens use.
            $table->string('key_hash')->unique();
            $table->string('key_last_four', 4)->comment('For identifying a license in the admin list without re-hashing.');

            $table->string('product')->default('mie');
            $table->string('status')->default('active')->comment('active, suspended, revoked');
            $table->string('plan')->nullable();
            $table->unsignedInteger('max_activations')->default(1);

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable()->comment('Null means perpetual.');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
