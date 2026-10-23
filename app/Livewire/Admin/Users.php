<?php

namespace App\Livewire\Admin;

use App\Actions\Identity\InviteUser;
use App\Actions\Identity\SetUserActive;
use App\Actions\Identity\UpdateUserRoles;
use App\Enums\Role;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Users and roles')]
class Users extends Component
{
    use UsesWorkspace, WithPagination;

    public string $inviteEmail = '';

    public string $inviteRole = 'employee';

    public ?int $editing = null;

    /** @var list<string> */
    public array $roles = [];

    public ?int $deactivating = null;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function invite(InviteUser $invite): void
    {
        $this->resetValidation();
        try {
            $invitation = $invite->handle(auth()->user(), $this->inviteEmail, $this->inviteRole);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->resetValidation();
        $this->reset('inviteEmail');
        $this->toast("Invitation sent to {$invitation->email}. It expires in 7 days.");
    }

    public function editRoles(int $userId): void
    {
        $user = User::query()->with('roles')->findOrFail($userId);
        $this->editing = $user->id;
        $this->roles = $user->getRoleNames()->values()->all();
        $this->resetValidation();
        $this->dispatch('modal', id: 'roles-modal', show: true);
    }

    public function saveRoles(UpdateUserRoles $update): void
    {
        $this->resetValidation();
        $target = User::query()->findOrFail((int) $this->editing);

        try {
            $update->handle(auth()->user(), $target, array_values($this->roles));
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->dispatch('modal', id: 'roles-modal', show: false);
        $this->toast("Roles saved for {$target->name}.");
    }

    public function confirmDeactivate(int $userId): void
    {
        $this->deactivating = $userId;
        $this->dispatch('modal', id: 'deactivate-modal', show: true);
    }

    public function deactivate(SetUserActive $set): void
    {
        $target = User::query()->findOrFail((int) $this->deactivating);
        $set->handle(auth()->user(), $target, false);
        $this->dispatch('modal', id: 'deactivate-modal', show: false);
        $this->toast("{$target->name} is deactivated and signed out everywhere.");
    }

    public function reactivate(int $userId, SetUserActive $set): void
    {
        $target = User::query()->findOrFail($userId);
        $set->handle(auth()->user(), $target, true);
        $this->toast("{$target->name} can sign in again.");
    }

    public function render(): View
    {
        return view('livewire.admin.users', [
            'users' => User::query()->with('roles')->orderBy('name')->paginate(25),
            'invitations' => Invitation::query()->with('inviter')->pending()->latest()->get(),
            'roleOptions' => Role::options(),
            'editingUser' => $this->editing ? User::query()->find($this->editing) : null,
            'deactivatingUser' => $this->deactivating ? User::query()->find($this->deactivating) : null,
        ]);
    }
}
