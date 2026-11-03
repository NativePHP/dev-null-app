<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Your best Nata Nu//ers run, one row per user, with everything its score
 * commit needs: the run's id, when it ended and the device key's signature
 * (see App\Devnull\NataNullers\HighScore). It's kept here rather than in the
 * cache because Android empties the file cache whenever a new bundle unpacks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nata_nullers_best_runs', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->unique();
            $table->uuid('run_id');
            $table->integer('score');
            $table->unsignedBigInteger('ended_at');
            $table->text('signature');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nata_nullers_best_runs');
    }
};
