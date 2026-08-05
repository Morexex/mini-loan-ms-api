<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->json('headers')->nullable();
            $table->json('payload');
            $table->string('processing_status', 32);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
            $table->index(['processing_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
