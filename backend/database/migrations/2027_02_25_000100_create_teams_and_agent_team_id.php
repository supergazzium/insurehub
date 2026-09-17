<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * สายงาน (agent team hierarchy) as a first-class table.
 *
 * Until now the team structure lived only in the dirty `agents.team_lv1` /
 * `agents.team_lv2` code columns (e.g. "Team14-1" reports up to "Team6-2"),
 * with a derived, concatenated `agents.team` column that is unusable
 * ("Team17Team6"). This migration promotes teams to a real table with a
 * self-referential parent link, then backfills it from the existing codes
 * and links each agent via `agents.team_id`.
 *
 * The raw `team_lv1` / `team_lv2` columns are LEFT in place (read-only legacy)
 * so nothing that still reads them breaks; `team_id` is the new source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Canonical team code, stripped of the "-1"/"-2" role suffix
            // (e.g. "Team14"). Unique per tenant.
            $table->string('code', 32);
            $table->string('name', 120)->nullable();
            // The team this team reports UP to (from team_lv2). Null = top of a line.
            $table->foreignId('parent_team_id')->nullable()->constrained('teams')->nullOnDelete();
            // The agent who leads this team (optional; set later in the UI).
            $table->foreignId('leader_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'teams_tenant_code_uq');
            $table->index(['tenant_id', 'parent_team_id'], 'teams_tenant_parent_idx');
        });

        Schema::table('agents', function (Blueprint $table): void {
            $table->foreignId('team_id')->nullable()->after('team_no')
                ->constrained('teams')->nullOnDelete();
            $table->index(['tenant_id', 'team_id'], 'agents_tenant_team_idx');
        });

        $this->backfill();
    }

    /**
     * Build the teams table from the distinct team codes on agents, wire up
     * the parent-team tree from team_lv2, then point each agent at its team.
     */
    private function backfill(): void
    {
        // Strip the "-1"/"-2" role suffix to get the canonical code.
        $canon = static fn (?string $raw): ?string =>
            $raw === null || $raw === '' ? null : preg_replace('/-\d+$/', '', $raw);

        // 1) Collect every (tenant, canonical-code) pair from team_lv1 + team_lv2.
        $rows = DB::table('agents')
            ->select('tenant_id', 'team_lv1', 'team_lv2')
            ->whereNotNull('team_lv1')
            ->get();

        $codesByTenant = [];       // tenant_id => set of codes
        $parentByTenantChild = []; // tenant_id => [childCode => parentCode]
        foreach ($rows as $r) {
            $child = $canon($r->team_lv1);
            if ($child === null) {
                continue;
            }
            $codesByTenant[$r->tenant_id][$child] = true;
            $parent = $canon($r->team_lv2);
            if ($parent !== null) {
                $codesByTenant[$r->tenant_id][$parent] = true;
                $parentByTenantChild[$r->tenant_id][$child] = $parent;
            }
        }

        // 2) Insert team rows (no parent yet), remember their ids.
        $idByTenantCode = [];
        foreach ($codesByTenant as $tenantId => $codes) {
            foreach (array_keys($codes) as $code) {
                $id = DB::table('teams')->insertGetId([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name' => $code,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $idByTenantCode[$tenantId][$code] = $id;
            }
        }

        // 3) Wire the parent-team links.
        foreach ($parentByTenantChild as $tenantId => $map) {
            foreach ($map as $child => $parent) {
                $childId = $idByTenantCode[$tenantId][$child] ?? null;
                $parentId = $idByTenantCode[$tenantId][$parent] ?? null;
                if ($childId && $parentId && $childId !== $parentId) {
                    DB::table('teams')->where('id', $childId)->update(['parent_team_id' => $parentId]);
                }
            }
        }

        // 4) Point each agent at its team (from team_lv1).
        foreach ($idByTenantCode as $tenantId => $byCode) {
            foreach ($byCode as $code => $teamId) {
                DB::table('agents')
                    ->where('tenant_id', $tenantId)
                    ->where(function ($q) use ($code): void {
                        $q->where('team_lv1', $code)
                          ->orWhere('team_lv1', $code.'-1');
                    })
                    ->update(['team_id' => $teamId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropIndex('agents_tenant_team_idx');
            $table->dropConstrainedForeignId('team_id');
        });
        Schema::dropIfExists('teams');
    }
};
