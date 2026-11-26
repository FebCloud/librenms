<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use LibreNMS\Alerting\QueryBuilderParser;

return new class extends Migration
{
    /**
     * FebNMS: rebuild the saved query of builder rules so it also selects the
     * macros the rule uses, and new faults record the values that triggered
     * them. Rules with a hand-written query are left alone.
     */
    public function up(): void
    {
        foreach (DB::table('alert_rules')->get(['id', 'builder', 'extra', 'query']) as $rule) {
            $extra = json_decode((string) $rule->extra, true);
            if (filter_var($extra['options']['override_query'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            if (! str_contains((string) $rule->builder, 'macros.')) {
                continue;
            }
            try {
                $query = QueryBuilderParser::fromJson($rule->builder)->toSql();
            } catch (Throwable) {
                continue;
            }
            if ($query && $query !== $rule->query) {
                DB::table('alert_rules')->where('id', $rule->id)->update(['query' => $query]);
            }
        }
    }

    /**
     * The extra selects are harmless without the fork; nothing to undo.
     */
    public function down(): void
    {
    }
};
