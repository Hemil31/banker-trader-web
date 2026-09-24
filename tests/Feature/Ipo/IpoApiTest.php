<?php

namespace Tests\Feature\Ipo;

use App\Contracts\Ipo\AllotmentProvider;
use App\Models\DematAccount;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\IpoSubscriptionSnapshot;
use App\Models\PanCard;
use App\Models\TradingAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class IpoApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_ipo_list_requires_authentication(): void
    {
        $this->getJson('/api/ipos')->assertUnauthorized();
    }

    public function test_ipo_list_returns_paginated_ipos(): void
    {
        Ipo::factory()->count(3)->create();

        Passport::actingAs($this->user);

        $this->getJson('/api/ipos')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['slug', 'name', 'status', 'board']], 'pagination' => ['total']]);
    }

    public function test_ipo_list_filters_by_status_and_board(): void
    {
        Ipo::factory()->create(['status' => 'live']);
        Ipo::factory()->create(['status' => 'upcoming']);
        Ipo::factory()->create(['status' => 'upcoming', 'board' => 'sme']);

        Passport::actingAs($this->user);

        $this->getJson('/api/ipos?status=upcoming')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/ipos?status=upcoming&board=sme')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_ipo_show_returns_detail_with_history(): void
    {
        $ipo = Ipo::factory()->live()->create();
        IpoSubscriptionSnapshot::factory()->for($ipo)->create(['as_on' => '2026-09-18 17:00:00', 'total' => 2.4]);

        Passport::actingAs($this->user);

        $this->getJson("/api/ipos/{$ipo->slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $ipo->slug)
            ->assertJsonPath('data.status', 'live')
            ->assertJsonStructure(['data' => ['subscription_snapshots' => [['as_on', 'total']]]]);
    }

    public function test_ipo_show_missing_slug_returns_404(): void
    {
        Passport::actingAs($this->user);

        $this->getJson('/api/ipos/nonexistent')->assertNotFound();
    }

    public function test_pan_card_store_normalizes_and_marks_primary(): void
    {
        Passport::actingAs($this->user);

        $this->postJson('/api/pan-cards', [
            'pan_number' => 'abcde1234f',
            'holder_name' => 'Test Holder',
            'is_primary' => true,
        ])->assertCreated()
            ->assertJsonPath('data.pan_number', 'ABCDE1234F')
            ->assertJsonPath('data.is_primary', true);
    }

    public function test_pan_card_store_keeps_primary_exclusive(): void
    {
        Passport::actingAs($this->user);

        $first = PanCard::factory()->for($this->user)->create(['is_primary' => true]);

        $this->postJson('/api/pan-cards', [
            'pan_number' => 'GHIJK1234L',
            'is_primary' => true,
        ])->assertCreated();

        $this->assertSame(0, (int) PanCard::where('id', $first->id)->value('is_primary'));
        $this->assertSame(1, PanCard::where('user_id', $this->user->id)->where('is_primary', true)->count());
    }

    public function test_pan_card_store_rejects_duplicate_pan_globally(): void
    {
        Passport::actingAs($this->user);
        PanCard::factory()->for($this->user)->create(['pan_number' => 'ABCDE1234F']);

        $this->postJson('/api/pan-cards', ['pan_number' => 'ABCDE1234F'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pan_number');
    }

    public function test_pan_card_crud_is_scoped_to_owner(): void
    {
        $other = User::factory()->create();
        $foreign = PanCard::factory()->for($other)->create();

        Passport::actingAs($this->user);

        $this->patchJson("/api/pan-cards/{$foreign->id}", ['holder_name' => 'Nope'])->assertNotFound();
        $this->deleteJson("/api/pan-cards/{$foreign->id}")->assertNotFound();
    }

    public function test_pan_card_verify_marks_verified(): void
    {
        $pan = PanCard::factory()->for($this->user)->create();

        Passport::actingAs($this->user);

        $this->postJson("/api/pan-cards/{$pan->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.verification_details.source', 'manual');
    }

    public function test_demat_account_store_and_verify(): void
    {
        Passport::actingAs($this->user);

        $this->postJson('/api/demat-accounts', [
            'provider' => 'nsdl',
            'dp_id' => 'IN300123',
            'client_id' => '12345678',
            'account_name' => 'Test BO',
            'upi_id' => 'test@oksbi',
            'is_primary' => true,
        ])->assertCreated()
            ->assertJsonPath('data.client_id', '12345678')
            ->assertJsonPath('data.upi_id', 'test@oksbi')
            ->assertJsonPath('data.is_primary', true);

        $demat = DematAccount::where('user_id', $this->user->id)->firstOrFail();

        $this->postJson("/api/demat-accounts/{$demat->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');
    }

    public function test_demat_account_store_enforces_provider_client_dedup(): void
    {
        Passport::actingAs($this->user);
        DematAccount::factory()->for($this->user)->create(['provider' => 'nsdl', 'client_id' => '12345678']);

        $this->postJson('/api/demat-accounts', [
            'provider' => 'nsdl',
            'client_id' => '12345678',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');

        $this->postJson('/api/demat-accounts', [
            'provider' => 'cdsl',
            'client_id' => '12345678',
        ])->assertCreated();
    }

    public function test_bulk_apply_computes_shares_and_amount(): void
    {
        Passport::actingAs($this->user);

        $pan = PanCard::factory()->for($this->user)->create();
        $demat = DematAccount::factory()->for($this->user)->create();
        $trading = TradingAccount::factory()->for($this->user)->create();
        $ipoA = Ipo::factory()->create(['price_max' => 100, 'price_min' => 95, 'lot_size' => 10]);
        $ipoB = Ipo::factory()->create(['price_max' => 200, 'price_min' => 190, 'lot_size' => 5]);

        $response = $this->postJson('/api/ipo-applications', [
            'pan_card_id' => $pan->id,
            'demat_account_id' => $demat->id,
            'trading_account_id' => $trading->id,
            'applications' => [
                ['ipo_id' => $ipoA->id, 'lots' => 1],
                ['ipo_id' => $ipoB->id, 'lots' => 2],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.applications.0.status', 'queued');

        $batch = $response->json('data.batch_id');
        $this->assertIsString($batch);

        $appA = IpoApplication::where('user_id', $this->user->id)->where('ipo_id', $ipoA->id)->firstOrFail();
        $appB = IpoApplication::where('user_id', $this->user->id)->where('ipo_id', $ipoB->id)->firstOrFail();

        $this->assertSame($batch, $appA->batch_id);
        $this->assertSame($batch, $appB->batch_id);
        $this->assertSame(10, $appA->shares);
        $this->assertSame(1000.0, (float) $appA->amount);
        $this->assertSame(10, $appB->shares);
        $this->assertSame(2000.0, (float) $appB->amount);
    }

    public function test_bulk_apply_dedupes_by_user_ipo_pan(): void
    {
        Passport::actingAs($this->user);

        $pan = PanCard::factory()->for($this->user)->create([
            'pan_number' => 'ABCDE1234F',
            'holder_name' => 'Test',
        ]);
        $demat = DematAccount::factory()->for($this->user)->create();
        $ipo = Ipo::factory()->create(['price_max' => 100, 'lot_size' => 10]);
        $payload = [
            'pan_card_id' => $pan->id,
            'demat_account_id' => $demat->id,
            'applications' => [['ipo_id' => $ipo->id, 'lots' => 1]],
        ];

        $this->postJson('/api/ipo-applications', $payload)->assertCreated();
        $this->postJson('/api/ipo-applications', $payload)->assertCreated();

        $this->assertSame(1, IpoApplication::where('user_id', $this->user->id)->where('ipo_id', $ipo->id)->where('pan_card_id', $pan->id)->count());
    }

    public function test_bulk_apply_rejects_foreign_pan(): void
    {
        $other = User::factory()->create();
        $foreignPan = PanCard::factory()->for($other)->create();
        $demat = DematAccount::factory()->for($this->user)->create();
        $ipo = Ipo::factory()->create();

        Passport::actingAs($this->user);

        $this->postJson('/api/ipo-applications', [
            'pan_card_id' => $foreignPan->id,
            'demat_account_id' => $demat->id,
            'applications' => [['ipo_id' => $ipo->id, 'lots' => 1]],
        ])->assertNotFound();
    }

    public function test_check_allotment_records_pending_when_no_provider_result(): void
    {
        $application = $this->submittedApplication();
        $application->update(['application_number' => 'APPL-001']);

        Passport::actingAs($this->user);

        $this->postJson("/api/ipo-applications/{$application->id}/check-allotment")
            ->assertOk()
            ->assertJsonPath('data.result', 'pending')
            ->assertJsonPath('data.attempts', 1);

        $this->assertSame('submitted', $application->fresh()->status);
    }

    public function test_check_allotment_updates_status_when_allotted(): void
    {
        $application = $this->submittedApplication();

        $provider = new class implements AllotmentProvider
        {
            public function fetch(string $panNumber, string $applicationNumber, ?string $registrar = null): array
            {
                return ['result' => 'allotted', 'shares_allotted' => 16, 'source' => 'mock'];
            }
        };
        $this->app->instance(AllotmentProvider::class, $provider);

        Passport::actingAs($this->user);

        $this->postJson("/api/ipo-applications/{$application->id}/check-allotment")
            ->assertOk()
            ->assertJsonPath('data.result', 'allotted')
            ->assertJsonPath('data.shares_allotted', 16);

        $this->assertSame('allotted', $application->fresh()->status);
    }

    public function test_check_allotment_rejects_draft(): void
    {
        $application = $this->submittedApplication();
        $application->update(['status' => 'draft']);
        $application->ipo->update(['allotment_date' => now()->subDay()->toDateString()]);

        Passport::actingAs($this->user);

        $this->postJson("/api/ipo-applications/{$application->id}/check-allotment")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_check_allotment_rejects_before_allotment_date(): void
    {
        $application = $this->submittedApplication();
        $application->ipo->update(['allotment_date' => now()->addDays(5)->toDateString()]);

        Passport::actingAs($this->user);

        $this->postJson("/api/ipo-applications/{$application->id}/check-allotment")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_check_allotment_is_scoped_to_owner(): void
    {
        $other = User::factory()->create();
        Passport::actingAs($other);

        $application = $this->submittedApplication();

        $this->postJson("/api/ipo-applications/{$application->id}/check-allotment")->assertNotFound();
    }

    public function test_applications_list_requires_authentication(): void
    {
        $this->getJson('/api/ipo-applications')->assertUnauthorized();
    }

    public function test_applications_list_returns_own_applications_only(): void
    {
        IpoApplication::factory()->for($this->user)->create();

        Passport::actingAs($this->user);

        $this->getJson('/api/ipo-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    private function submittedApplication(): IpoApplication
    {
        $ipo = Ipo::factory()->create(['allotment_date' => now()->subDay()->toDateString(), 'status' => 'allotment_awaited']);
        $pan = PanCard::factory()->for($this->user)->create();

        return IpoApplication::factory()->create([
            'user_id' => $this->user->id,
            'ipo_id' => $ipo->id,
            'pan_card_id' => $pan->id,
            'status' => 'submitted',
        ]);
    }
}
