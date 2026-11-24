<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->index();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality')->default('standard');
            $table->json('reference_images')->nullable();
            $table->string('status')->default('pending_submit')->index();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('estimated_price', 10, 4)->default(0);
            $table->decimal('actual_price', 10, 4)->nullable();
            $table->text('image_url')->nullable();
            $table->string('stored_image_path')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('last_webhook_event_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
