<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('phone', 32);
            $table->string('status', 32);
            $table->unsignedInteger('attempt_number')->default(1);
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->string('merchant_request_id', 64)->nullable();
            $table->string('checkout_request_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['phone', 'status', 'created_at']);
            $table->index(['loan_id', 'status']);
            $table->index(['expires_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
