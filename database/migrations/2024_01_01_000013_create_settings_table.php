<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            // null = configuración global del SaaS
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('group')->default('general');
            $table->string('key');
            $table->text('value')->nullable();
            $table->enum('type', ['string', 'bool', 'int', 'json'])->default('string');
            $table->timestamps();

            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
