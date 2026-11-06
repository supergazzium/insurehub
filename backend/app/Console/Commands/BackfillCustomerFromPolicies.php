<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The legacy Client export carries no agent link and no registration date, so
 * the customer import leaves assigned_agent_id / created_by_agent_id and
 * registered_at empty. Both are derivable from the customer's own policies:
 *   - assigned_agent_id  = writing_agent of the customer's LATEST policy
 *   - created_by_agent_id = writing_agent of the customer's EARLIEST policy
 *   - registered_at      = the earliest policy's date (app_date, else create_date)
 * Customers with no policy are left untouched (they stay unassigned).
 * Idempotent; run after the import + policy steps.
 */
class BackfillCustomerFromPolicies extends Command
{
    protected $signature = 'customers:backfill-from-policies {--dry-run} {--tenant=1}';

    protected $description = 'Derive customer agent link + registration date from their policies.';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        // One row per customer: earliest + latest policy, with each one's agent.
        // Order key = COALESCE(app_date, create_date); ties broken by policy id.
        $rows = DB::select(
            <<<'SQL'
            SELECT p.customer_id,
                   SUBSTRING_INDEX(MIN(CONCAT(LPAD(DATE_FORMAT(COALESCE(p.app_date,p.create_date),'%Y%m%d'),8,'0'),'|',LPAD(p.id,10,'0'),'|',IFNULL(p.writing_agent_id,0))), '|', -1) AS first_agent,
                   SUBSTRING_INDEX(MAX(CONCAT(LPAD(DATE_FORMAT(COALESCE(p.app_date,p.create_date),'%Y%m%d'),8,'0'),'|',LPAD(p.id,10,'0'),'|',IFNULL(p.writing_agent_id,0))), '|', -1) AS last_agent,
                   MIN(COALESCE(p.app_date,p.create_date)) AS first_date
            FROM policies p
            WHERE p.tenant_id = ? AND p.customer_id IS NOT NULL AND p.deleted_at IS NULL
            GROUP BY p.customer_id
            SQL,
            [$tenantId]
        );

        $withAgent = 0;
        $withDate = 0;
        foreach ($rows as $r) {
            $first = (int) $r->first_agent ?: null;
            $last = (int) $r->last_agent ?: null;
            $update = [];
            if ($last) {
                $update['assigned_agent_id'] = $last;
            }
            if ($first) {
                $update['created_by_agent_id'] = $first;
            }
            if ($r->first_date) {
                $update['registered_at'] = $r->first_date;
                $withDate++;
            }
            if ($update === []) {
                continue;
            }
            if ($last || $first) {
                $withAgent++;
            }
            if (! $dry) {
                DB::table('customers')->where('id', $r->customer_id)->where('tenant_id', $tenantId)->update($update);
            }
        }

        $this->table(['metric', 'count'], [
            ['customers with a policy', count($rows)],
            ['got agent link', $withAgent],
            ['got registered_at', $withDate],
        ]);
        $this->info(($dry ? '[DRY-RUN] would update ' : 'updated ').count($rows).' customers from their policies');

        return self::SUCCESS;
    }
}
