<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('cmd_uuid')->unique();            // matches cmd_id sent over MQTT, used to correlate ack
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();
            $table->string('action');                       // digital_write | pwm_write | analog_read | digital_read | get_status
            $table->unsignedTinyInteger('pin')->nullable();
            $table->string('value')->nullable();             // stored as string, cast on use
            $table->enum('status', ['pending', 'acked', 'failed', 'timeout'])->default('pending');
            $table->json('result')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
