<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\CarrierContactGroup;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seed carrier contact groups from the real Insurehub recipient list
 * (Email Template/Recipient list.xlsx). The list maps display names
 * (Allianz, Viriyah, MTI, ...) to the broker desks; we resolve those to
 * the 3-letter carrier codes already in the DB.
 *
 * One group per carrier (department='new_business', is_default=true) so the
 * composer routes every line of business to the real desk by default.
 */
class CarrierContactGroupSeeder extends Seeder
{
    /** Sentinel the frontend recognises and renders an "auto-seeded — verify" badge for. */
    private const AUTO_SEED_NOTE = 'auto-seeded: verify recipients against carrier broker channel';

    /** Recipient label → DB carrier code (the recipient list uses display names). */
    private const RECIPIENT_TO_CARRIER_CODE = [
        'Allianz' => 'ALI',
        'IND' => 'IND',
        'AIO' => 'AIO',
        'BKI' => 'BKI',
        'TIP' => 'TIP',
        'AXA' => 'AXA',
        'ERGO' => 'ERG',
        'TPB' => 'TPB',
        'CHUBB' => 'CHU',
        'TOK' => 'TOK',
        'KPI' => 'KPI',
        'Viriyah' => 'VIB',
        'MTI' => 'MTP',
        // MSIG has no carrier row in the DB — skip if not resolvable.
        'MSIG' => 'MSI',
    ];

    /**
     * Recipient data extracted from `Email Template/Recipient list.xlsx`.
     * `to` is the list of email addresses for that carrier's broker desk.
     */
    private const RECIPIENTS = [
        ['code' => 'Allianz', 'to' => ['agency.a@allianz.co.th', 'thanon.k@allianz.co.th', 'contact@insurehub.co.th']],
        ['code' => 'IND', 'to' => ['xb_utain.j@tgh.co.th', 'xb_teerapong.p@tgh.co.th', 'xb_anchalee.p@tgh.co.th', 'contact@insurehub.co.th']],
        ['code' => 'AIO', 'to' => ['absp1@aioibkkins.co.th', 'contact@insurehub.co.th']],
        ['code' => 'BKI', 'to' => ['suebsawad.s@bangkokinsurance.com', 'Wanwimol@bangkokinsurance.com', 'contact@insurehub.co.th']],
        ['code' => 'TIP', 'to' => ['titirats@dhipaya.co.th', 'taksaons@dhipaya.co.th', 'contact@insurehub.co.th']],
        ['code' => 'AXA', 'to' => ['marine&tradecredit@axa.co.th', 'distribution2_salesteam3@axa.co.th', 'contact@insurehub.co.th']],
        ['code' => 'MSIG', 'to' => ['th_msignt@th.msig-asia.com', 'contact@insurehub.co.th']],
        ['code' => 'ERGO', 'to' => ['contact_center@ergo.co.th']],
        ['code' => 'TPB', 'to' => ['tpb_upcnpt@thaipaiboon.com', 'contact@insurehub.co.th']],
        ['code' => 'CHUBB', 'to' => ['atch.shalasonti@chubb.com', 'thunrada.jitsuraphol@chubb.com', 'Chubb.BKKC@chubb.com', 'contact@insurehub.co.th']],
        ['code' => 'TOK', 'to' => ['ratchapluek@tokiomarinesafety.co.th', 'contact@insurehub.co.th']],
        ['code' => 'KPI', 'to' => ['mkt.broker@kpi.co.th', 'korawan.a@kpi.co.th', 'contact@insurehub.co.th']],
        ['code' => 'Viriyah', 'to' => ['pr2_nonmotor@viriyah.co.th', 'contact@insurehub.co.th']],
        ['code' => 'MTI', 'to' => ['tippawan.n@muangthaiinsurance.com', 'Auto-insurance-broker-business-NON-TQM@muangthaiinsurance.com', 'contact@insurehub.co.th']],
    ];

    public function run(): void
    {
        $tenantId = Tenant::where('slug', 'insurehub')->value('id');
        if ($tenantId === null) {
            throw new \RuntimeException('TenantSeeder must run before CarrierContactGroupSeeder.');
        }

        $carriersByCode = Carrier::where('tenant_id', $tenantId)
            ->pluck('id', 'code')
            ->all();

        $inserted = 0;
        $skipped = [];
        foreach (self::RECIPIENTS as $row) {
            $label = $row['code'];
            $carrierCode = self::RECIPIENT_TO_CARRIER_CODE[$label] ?? null;
            if ($carrierCode === null || ! isset($carriersByCode[$carrierCode])) {
                $skipped[] = $label;
                continue;
            }
            CarrierContactGroup::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'carrier_id' => $carriersByCode[$carrierCode],
                    'department' => 'new_business',
                    'name' => $label.' — New Business',
                ],
                [
                    'emails' => $row['to'],
                    'insurance_types' => [], // empty = matches any line
                    'is_default' => true,
                    'notes' => null,
                    'active' => true,
                ],
            );
            $inserted++;
        }
        $this->command?->info('  carrier_contact_groups (from recipient list): inserted/updated '.$inserted);
        if ($skipped !== []) {
            $this->command?->warn('  skipped (no carrier match): '.implode(', ', $skipped));
        }

        // ── Second pass: placeholder group for any carrier without one yet. ──
        // The frontend renders an "auto-seeded — verify" badge for these so
        // the broker knows to plug in real emails before sending.
        $carriersWithGroups = CarrierContactGroup::query()
            ->where('tenant_id', $tenantId)
            ->pluck('carrier_id')
            ->all();
        $missing = Carrier::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('id', $carriersWithGroups)
            ->get(['id', 'code', 'name']);

        $autoSeeded = 0;
        foreach ($missing as $carrier) {
            $domain = strtolower($carrier->code).'.co.th';
            CarrierContactGroup::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'carrier_id' => $carrier->id,
                    'department' => 'new_business',
                    'name' => $carrier->code.' — New Business',
                ],
                [
                    'emails' => ['newbiz@'.$domain],
                    'insurance_types' => [],
                    'is_default' => true,
                    'notes' => self::AUTO_SEED_NOTE,
                    'active' => true,
                ],
            );
            $autoSeeded++;
        }
        $this->command?->info('  carrier_contact_groups (placeholder for unconfigured carriers): '.$autoSeeded);
        $this->command?->info('  total carrier_contact_groups: '.CarrierContactGroup::where('tenant_id', $tenantId)->count());
    }
}
