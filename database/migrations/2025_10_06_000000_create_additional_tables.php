<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('phone')->nullable();
            $table->string('telegram_id')->nullable();
            $table->string('state')->nullable();
            $table->string('lang')->nullable();
            $table->integer('lang_priority')->nullable();
            $table->timestamp('lang_check_date')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        // LogEntry table
        Schema::create('log_entries', function (Blueprint $table) {
            $table->id();
            $table->string('level');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });

        // RequestLog table
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('method');
            $table->text('url');
            $table->string('ip_address');
            $table->string('real_ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->integer('status_code');
            $table->decimal('execution_time', 8, 2);
            $table->integer('request_size')->default(0);
            $table->integer('response_size')->default(0);
            $table->json('request_data')->nullable();
            $table->longText('response_data')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['created_at', 'status_code']);
        });

        // TelegramUser table
        Schema::create('telegram_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('uid')->nullable();
            $table->string('state')->nullable();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->string('lang')->nullable();
            $table->string('tid')->unique();
            $table->json('json')->nullable();
            $table->string('activity_status')->default('active');
            $table->timestamps();

            $table->foreign('uid')->references('id')->on('users')->onDelete('set null');
            $table->index(['state', 'activity_status']);
        });

        // TelegramLog table
        Schema::create('telegram_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tid');
            $table->text('text')->nullable();
            $table->enum('direction', ['sent', 'received']);
            $table->text('error')->nullable();
            $table->text('response')->nullable();
            $table->json('message_id')->nullable();
            $table->string('type')->nullable();
            $table->json('comm')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['tid', 'created_at']);
        });

        // Lang table
        Schema::create('langs', function (Blueprint $table) {
            $table->id();
            $table->string('lang')->unique();
        });

        // LanguagePriority table
        Schema::create('language_priorities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('uid');
            $table->string('lang');
            $table->integer('priority');
            $table->timestamps();

            $table->foreign('uid')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['uid', 'lang']);
        });

        // TranslationCache table
        Schema::create('translation_cache', function (Blueprint $table) {
            $table->id();
            $table->text('source_text');
            $table->text('translated_text');
            $table->string('source_language');
            $table->string('target_language');
            $table->integer('requests_count')->default(0);
            $table->timestamps();

            $table->index(['source_language', 'target_language']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translation_cache');
        Schema::dropIfExists('language_priorities');
        Schema::dropIfExists('langs');
        Schema::dropIfExists('telegram_logs');
        Schema::dropIfExists('telegram_users');
        Schema::dropIfExists('request_logs');
        Schema::dropIfExists('log_entries');
    }
};
