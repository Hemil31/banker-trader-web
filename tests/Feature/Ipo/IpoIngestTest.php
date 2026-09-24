<?php

namespace Tests\Feature\Ipo;

use App\Contracts\Ipo\JsonFileIpoProvider;
use App\Models\Ipo;
use App\Services\IpoIngestService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpoIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_provider_reads_both_json_shapes(): void
    {
        $item = $this->item('nse');

        $wrapped = $this->provider(json_encode(['ipos' => [$item]]))->fetchAll();
        $plain = $this->provider(json_encode([$item]))->fetchAll();

        $this->assertCount(1, $wrapped);
        $this->assertCount(1, $plain);
        $this->assertSame('nse', $wrapped[0]['slug']);
        $this->assertSame('nse', $plain[0]['slug']);
        $this->assertSame(3100, $wrapped[0]['price_max']);
    }

    public function test_provider_throws_on_missing_file(): void
    {
        $this->expectExceptionMessage('IPO source file not found');

        (new JsonFileIpoProvider('/tmp/does-not-exist-ipos.json'))->fetchAll();
    }

    public function test_provider_throws_on_invalid_json(): void
    {
        $this->expectExceptionMessage('not valid JSON');

        $this->provider('{ not json')->fetchAll();
    }

    public function test_ingest_creates_ipo_with_children(): void
    {
        app(IpoIngestService::class)->ingest([$this->item('nse')]);

        $ipo = Ipo::where('slug', 'nse')->firstOrFail();

        $this->assertSame('live', $ipo->status);
        $this->assertSame('mainboard', $ipo->board);
        $this->assertSame(3, $ipo->subscriptionSnapshots()->count());
        $this->assertSame(1, $ipo->gmpHistory()->count());
        $this->assertNotNull($ipo->synced_at);
        $this->assertSame(2.7, (float) $ipo->current_subscription);
        $this->assertSame(73.0, (float) $ipo->current_gmp);
        $this->assertSame(73.0, (float) $ipo->expected_premium);
    }

    public function test_ingest_is_idempotent_and_upserts_by_slug(): void
    {
        $service = app(IpoIngestService::class);

        $service->ingest([$this->item('nse')]);
        $result = $service->ingest([$this->item('nse')]);

        $this->assertSame(['ipos_created' => 0, 'ipos_updated' => 1, 'snapshots_written' => 3, 'gmp_written' => 1], $result);
        $this->assertSame(1, Ipo::where('slug', 'nse')->count());
        $this->assertSame(3, Ipo::find(Ipo::where('slug', 'nse')->value('id'))->subscriptionSnapshots()->count());
        $this->assertSame(1, Ipo::find(Ipo::where('slug', 'nse')->value('id'))->gmpHistory()->count());
    }

    public function test_ingest_maps_provider_status_words(): void
    {
        app(IpoIngestService::class)->ingest([
            $this->item('pre-apply', ['status' => 'Pre-Apply']),
            $this->item('awaited', ['slug' => 'x', 'status' => 'Allotment Awaited', 'board' => 'sme']),
            $this->item('drhp', ['slug' => 'y', 'status' => 'DRHP Approved']),
        ]);

        $this->assertSame('upcoming', Ipo::where('slug', 'pre-apply')->value('status'));
        $this->assertSame('allotment_awaited', Ipo::where('slug', 'x')->value('status'));
        $this->assertSame('drhp_approved', Ipo::where('slug', 'y')->value('status'));
    }

    public function test_ingest_keeps_latest_snapshot_as_current_value(): void
    {
        $item = $this->item('nse');
        $item['subscription'] = [
            ['as_on' => '2026-09-17 17:00:00', 'total' => 1.1],
            ['as_on' => '2026-09-18 17:00:00', 'total' => 2.4],
        ];
        $item['gmp'] = [
            ['recorded_at' => '2026-09-18 18:00:00', 'gmp' => 45],
        ];

        app(IpoIngestService::class)->ingest([$item]);

        $ipo = Ipo::where('slug', 'nse')->firstOrFail();

        $this->assertSame(2.4, (float) $ipo->current_subscription);
        $this->assertSame(45.0, (float) $ipo->current_gmp);
    }

    public function test_ingest_skips_items_without_slug(): void
    {
        $result = app(IpoIngestService::class)->ingest([
            ['name' => 'Nameless IPO', 'status' => 'upcoming'],
        ]);

        $this->assertSame(0, $result['ipos_created']);
        $this->assertSame(0, Ipo::count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(string $slug, array $overrides = []): array
    {
        return array_merge([
            'slug' => $slug,
            'name' => 'NSE India Ltd',
            'symbol' => 'NSE',
            'board' => 'mainboard',
            'status' => 'live',
            'price_min' => 3100,
            'price_max' => 3100,
            'lot_size' => 1,
            'issue_size_cr' => 20000.0,
            'open_date' => '2026-09-17',
            'close_date' => '2026-09-19',
            'allotment_date' => '2026-09-22',
            'listing_date' => '2026-09-24',
            'listing_at' => 'NSE, BSE',
            'face_value' => '₹10 Per Equity Share',
            'registrar' => 'KFin Technologies Ltd.',
            'expected_premium' => 73,
            'current_subscription' => 1.67,
            'current_gmp' => 73,
            'subscription' => [
                ['as_on' => '2026-09-17 17:00:00', 'qib' => 0.42, 'nii' => 0.78, 'retail' => 1.55, 'total' => 1.67],
                ['as_on' => '2026-09-18 17:00:00', 'qib' => 0.9, 'nii' => 1.1, 'retail' => 2.0, 'total' => 2.4],
                ['as_on' => '2026-09-19 17:00:00', 'qib' => 1.2, 'nii' => 0.4, 'retail' => 2.1, 'total' => 2.7],
            ],
            'gmp' => [
                ['recorded_at' => '2026-09-19 18:00:00', 'gmp' => 73, 'premium_pct' => 2.4],
            ],
        ], $overrides);
    }

    private function provider(string $json): JsonFileIpoProvider
    {
        $path = tempnam(sys_get_temp_dir(), 'ipo-test');
        file_put_contents($path, $json);

        return new JsonFileIpoProvider($path);
    }
}
