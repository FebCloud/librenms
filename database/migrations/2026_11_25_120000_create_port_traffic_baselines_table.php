<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each port's usual traffic for the current hour (median of the same hour
     * over the past 7 days, bytes per second), refreshed hourly by
     * maintenance:port-traffic-baselines and read by the port baseline macros.
     * valid_from / valid_until are Unix times so the database time zone does
     * not matter.
     */
    public function up(): void
    {
        Schema::create('port_traffic_baselines', function (Blueprint $table): void {
            $table->unsignedInteger('port_id')->primary();
            $table->double('in_rate')->nullable();
            $table->double('out_rate')->nullable();
            $table->unsignedTinyInteger('days')->default(0);
            $table->unsignedInteger('valid_from');
            $table->unsignedInteger('valid_until')->index();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('port_traffic_baselines');
    }
};
