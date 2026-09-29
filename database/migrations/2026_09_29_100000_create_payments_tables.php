<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts shown for bank transfers (donations, dues...).
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('branch')->nullable();
            $table->string('account_holder');
            $table->string('iban', 34);
            $table->string('currency', 3)->default('TRY');
            // Purposes the account is shown for (json list); empty = all.
            $table->json('purposes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Card payment providers; several may be active at once (e.g. iyzico
        // for donations, another provider for dues). Credentials are encrypted.
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 30);
            $table->string('name');
            $table->text('credentials')->nullable();
            $table->boolean('test_mode')->default(false);
            $table->json('purposes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Every collection, whatever it is for: a module owns the payable
        // (a donation, a dues invoice) and reacts to the PaymentSucceeded event.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Short code the payer writes in the transfer description.
            $table->string('reference', 20)->unique();
            $table->string('purpose', 40)->index();
            $table->nullableMorphs('payable');
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payer_name')->nullable();
            $table->string('payer_email')->nullable();
            $table->string('payer_phone', 30)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('TRY');
            $table->string('method', 20);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway_token')->nullable()->index();
            $table->string('gateway_payment_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->string('return_url', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_gateways');
        Schema::dropIfExists('bank_accounts');
    }
};
