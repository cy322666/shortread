<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('webhook_tasks', 'lead_id')) {
                $table->unsignedBigInteger('lead_id')->nullable()->index();
            }

            if (!Schema::hasColumn('webhook_tasks', 'contact_id')) {
                $table->unsignedBigInteger('contact_id')->nullable()->index();
            }

            if (!Schema::hasColumn('webhook_tasks', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable()->index();
            }

            if (!Schema::hasColumn('webhook_tasks', 'scenario')) {
                $table->string('scenario')->nullable()->index();
            }
        });

        if (Schema::hasColumn('webhook_tasks', 'products')) {
            Schema::table('webhook_tasks', function (Blueprint $table) {
                $table->dropColumn('products');
            });
        }

        Schema::table('webhook_tasks', function (Blueprint $table) {
            $table->text('products')->nullable();
        });

        if (Schema::hasColumn('webhook_tasks', 'processed_at')) {
            Schema::table('webhook_tasks', function (Blueprint $table) {
                $table->dropColumn('processed_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('webhook_tasks', 'processed_at')) {
            Schema::table('webhook_tasks', function (Blueprint $table) {
                $table->dropColumn('processed_at');
            });
        }

        Schema::table('webhook_tasks', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable();
        });

        if (Schema::hasColumn('webhook_tasks', 'products')) {
            Schema::table('webhook_tasks', function (Blueprint $table) {
                $table->dropColumn('products');
            });
        }

        Schema::table('webhook_tasks', function (Blueprint $table) {
            $table->json('products')->nullable();
        });

        Schema::table('webhook_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('webhook_tasks', 'scenario')) {
                $table->dropColumn('scenario');
            }

            if (Schema::hasColumn('webhook_tasks', 'company_id')) {
                $table->dropColumn('company_id');
            }

            if (Schema::hasColumn('webhook_tasks', 'contact_id')) {
                $table->dropColumn('contact_id');
            }

            if (Schema::hasColumn('webhook_tasks', 'lead_id')) {
                $table->dropColumn('lead_id');
            }
        });
    }
};
