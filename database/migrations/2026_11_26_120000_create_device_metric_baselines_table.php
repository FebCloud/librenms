<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each device's usual CPU and memory usage for the current hour (median of
     * the same hour over the past 7 days, percent), refreshed hourly by
     * maintenance:device-metric-baselines and read by the device baseline
     * macros. valid_from / valid_until are Unix times so the database time
     * zone does not matter.
     */
    public function up(): void
    {
        Schema::create('device_metric_baselines', function (Blueprint $table): void {
            $table->unsignedInteger('device_id')->primary();
            $table->double('cpu_perc')->nullable();
            $table->double('mem_perc')->nullable();
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
        Schema::dropIfExists('device_metric_baselines');
    }
};
