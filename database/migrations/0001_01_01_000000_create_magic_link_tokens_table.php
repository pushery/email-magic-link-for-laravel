<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magic_link_tokens', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // String so the table works with integer, UUID, or ULID user keys.
            $table->string('user_id')->index();
            $table->string('guard');

            // Keyed hash of the secret; the raw token or code is never stored.
            $table->string('token_hash', 64)->index();

            $table->string('channel', 8);
            $table->unsignedInteger('attempts')->default(0);

            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_link_tokens');
    }
};
