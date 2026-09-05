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
            // is deliberately NOT repeated here. It used to be, and the two copies did what
            // two copies of a number do: the ratio was corrected in that file on 2026-09-05
            // -- it compared a COLD sequential scan against a warm bitmap scan and was wrong
            // by a factor of five -- while this one kept saying "about fifty times" and kept
            // the 35.8 ms reading the correction had just discredited. Both were edited the
            // same day, in the same session, by whoever is reading this.
            //
            // The short version, for a reader who does not want to open the other file: on a
            // table of fifteen-minute links almost every row qualifies, a sequential scan is
            // genuinely correct, and the pair costs what it costs. On a table with a long
            // TTL and a frequent purge the pair is worth about eleven times the runtime.
            //
            // TWO SENTENCES HERE WERE FALSE, and both read as reassurance. One said the
            // other arm did not exist -- it was added by the purge-index migration in the
            // same release. The other said this index "is used by the claim path", and the
            // plan says otherwise: the claim path enters through the token_hash index and
            // evaluates `expires_at > now()` as a FILTER on the fetched row, so it never
            // touches this one. Measured 2026-09-05, same server, 200k rows:
            //
            //   Index Scan using magic_link_tokens_token_hash_idx
            //     Index Cond: token_hash = '827ccb…'
            //     Filter: guard = 'web' AND channel = 'link' AND expires_at > now()
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
