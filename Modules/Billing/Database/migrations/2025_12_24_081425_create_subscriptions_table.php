<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->string('sub_id')->nullable();
            $table->string('cus_id')->nullable();
            $table->string('status');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['type', 'type_id']);
            $table->index('sub_id');
            $table->index('status');
            $table->index('ends_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('subscriptions');
    }
};