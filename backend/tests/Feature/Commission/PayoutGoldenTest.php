<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Models\Agent;
use App\Models\CommissionPayoutBatch;
use App\Models\CommissionPayoutPayee;
use App\Services\Commission\CommissionPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Commission\Concerns\BuildsPayoutScenario;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class PayoutGoldenTest extends TestCase
{
    use BuildsPayoutScenario;
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        $this->seedMgm();
    }

    public static function cases(): array
    {
        $rows = array_map(
            fn (string $line): array => str_getcsv($line, ',', '"', ''),
            file(dirname(__DIR__, 2).'/fixtures/payout-golden.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
        $header = array_shift($rows);
        $cases = [];
        foreach ($rows as $row) {
            $r = array_combine($header, $row);
            $cases[$r['case']][$r['case']] ??= [];
            $cases[$r['case']][$r['case']][] = $r;
        }

        return array_map(fn ($c) => array_values($c), $cases);
    }

    #[DataProvider('cases')]
    public function test_golden_case(array $expected): void
    {
        $first = $expected[0];
        $service = app(CommissionPayoutService::class);
        $roles = $first['structure'] === 'clawback'
            ? $this->runClawbackCase($first)
            : $this->runStructureCase($first);

        $batch = CommissionPayoutBatch::query()->latest('id')->firstOrFail();
        $batch = $service->approveBatch($batch, $this->admin->id);

        foreach ($expected as $row) {
            $agent = $roles[$row['role']];
            $payee = CommissionPayoutPayee::query()->where('batch_id', $batch->id)->where('agent_id', $agent->id)->firstOrFail();
            $label = "{$row['case']} {$row['role']}";
            $this->assertSame($row['gross'], number_format((float) $payee->gross_amount, 2, '.', ''), "{$label} gross");
            $this->assertSame($row['deduction'], number_format((float) $payee->deduction_amount, 2, '.', ''), "{$label} deduction");
            $this->assertSame($row['taxable'], number_format((float) $payee->taxable_amount, 2, '.', ''), "{$label} taxable");
            $this->assertSame($row['vat_amount'], number_format((float) $payee->vat_amount, 2, '.', ''), "{$label} vat");
            $this->assertSame($row['total'], number_format((float) $payee->total_amount, 2, '.', ''), "{$label} total");
            $this->assertSame($row['wht_rate'], number_format((float) $payee->wht_rate, 2, '.', ''), "{$label} wht rate");
            $this->assertSame($row['wht'], number_format((float) $payee->wht_amount, 2, '.', ''), "{$label} wht");
            $this->assertSame($row['net'], number_format((float) $payee->net_amount, 2, '.', ''), "{$label} net");
            $this->assertSame($row['wht_form'], $payee->wht_form, "{$label} form");
        }
    }

    private function runStructureCase(array $c): array
    {
        $kind = $c['kind'];
        $vat = $c['vat'];
        $roles = [];
        if ($c['structure'] === 'line2') {
            $roles['upline2'] = $this->payoutAgent(5, null, $kind, $vat);
            $roles['upline1'] = $this->payoutAgent(3, $roles['upline2'], $kind, $vat);
        } elseif ($c['structure'] === 'referral') {
            $roles['upline1'] = $this->payoutAgent(1, null, $kind, $vat);
        }
        $roles['seller'] = $this->payoutAgent(1, $roles['upline1'] ?? null, $kind, $vat);
        $this->sellAndPay($roles['seller']);
        app(CommissionPayoutService::class)->createBatch((int) $this->tenant->id, null, null, $this->admin->id);

        return $roles;
    }

    private function runClawbackCase(array $c): array
    {
        $service = app(CommissionPayoutService::class);
        $upline = $this->payoutAgent(1);
        $seller = $this->payoutAgent(1, $upline);
        $old = $this->sellAndPay($seller);
        $first = $service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $service->markPaid($service->approveBatch($first, $this->admin->id), now()->toDateString(), 'TRF-1', $this->admin->id);

        $old->update(['status' => 'cancelled', 'cancel_date' => now()->toDateString()]);
        $this->actingAsAdmin();
        $this->patchJson("/api/v1/policies/{$old->id}/cancellation-review", [
            'clawbackAmount' => 500, 'checks' => ['refund' => 'na', 'clawback' => 'done', 'carrierDeduct' => 'done'], 'markReviewed' => true,
        ])->assertOk()->assertJsonPath('data.uplineRatio', 0.25);

        $this->sellAndPay($seller);
        $service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);

        return ['seller' => $seller, 'upline1' => $upline];
    }
}
