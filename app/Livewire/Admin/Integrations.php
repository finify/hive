<?php

namespace App\Livewire\Admin;

use App\Actions\Integrations\ManageWebhooks;
use App\Actions\Integrations\SyncAccounting;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\SupplierInvoice;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Accounting\AccountingGateway;
use App\Services\Identity\SingleSignOn;
use App\Services\Webhooks\Webhooks;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What Hive is connected to: Microsoft sign-in, Xero, calendar feeds and
 * outbound webhooks. Credentials come from the environment and are never
 * shown; webhooks are managed here.
 */
#[Layout('layouts.app')]
#[Title('Integrations')]
class Integrations extends Component
{
    use UsesWorkspace;

    /** @var array{url: string, description: string, events: list<string>} */
    public array $hookForm = ['url' => '', 'description' => '', 'events' => ['event.stage_changed', 'booking.confirmed']];

    /** The new endpoint's signing secret, shown once. */
    public ?string $newSecret = null;

    public function addWebhook(ManageWebhooks $manage): void
    {
        $this->resetValidation();

        try {
            $this->newSecret = $manage->create(auth()->user(), $this->hookForm)['secret'];
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['hookForm.'.explode('.', $k)[0] => $m])->all());

            return;
        }

        $this->hookForm = ['url' => '', 'description' => '', 'events' => array_keys(Webhooks::EVENTS)];
        $this->toast('Webhook added. Copy its signing secret now.');
    }

    public function toggleWebhook(int $id, ManageWebhooks $manage): void
    {
        $endpoint = $manage->toggle(auth()->user(), $id);
        $this->toast($endpoint->is_active ? 'Webhook resumed.' : 'Webhook paused.');
    }

    public function deleteWebhook(int $id, ManageWebhooks $manage): void
    {
        $manage->delete(auth()->user(), $id);
        $this->toast('Webhook removed.');
    }

    public function testWebhook(int $id, ManageWebhooks $manage): void
    {
        $manage->test(auth()->user(), $id);
        $this->toast('Test sent. Its delivery appears below.');
    }

    public function syncAccounting(SyncAccounting $sync): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $r = $sync->handle();
        $this->toast("Sent {$r['pushed']} bills, recorded {$r['paid']} paid".($r['failed'] ? ", {$r['failed']} failed (see the log)" : '').'.', $r['failed'] ? 'error' : 'success');
    }

    public function render(SingleSignOn $sso, AccountingGateway $accounting): View
    {
        return view('livewire.admin.integrations', [
            'sso' => $sso,
            'accounting' => $accounting,
            'pushed' => SupplierInvoice::query()->whereNotNull('accounting_id')->count(),
            'waiting' => SupplierInvoice::query()->where('status', 'approved')->whereNull('accounting_id')->count(),
            'lastSync' => SupplierInvoice::query()->max('accounting_synced_at'),
            'endpoints' => WebhookEndpoint::query()->withCount('deliveries')->orderBy('id')->get(),
            'deliveries' => WebhookDelivery::query()->with('endpoint')->latest('id')->limit(15)->get(),
            'events' => Webhooks::EVENTS,
            'canManage' => auth()->user()->can('settings.manage'),
        ]);
    }
}
