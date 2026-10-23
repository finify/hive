<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of money in minor units (pence, cents) with its ISO 4217 currency.
 *
 * Immutable. Arithmetic is integer-only, so totals always equal the sum of
 * their parts. Conversions round half away from zero, once, at the point of
 * conversion.
 */
final class Money implements JsonSerializable, Stringable
{
    private const SYMBOLS = ['GBP' => '£', 'EUR' => '€', 'USD' => '$'];

    public function __construct(
        public readonly int $minor,
        public readonly string $currency = 'GBP',
    ) {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Invalid currency code [{$currency}].");
        }
    }

    public static function of(int $minor, string $currency = 'GBP'): self
    {
        return new self($minor, strtoupper($currency));
    }

    public static function zero(string $currency = 'GBP'): self
    {
        return new self(0, strtoupper($currency));
    }

    /**
     * Build from a major-unit string such as "1,250.50". Never pass floats.
     */
    public static function parse(string $amount, string $currency = 'GBP'): self
    {
        $clean = str_replace([',', ' ', '£', '€', '$'], '', trim($amount));

        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $clean, $m)) {
            throw new InvalidArgumentException("Cannot read [{$amount}] as money.");
        }

        $minor = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return new self($m[1] === '-' ? -$minor : $minor, strtoupper($currency));
    }

    /**
     * @param  iterable<Money>  $amounts
     */
    public static function sum(iterable $amounts, string $currency = 'GBP'): self
    {
        $total = self::zero($currency);

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    /**
     * Multiply by an integer quantity, for example a number of days.
     */
    public function times(int $quantity): self
    {
        return new self($this->minor * $quantity, $this->currency);
    }

    /**
     * Multiply by a ratio expressed as numerator / denominator, rounding half
     * away from zero. Used for VAT, percentages and part days.
     */
    public function multiplyRatio(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Denominator cannot be zero.');
        }

        return new self(self::roundDiv($this->minor * $numerator, $denominator), $this->currency);
    }

    /**
     * Percentage of this amount, with the percentage given in basis points
     * (2000 = 20%).
     */
    public function percentage(int $basisPoints): self
    {
        return $this->multiplyRatio($basisPoints, 10_000);
    }

    /**
     * Convert to another currency with a rate given as a decimal string
     * (units of the target currency per one unit of this currency).
     */
    public function convert(string $rate, string $currency): self
    {
        if (! preg_match('/^\d+(?:\.(\d{1,8}))?$/', $rate, $m)) {
            throw new InvalidArgumentException("Invalid exchange rate [{$rate}].");
        }

        $decimals = strlen($m[1] ?? '');
        $scaled = (int) str_replace('.', '', $rate);

        return new self(self::roundDiv($this->minor * $scaled, 10 ** $decimals), strtoupper($currency));
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function abs(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    /**
     * "£12,400.00", or "£12,400" with $pence false. Negative amounts use a
     * minus sign before the symbol.
     */
    public function format(bool $pence = true): string
    {
        $symbol = self::SYMBOLS[$this->currency] ?? $this->currency.' ';
        $abs = abs($this->minor);
        $major = intdiv($abs, 100);
        $text = number_format($major);

        if ($pence) {
            $text .= '.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
        }

        return ($this->minor < 0 ? '−' : '').$symbol.$text;
    }

    /**
     * Short form for dense tables and charts: "£12.4k", "£1.25m".
     */
    public function formatShort(): string
    {
        $symbol = self::SYMBOLS[$this->currency] ?? $this->currency.' ';
        $abs = abs($this->minor);
        $sign = $this->minor < 0 ? '−' : '';

        if ($abs >= 100_000_000) {
            return $sign.$symbol.rtrim(rtrim(number_format($abs / 100_000_000, 2), '0'), '.').'m';
        }

        if ($abs >= 100_000) {
            return $sign.$symbol.rtrim(rtrim(number_format($abs / 100_000, 1), '0'), '.').'k';
        }

        return $this->format(false);
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount_minor' => $this->minor, 'currency' => $this->currency];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}.");
        }
    }

    private static function roundDiv(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        if (abs($remainder) * 2 >= abs($denominator)) {
            $quotient += ($numerator < 0) === ($denominator < 0) ? 1 : -1;
        }

        return $quotient;
    }
}
