<?php

namespace EduLazaro\Larameter\Tests\Feature;

use EduLazaro\Larameter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * The upgrade path, which is the one nobody runs until it is running on live data.
 *
 * The rest of the suite builds its tables from create_larameter_tables, which already has
 * the expiry columns, so it only ever exercises the case where there is nothing to do.
 * This one starts from the schema a 1.0 installation actually has.
 */
class UpgradeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require __DIR__ . '/../../database/auto/2026_09_01_000000_add_credit_expiry_to_larameter_deposits.php';
    }

    /**
     * The columns a 1.0 database does not have.
     *
     * @return void
     */
    private function dropThem(): void
    {
        Schema::table('larameter_deposits', function ($table) {
            $table->dropIndex(['account_id', 'expires_at']);
            $table->dropColumn(['expires_at', 'consumed']);
        });
    }

    public function test_it_adds_the_columns_to_a_database_that_does_not_have_them(): void
    {
        $this->dropThem();

        $this->assertFalse(Schema::hasColumn('larameter_deposits', 'expires_at'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('larameter_deposits', 'expires_at'));
        $this->assertTrue(Schema::hasColumn('larameter_deposits', 'consumed'));
    }

    /**
     * And is a no-op against a database that already has them.
     *
     * Which is every fresh install, because create_larameter_tables puts them there.
     * Without the guard, `migrate` on a new project would fail on a duplicate column.
     */
    public function test_it_does_nothing_when_the_columns_are_already_there(): void
    {
        $this->assertTrue(Schema::hasColumn('larameter_deposits', 'expires_at'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('larameter_deposits', 'expires_at'));
    }

    /**
     * Twice in a row changes nothing either, which is what makes it safe to leave in the
     * package rather than published where somebody decides when it runs.
     */
    public function test_running_it_twice_is_the_same_as_running_it_once(): void
    {
        $this->dropThem();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('larameter_deposits', 'consumed'));
    }

    /**
     * The rows already in the table come out as history, not as lots.
     *
     * A null `consumed` is what says so, and ALTER TABLE leaves it null on its own. If it
     * defaulted to zero instead, every old deposit would become a live lot on upgrade and
     * every balance in the application would double overnight.
     */
    public function test_the_rows_already_there_come_out_as_history(): void
    {
        $this->dropThem();

        \DB::table('larameter_accounts')->insert([
            'id' => 1, 'meterable_type' => 'org', 'meterable_id' => 1,
            'purchased_credits' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('larameter_deposits')->insert([
            'account_id' => 1, 'credits' => 500, 'reason' => 'purchase',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertNull(\DB::table('larameter_deposits')->first()->consumed);
        $this->assertSame(500, (int) \DB::table('larameter_accounts')->first()->purchased_credits);
    }
}
