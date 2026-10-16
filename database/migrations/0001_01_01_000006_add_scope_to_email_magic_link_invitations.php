<?php

declare(strict_types=1);

use EmailMagicLink\Support\TableLookup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scope on an invitation, such as the tenant that sent it.
 *
 * Issuing an invitation supersedes the earlier open invitations of its address and guard, and revoking
 * withdraws them. Where several tenants invite on their own, that let one tenant withdraw the invitation
 * another had just sent. With a scope, both stay inside it.
 *
 * No index of its own: every lookup that names a scope also names the email and the guard, and the index
 * on that pair narrows to the few rows of one address.
 *
 * The create-table migration carries the column as well, for a consumer who adopts the invitations table
 * after this migration was recorded without it. So this one adds the column only to a table that lacks it,
 * leaves a declined table alone, and leaves the column in place when it rolls back: it belongs to the
 * create-table migration and goes with its table.
 */
return new class extends Migration
{
    private const string TABLE = 'email_magic_link_invitations';

    public function up(): void
    {
        if (! TableLookup::exists(self::TABLE) || Schema::hasColumn(TableLookup::qualified(self::TABLE), 'scope')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('scope')->nullable()->after('guard');
        });
    }

    public function down(): void
    {
        // Deliberately empty, see above: the column is the create-table migration's.
    }
};
