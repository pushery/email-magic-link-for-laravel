<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A separate table rather than a third channel on magic_link_tokens, and the
        // reason is the addressee: an invitation is issued to an EMAIL, for someone who
        // may have no account at all, while every row over there is bound to a user_id
        // that is indexed and NOT NULL. Widening that column would mean altering a
        // populated, indexed table in every application that already adopted the package.
        //
        // The separation also removes a filter someone could forget. The login store
        // narrows on `channel = 'link'`; here there is no shared table to narrow, so an
        // invitation token cannot reach the sign-in path even in principle.
        Schema::create('email_magic_link_invitations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('email');
            $table->string('guard');

            // Keyed hash of the secret; the raw token exists only in the message that
            // carries it. UNIQUE rather than merely indexed, unlike magic_link_tokens: a
            // collision here should be a write error, never a silent wrong match.
            $table->string('token_hash', 64)->unique();

            // Whatever the inviting side decided in advance -- roles, a team, a plan.
            // The package stores and returns it; it never interprets it.
            $table->json('context')->nullable();

            $table->string('invited_by')->nullable();

            $table->timestamp('expires_at')->index();
            // Indexed here as well as in the purge-index migration, and that is not a
            // duplicate. A consumer who declines the invitations tables and adopts them LATER
            // runs this migration after the other one is already recorded, so the other one
            // never runs again -- the two indexes the purge needs would never exist for them.
            // The later migration skips whatever it finds, so a fresh install gets them once.
            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();

            $table->timestamps();

            // Supersession and revocation both look up by exactly this pair. Two
            // 255-character utf8mb4 columns index to 2040 bytes, inside InnoDB's
            // 3072-byte limit, so this needs no prefix length.
            $table->index(['email', 'guard']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_magic_link_invitations');
    }
};
