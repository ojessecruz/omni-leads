<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off cleanup: a lone "SC" region made Google Places search South Carolina.
 * Drops "SC" from those ICPs and deletes the US leads it brought into their projects
 * (only where no ICP of the project targets the US).
 */
return new class extends Migration
{
    public function up(): void
    {
        $icps = DB::table('icps')->whereJsonContains('criteria->regions', 'SC')->get(['id', 'project_id', 'criteria']);

        foreach ($icps as $icp) {
            $criteria = json_decode($icp->criteria, true);
            $criteria['regions'] = array_values(array_filter($criteria['regions'], fn ($region) => $region !== 'SC'));

            DB::table('icps')->where('id', $icp->id)->update(['criteria' => json_encode($criteria)]);
        }

        $projects = $icps->pluck('project_id')->unique()->reject(
            fn ($projectId) => DB::table('icps')->where('project_id', $projectId)->whereJsonContains('criteria->countries', 'US')->exists()
        );

        DB::table('leads')->whereIn('project_id', $projects->all())->where('country', 'US')->delete();
    }

    public function down(): void
    {
        // Data cleanup: nothing to restore.
    }
};
