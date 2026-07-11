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
            // Remaining redemptions for a link. Default 1 preserves single-use;
            // a link issued with a higher max_uses is decremented atomically on
            // each claim and only marked consumed once it reaches zero. Codes are
            // always issued with 1.
            $table->unsignedInteger('uses_remaining')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('magic_link_tokens', function (Blueprint $table): void {
            $table->dropColumn('uses_remaining');
        });
    }
};
