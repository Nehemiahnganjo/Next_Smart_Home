<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_uid')->unique();      // e.g. esp32-a1b2c3 (MQTT client id / topic key)
            $table->string('name');                       // human label, "Gate Relay"
            $table->string('secret_hash');                 // hashed device auth secret (for MQTT ACL provisioning)
            $table->boolean('is_online')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->json('pin_config')->nullable();        // declared pins/capabilities, e.g. [{"pin":26,"type":"digital_output","label":"relay1"}]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
