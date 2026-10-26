<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox: stars, commits, replies and Remotes messages you've made that
 * devnull hasn't answered for yet, kept on the phone so they outlive the
 * screen they were made on, and the app being closed (see App\Devnull\Outbox).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_writes', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('kind');
            $table->string('target')->nullable();
            $table->uuid('client_id')->unique();
            $table->json('payload');
            $table->string('status')->default('queued');
            $table->string('task_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_writes');
    }
};
