<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The live "contacts" table drifted from its create migration: its "id" is
     * a plain int with no primary key or auto-increment (so every insert
     * failed), it has no "subject" and no "updated_at", while the create
     * migration declared a required "subject". Bring both shapes together so
     * the contact form can insert with Eloquent timestamps.
     */
    public function up(): void
    {
        if (!Schema::hasTable('contacts')) {
            return;
        }

        $this->repairPrimaryKey();

        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'subject')) {
                $table->string('subject')->nullable()->after('email');
            } else {
                $table->string('subject')->nullable()->change();
            }

            if (!Schema::hasColumn('contacts', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }

            if (!Schema::hasColumn('contacts', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
        });
    }

    /**
     * Give "id" a primary key and auto-increment when it is missing them.
     */
    private function repairPrimaryKey(): void
    {
        $hasPrimaryKey = collect(DB::select('SHOW KEYS FROM `contacts`'))
            ->contains(fn ($key) => $key->Key_name === 'PRIMARY');

        $isAutoIncrement = collect(DB::select('SHOW COLUMNS FROM `contacts`'))
            ->contains(fn ($column) => $column->Field === 'id'
                && str_contains(strtolower($column->Extra ?? ''), 'auto_increment'));

        if ($hasPrimaryKey && $isAutoIncrement) {
            return;
        }

        // Existing rows may share an id (or all be 0), so renumber before the
        // primary key is added.
        $id = 0;
        foreach (DB::table('contacts')->orderBy('created_at')->get() as $row) {
            DB::table('contacts')->where('id', $row->id)->limit(1)->update(['id' => ++$id]);
        }

        if (!$hasPrimaryKey) {
            DB::statement('ALTER TABLE `contacts` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
        } else {
            DB::statement('ALTER TABLE `contacts` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('contacts')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
};
