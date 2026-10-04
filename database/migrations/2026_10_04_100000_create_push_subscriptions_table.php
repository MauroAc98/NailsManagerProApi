<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Web Push subscriptions (laravel-notification-channels/webpush schema).
 * subscribable_* is a morph to users.id: one salon account, many devices.
 * `endpoint` is unique, which makes subscribe an idempotent upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('webpush.database_connection'))->create(config('webpush.table_name'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
            $table->string('endpoint', PushSubscription::ENDPOINT_MAX_LENGTH)->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(config('webpush.database_connection'))->dropIfExists(config('webpush.table_name'));
    }
};
