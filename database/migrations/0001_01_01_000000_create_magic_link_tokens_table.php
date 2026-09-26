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

            // This index earns its keep only ALONGSIDE one on the purge's other predicate,
            // and the purge-index migration supplies it. PostgreSQL reaches for an index on
            // a disjunction only when EVERY arm is covered, so one arm alone buys nothing.
            //
            // The measurement lives in 0001_01_01_000004_index_the_purge_predicates.php and
            // is deliberately NOT repeated here: two copies of a number drift apart.
            //
            // The short version, for a reader who does not want to open the other file: on a
            // table of fifteen-minute links almost every row qualifies, walking the primary
            // key is genuinely the right plan, and the pair costs what it costs. On a table
            // with a long TTL and a frequent purge the pair is worth more than an order of
            // magnitude.
            //
            // The claim path does not use this index: its UPDATE enters through the
            // token_hash index and evaluates the rest as a FILTER on the fetched row.
            // PostgreSQL 18, 200k rows, the statement the store runs:
            //
            //   Index Scan using magic_link_tokens_token_hash_index
            //     Index Cond: token_hash = '03e6c6…'
            //     Filter: consumed_at IS NULL AND expires_at > '…' AND uses_remaining > 0
            //             AND channel = 'link'
            //
            // The purge is what uses THIS one, and only in company.
            //
            // Its sibling on `consumed_at` is a different case, and calling both of them
            // purge tuning is how one of them gets removed. The code-claim lookup uses it
            // ALONE: measured against a user whose history grows, the plan is
            // `Index Scan using magic_link_tokens_consumed_at_index` from 100 rows upward
            // and the claim stays flat -- 0.37 ms at 100 rows, 0.49 ms at 50,000. Without
            // it the lookup falls back to the user_id index and filters every historical
            // row that user ever had.
            $table->timestamp('expires_at')->index();

            // NOT indexed here, and the asymmetry with the invitations table is deliberate.
            // There the two purge columns carry `->index()` inline, because that table is
            // optional and a consumer who adopts it late would otherwise never get them --
            // the purge-index migration is already recorded by then. This table is not
            // optional: every consumer runs it, so the purge-index migration always has a
            // table to reach and is the single place this index is created.
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_link_tokens');
    }
};
