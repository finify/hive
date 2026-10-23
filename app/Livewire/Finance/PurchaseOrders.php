<?php

namespace App\Livewire\Finance;

use App\Enums\PurchaseOrderStatus;
use App\Livewire\Concerns\PaginatesCollections;
use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Purchase orders')]
class PurchaseOrders extends Component
{
    use PaginatesCollections, UsesWorkspace;

    #[Url(except: '')]
    public string $status = '';

    public function render(): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();

        $pos = $workspace->purchaseOrders()
            ->filter(fn ($po) => ($event = $workspace->event($po['event_id'])) && $viewer->canSeeBudget($event))
            ->when($this->status !== '', fn ($c) => $c->where('status', $this->status))
            ->sortByDesc('code');

        return view('livewire.finance.purchase-orders', [
            'pos' => $this->paginateCollection($pos),
            'statuses' => PurchaseOrderStatus::cases(),
        ]);
    }
}
