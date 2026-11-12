<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('moztopia_notification_preferences', function (Blueprint $table): void {
            $table->json('push_categories')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('moztopia_notification_preferences', function (Blueprint $table): void {
            $table->dropColumn('push_categories');
        });
    }
};
