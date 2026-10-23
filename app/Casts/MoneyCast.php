<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts an `{prefix}_minor` bigint column and a currency column to Money.
 *
 * Usage: 'client_fee' => MoneyCast::class.':client_fee_minor,currency'
 *
 * @implements CastsAttributes<Money|null, Money|null>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly string $amountColumn,
        private readonly string $currencyColumn = 'currency',
    ) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $minor = $attributes[$this->amountColumn] ?? null;

        if ($minor === null) {
            return null;
        }

        return Money::of((int) $minor, (string) ($attributes[$this->currencyColumn] ?? config('hive.base_currency')));
    }

    /**
     * @return array<string, int|string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$this->amountColumn => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("The [{$key}] attribute must be a Money instance.");
        }

        return [
            $this->amountColumn => $value->minor,
            $this->currencyColumn => $value->currency,
        ];
    }
}
