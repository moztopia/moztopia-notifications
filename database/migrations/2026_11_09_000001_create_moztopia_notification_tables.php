<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('moztopia_notification_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('application', 120);
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('moztopia_notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('application', 120);
            $table->string('owner_type', 191);
            $table->uuid('owner_id');
            $table->boolean('enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->json('categories')->nullable();
            $table->timestamps();
            $table->unique(['application', 'owner_type', 'owner_id'], 'mn_preferences_owner_unique');
        });

        Schema::create('moztopia_notification_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('application', 120);
            $table->string('environment', 20);
            $table->string('owner_type', 191);
            $table->uuid('owner_id');
            $table->string('installation_id', 191);
            $table->string('platform', 10);
            $table->string('transport', 10);
            $table->string('transport_scope', 64);
            $table->text('token')->nullable();
            $table->string('token_fingerprint', 64)->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->string('name')->nullable();
            $table->boolean('push_enabled')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
            $table->unique(['application', 'environment', 'installation_id'], 'mn_device_installation_unique');
            $table->unique('token_fingerprint', 'mn_device_token_unique');
            $table->index(['application', 'owner_type', 'owner_id'], 'mn_device_owner_index');
        });

        Schema::create('moztopia_notification_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('application', 120);
            $table->string('owner_type', 191);
            $table->uuid('owner_id');
            $table->string('idempotency_hash', 64)->nullable();
            $table->string('category', 120);
            $table->text('payload')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('deduplicate_until');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['application', 'owner_type', 'owner_id', 'idempotency_hash'], 'mn_message_event_unique');
        });

        Schema::create('moztopia_notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained('moztopia_notification_messages');
            $table->uuid('device_id');
            $table->unsignedInteger('generation');
            $table->string('transport', 10);
            $table->string('transport_scope', 64);
            $table->string('environment', 20);
            $table->string('state', 30)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamp('available_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'device_id'], 'mn_delivery_device_unique');
            $table->index(['state', 'available_at'], 'mn_delivery_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moztopia_notification_deliveries');
        Schema::dropIfExists('moztopia_notification_messages');
        Schema::dropIfExists('moztopia_notification_devices');
        Schema::dropIfExists('moztopia_notification_preferences');
        Schema::dropIfExists('moztopia_notification_history');
    }
};
