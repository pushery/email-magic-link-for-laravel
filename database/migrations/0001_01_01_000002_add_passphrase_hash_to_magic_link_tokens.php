<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magic_link_tokens', function (Blueprint $table): void {
            // Optional per-link passphrase gate. Null (the default) means the link
            // has no passphrase; when set it holds a bcrypt hash of a shared secret
            // that must be entered on the confirmation page before the link is
            // consumed. It is a lightweight gate, NOT the Fortify two-factor path.
            $table->string('passphrase_hash')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('magic_link_tokens', function (Blueprint $table): void {
            $table->dropColumn('passphrase_hash');
        });
    }
};
