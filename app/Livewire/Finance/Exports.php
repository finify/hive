<?php

namespace App\Livewire\Finance;

use App\Actions\Finance\ExportFinance;
use App\Actions\Finance\ManageBilling;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\ExchangeRate;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * CSV exports for the accounting system and payment runs, and the exchange
 * rates finance enters.
 */
#[Layout('layouts.app')]
#[Title('Exports')]
class Exports extends Component
{
    use UsesWorkspace;

    public string $from = '';

    public string $to = '';

    /** @var array{currency: string, rate_to_gbp: string, effective_on: string} */
    public array $rate = ['currency' => 'EUR', 'rate_to_gbp' => '', 'effective_on' => ''];

    public function mount(): void
    {
        $today = $this->workspace()->today();
        $this->from = $today->startOfMonth()->subMonths(3)->toDateString();
        $this->to = $today->endOfMonth()->toDateString();
        $this->rate['effective_on'] = $today->toDateString();
    }

    public function setRate(ManageBilling $billing): void
    {
        $this->resetValidation();

        try {
            $billing->setRate(auth()->user(), $this->rate);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['rate.'.$k => $m])->all());

            return;
        }

        $this->rate['rate_to_gbp'] = '';
        $this->toast('Rate saved. New costs in that currency use it from that date; costs already posted keep their rate.');
    }

    public function render(ExportFinance $export, ManageBilling $billing): View
    {
        $user = auth()->user();

        return view('livewire.finance.exports', [
            'types' => collect(ExportFinance::TYPES)->filter(fn ($label, $type) => $export->can($user, $type)),
            'canManage' => $billing->canManage($user),
            'rates' => ExchangeRate::query()->orderByDesc('effective_on')->orderByDesc('id')->limit(12)->get(),
            'currencies' => array_values(array_diff(config('hive.finance.currencies'), ['GBP'])),
            'valid' => $this->from !== '' && $this->to !== '' && $this->from <= $this->to,
        ]);
    }
}
