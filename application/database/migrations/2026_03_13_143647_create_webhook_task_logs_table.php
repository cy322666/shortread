<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_task_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('task_id');

            $table->text('message');

            $table->timestamps();

            $table->index('task_id');

            $table->foreign('task_id')
                ->references('id')
                ->on('webhook_tasks')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_task_logs');
    }
};
