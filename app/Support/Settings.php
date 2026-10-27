<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The settings admins can change in the app (section 5.15). Each is a key
 * in config/hive.php; a saved value overrides the file. Credentials are not
 * here: they stay in the environment.
 */
final class Settings
{
    /**
     * Group => config key => definition. Types: int, money (pence), percent
     * (stored as a ratio), bool, select.
     *
     * @var array<string, array<string, array{label: string, type: string, min?: int|float, max?: int|float, options?: array<string, string>, hint?: string}>>
     */
    public const FIELDS = [
        'Approvals and spending' => [
            'hive.finance.po_limits.producer' => ['label' => 'Producer purchase order limit', 'type' => 'money', 'min' => 0, 'max' => 100_000_000, 'hint' => 'Above this, the operations director approves.'],
            'hive.finance.po_limits.operations-director' => ['label' => 'Operations director purchase order limit', 'type' => 'money', 'min' => 0, 'max' => 1_000_000_000, 'hint' => 'Above this, the managing director approves.'],
            'hive.approvals.remind_after_days' => ['label' => 'Remind approvers after (days)', 'type' => 'int', 'min' => 1, 'max' => 14],
            'hive.finance.mileage_rate_minor' => ['label' => 'Mileage rate (pence a mile)', 'type' => 'int', 'min' => 0, 'max' => 100],
        ],
        'Budget health and invoices' => [
            'hive.health.watch_forecast_ratio' => ['label' => 'Watch when the forecast passes (% of budget)', 'type' => 'percent', 'min' => 50, 'max' => 100],
            'hive.health.target_margin_percent' => ['label' => 'Target margin (%)', 'type' => 'int', 'min' => 0, 'max' => 60],
            'hive.invoice_tolerance.percent' => ['label' => 'Invoice tolerance (%)', 'type' => 'int', 'min' => 0, 'max' => 50],
            'hive.invoice_tolerance.amount_minor' => ['label' => 'Invoice tolerance (amount)', 'type' => 'money', 'min' => 0, 'max' => 10_000_000],
        ],
        'Working time' => [
            'hive.working_time.min_rest_hours' => ['label' => 'Minimum rest between shifts (hours)', 'type' => 'int', 'min' => 8, 'max' => 16],
            'hive.working_time.long_shift_hours' => ['label' => 'Long shift (hours)', 'type' => 'int', 'min' => 8, 'max' => 16],
            'hive.working_time.break_after_hours' => ['label' => 'Break needed after (hours)', 'type' => 'int', 'min' => 4, 'max' => 8],
            'hive.working_time.break_minutes' => ['label' => 'Break length (minutes)', 'type' => 'int', 'min' => 15, 'max' => 60],
            'hive.working_time.weekly_average_hours' => ['label' => 'Weekly average limit (hours)', 'type' => 'int', 'min' => 30, 'max' => 60],
        ],
        'People' => [
            'hive.documents.expired_mandatory' => ['label' => 'Booking someone with an expired mandatory document', 'type' => 'select', 'options' => ['warn' => 'Warn only', 'block' => 'Block confirmation']],
            'hive.leave.default_allowance' => ['label' => 'Default annual leave (days)', 'type' => 'int', 'min' => 20, 'max' => 40],
            'hive.leave.carry_over_max' => ['label' => 'Most days carried into the next year', 'type' => 'int', 'min' => 0, 'max' => 20],
        ],
        'Ask Hive' => [
            'hive.ai.enabled' => ['label' => 'Ask Hive is on', 'type' => 'bool'],
            'hive.ai.hourly_limit' => ['label' => 'Questions per person per hour', 'type' => 'int', 'min' => 1, 'max' => 200],
            'hive.ai.effort' => ['label' => 'How hard Claude thinks', 'type' => 'select', 'options' => ['low' => 'Low (fastest)', 'medium' => 'Medium', 'high' => 'High (slowest)']],
        ],
        'Data retention' => [
            'hive.retention.timesheets_years' => ['label' => 'Timesheets (years)', 'type' => 'int', 'min' => 6, 'max' => 10, 'hint' => 'Financial records: at least six years.'],
            'hive.retention.expenses_years' => ['label' => 'Expenses (years)', 'type' => 'int', 'min' => 6, 'max' => 10],
            'hive.retention.leavers_years' => ['label' => 'Leavers’ records (years after leaving)', 'type' => 'int', 'min' => 1, 'max' => 10],
            'hive.retention.expired_documents_years' => ['label' => 'Expired documents (years)', 'type' => 'int', 'min' => 1, 'max' => 10],
            'hive.retention.ai_requests_months' => ['label' => 'Ask Hive log (months)', 'type' => 'int', 'min' => 1, 'max' => 36],
        ],
    ];

