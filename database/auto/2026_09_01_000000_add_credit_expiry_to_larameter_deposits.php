<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a deposit carry an expiry date, and become a lot when it does.
 *
 * Run by the package, not published into your application. Upgrading is `composer update`
 * and `php artisan migrate`, with nothing to remember and nothing to copy, because a step
 * somebody has to remember is a step somebody will skip, and skipping this one takes the
 * balance down on the first request that reads it.
 *
 * Guarded, so it is safe in both directions: a fresh install already has the columns from
 * create_larameter_tables and this finds nothing to do.
 *
 * Nothing is migrated and nothing is recalculated, which is the point. One rule decides
 * what a row is: with a date it is a lot, without one it is history and its credits are
 * in purchased_credits. Every row written before this has a null date, so every one of
 * them is history, the column still holds exactly what it held, and an installation that
 * never sets a date behaves precisely as it did.
 *
 * The alternative was to reconstruct which past deposit each past spend came out of, and
 * that is not recorded anywhere because until now there was nothing to record it against.
 * It would have meant guessing, once, over live data, on the day of the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('larameter_deposits', 'expires_at')) {
            return;
        }

        Schema::table('larameter_deposits', function (Blueprint $table) {
            // Null is never, and it is the default, so a caller with no interest in
            // expiry never has to say so. It is also what makes this migration free.
            $table->timestamp('expires_at')->nullable()->after('credits');

            // How much of THIS lot has been spent. `credits` never moves once written;
            // this is the only mutable part, the way an invoice carries what has been
            // paid against it without rewriting what it was for.
            //
            // NULL means the row is not a lot at all. That is how a deposit written
            // before lots existed keeps its meaning: its credits are in the account's
            // purchased_credits and always were, so counting it here too would double
            // the balance. ALTER TABLE leaves it null on its own, which is exactly why
            // the upgrade touches no data.
            $table->unsignedBigInteger('consumed')->nullable()->after('expires_at');

            // The balance query and the spending order share this one.
            $table->index(['account_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('larameter_deposits', 'expires_at')) {
            return;
        }

        Schema::table('larameter_deposits', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'expires_at']);
            $table->dropColumn(['expires_at', 'consumed']);
        });
    }
};
