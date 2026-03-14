<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_tasks', function (Blueprint $table) {
            $table->id();

            // JSON webhook
            $table->json('task_content');

            // статус обработки
            $table->boolean('task_complete')->default(false);

            // время обработки
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('task_complete');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_tasks');
    }
};
