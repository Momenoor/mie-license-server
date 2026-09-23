<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->string('product')->default('mie');
            $table->string('version')->comment('Semantic version, e.g. 1.2.0 — the app checks out git tag v{version}');
            $table->text('notes')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['product', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
