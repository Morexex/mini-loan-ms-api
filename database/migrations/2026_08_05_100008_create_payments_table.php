<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_intent_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('phone', 32);
            $table->string('status', 32);
            $table->string('evidence_source', 32);
            $table->timestamp('evidenced_at');
            $table->string('idempotency_key', 191)->unique();
            $table->string('receipt_number', 64)->nullable();
            $table->foreignId('webhook_log_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'status']);
            $table->index('loan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
