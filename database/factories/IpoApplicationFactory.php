<?php

namespace Database\Factories;

use App\Models\DematAccount;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\PanCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpoApplication>
 */
class IpoApplicationFactory extends Factory
{
    protected $model = IpoApplication::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ipo_id' => Ipo::factory(),
            'pan_card_id' => fn (array $attrs) => PanCard::factory()->create(['user_id' => $attrs['user_id']]),
            'demat_account_id' => fn (array $attrs) => DematAccount::factory()->create(['user_id' => $attrs['user_id']]),
            'lots' => 1,
            'shares' => 8,
            'amount' => 14280,
            'status' => 'draft',
        ];
    }
}