    private const CACHE = 'hive.settings';

    /**
     * Applies saved values over the config files. Safe before migrations run.
     */
    public static function apply(): void
    {
        $saved = rescue(fn () => Cache::rememberForever(self::CACHE, fn () => Setting::query()->pluck('value', 'key')->all()), [], false);

        foreach ((array) $saved as $key => $value) {
            if (self::field((string) $key) !== null) {
                config([$key => $value]);
            }
        }
    }

    /**
     * @return array{label: string, type: string, min?: int|float, max?: int|float, options?: array<string, string>, hint?: string}|null
     */
    public static function field(string $key): ?array
    {
        foreach (self::FIELDS as $fields) {
            if (isset($fields[$key])) {
                return $fields[$key];
            }
        }

        return null;
    }

    /**
     * Current values as the form shows them: pounds for money, whole
     * percentages for ratios.
     *
     * @return array<string, string|bool>
     */
    public static function formValues(): array
    {
        $values = [];

        foreach (self::FIELDS as $fields) {
            foreach ($fields as $key => $f) {
                $v = config($key);
                $values[self::formKey($key)] = match ($f['type']) {
                    'money' => sprintf('%d.%02d', intdiv((int) $v, 100), (int) $v % 100),
                    'percent' => (string) round((float) $v * 100),
                    'bool' => (bool) $v,
                    default => (string) $v,
                };
            }
        }

        return $values;
    }

    /**
     * Livewire can't bind dotted keys, so the form uses underscores.
     */
    public static function formKey(string $key): string
    {
        return str_replace(['.', '-'], ['__', '_'], $key);
    }

    /**
     * @param  array<string, mixed>  $input  form key => value
     * @return int number of settings changed
     */
    public static function save(User $user, array $input): int
    {
        Gate::forUser($user)->authorize('settings.manage');

        $rules = $messages = [];

        foreach (self::FIELDS as $fields) {
            foreach ($fields as $key => $f) {
                $k = self::formKey($key);
                $rules[$k] = match ($f['type']) {
                    'bool' => ['boolean'],
                    'select' => ['required', Rule::in(array_keys($f['options'] ?? []))],
                    'money' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
                    default => ['required', 'integer', 'min:'.($f['min'] ?? 0), 'max:'.($f['max'] ?? PHP_INT_MAX)],
                };
                $messages[$k.'.min'] = $f['label'].' is at least :min.';
                $messages[$k.'.max'] = $f['label'].' is at most :max.';
                $messages[$k.'.regex'] = 'Enter an amount in pounds, for example 5000.';
            }
        }

        $data = Validator::make($input, $rules, $messages)->validate();
        $changed = [];

        foreach (self::FIELDS as $fields) {
            foreach ($fields as $key => $f) {
                $raw = $data[self::formKey($key)] ?? null;
                $value = match ($f['type']) {
                    'money' => Money::parse((string) $raw)->minor,
                    'percent' => round((int) $raw / 100, 2),
                    'bool' => (bool) $raw,
                    'int' => (int) $raw,
                    default => (string) $raw,
                };

                if ($f['type'] === 'money' && (isset($f['max']) && $value > $f['max'])) {
                    throw ValidationException::withMessages([self::formKey($key) => $f['label'].' is too large.']);
                }

                if ($value != config($key)) {
                    $changed[$key] = ['old' => config($key), 'new' => $value];
                    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $user->person_id]);
                    config([$key => $value]);
                }
            }
        }

        Cache::forget(self::CACHE);

        if ($changed !== []) {
            activity('settings')->causedBy($user)
                ->withProperties(['ref' => 'settings', 'old' => array_map(fn ($c) => $c['old'], $changed), 'attributes' => array_map(fn ($c) => $c['new'], $changed)])
                ->event('settings-changed')->log('Changed '.count($changed).' '.(count($changed) === 1 ? 'setting' : 'settings'));
        }

        return count($changed);
    }
}
