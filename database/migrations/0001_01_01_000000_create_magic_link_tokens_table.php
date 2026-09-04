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

            // The index earns its keep only ALONGSIDE indexes on the purge's other
            // predicates, and there are none today. Measured on PostgreSQL 18, 200k rows,
            // chunk 1000, EXPLAIN ANALYZE on `expires_at <= now() OR consumed_at IS NOT
            // NULL`:
            //
            //   qualifying   expires_at + consumed_at     expires_at alone / none
            //   98%          Seq Scan, 0.13 ms            Seq Scan, 0.29 ms
            //   1%           BitmapOr on both, 0.62 ms    Seq Scan, 35.8 ms
            //
            // PostgreSQL reaches for an index on a disjunction only when EVERY arm is
            // covered, so this one alone is never used by the purge -- it is used by the
            // claim path, which filters `expires_at > now` behind a token_hash lookup.
            // Adding the missing arms is worth about fifty times the purge's runtime on a
            // table with a long TTL and a frequent purge, and nothing at all on a table of
            // fifteen-minute links, where almost every row qualifies and a sequential scan
            // is correct.
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
