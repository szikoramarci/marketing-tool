<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('quiz_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('config_version_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_events');
    }
};
