<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_pin_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('pin');
            $table->string('type');       // digital_output | digital_input | pwm | analog_input
            $table->string('label')->nullable(); // human name from firmware, e.g. "relay1"
            $table->string('value')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['device_id', 'pin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_pin_states');
    }
};
