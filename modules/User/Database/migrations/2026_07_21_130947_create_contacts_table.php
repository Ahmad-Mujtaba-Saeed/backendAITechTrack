<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The table already exists on deployed environments, where it was
        // created outside of this migration.
        if (Schema::hasTable('contacts')) {
            return;
        }

        Schema::create('contacts', function (Blueprint $table) {
<<<<<<< HEAD
    $table->id();
    $table->string('name');
    $table->string('email');
    $table->text('message');
    $table->timestamps();
});
=======
            $table->id();
            $table->string('name');
            $table->string('email');
            // The public contact form does not collect a subject.
            $table->string('subject')->nullable();
            $table->text('message');
            $table->timestamps();
        });
>>>>>>> dab143845eba5dccad33c86346913586261cf97a
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
