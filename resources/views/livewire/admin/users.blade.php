@php
    use App\Enums\Role;
    use App\Support\Display;
@endphp
<div>
    <x-page-head title="Users and roles" :answer="$users->total().' users, '.$invitations->count().' invitations waiting. People join by invitation only.'" />

    <div class="grid-12">
        <section class="card span-8">
            <div class="card-header"><h2 class="card-title">Users</h2></div>
            <div class="table-wrap border-0 rounded-0">
                <table class="table">
                    <thead><tr><th>Name</th><th>Roles</th><th>Two-factor</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($users as $u)
                            <tr wire:key="u-{{ $u->id }}">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <x-avatar :name="$u->name" :id="$u->person_id ?? $u->id" size="sm" />
                                        <span>{{ $u->name }}<span class="d-block small text-muted">{{ $u->email }}</span></span>
                                    </div>
                                </td>
                                <td class="small">{{ $u->roles->map(fn ($r) => Role::tryFrom($r->name)?->label() ?? $r->name)->implode(', ') }}</td>
                                <td>
                                    @if ($u->hasConfirmedTwoFactor())
                                        <x-pill tone="positive">On</x-pill>
                                    @elseif ($u->requiresTwoFactor())
                                        <x-pill tone="negative">Required, not set up</x-pill>
                                    @else
                                        <span class="small text-muted">Off</span>
                                    @endif
                                </td>
                                <td class="small tnum">{{ Display::dateTime($u->last_login_at) }}</td>
                                <td><x-pill :tone="$u->is_active ? 'positive' : 'neutral'">{{ $u->is_active ? 'Active' : 'Deactivated' }}</x-pill></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-secondary btn-sm" wire:click="editRoles({{ $u->id }})">Roles</button>
                                    @if ($u->is_active && $u->isNot(auth()->user()))
                                        <button type="button" class="btn btn-quiet btn-sm text-negative" wire:click="confirmDeactivate({{ $u->id }})">Deactivate</button>
                                    @endif
                                    @if (! $u->is_active)
                                        <button type="button" class="btn btn-quiet btn-sm" wire:click="reactivate({{ $u->id }})">Reactivate</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-body">{{ $users->links() }}</div>
        </section>

        <div class="span-4 d-grid gap-3 align-content-start">
            <section class="card">
                <div class="card-header"><h2 class="card-title">Invite someone</h2></div>
                <form class="card-body d-grid gap-3" wire:submit="invite">
                    <div>
                        <label for="inv-email" class="form-label">Work email</label>
                        <input id="inv-email" type="email" @class(['form-control', 'is-invalid' => $errors->has('email')]) wire:model="inviteEmail" placeholder="name@lumenco.test">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="inv-role" class="form-label">Role</label>
                        <select id="inv-role" @class(['form-select', 'is-invalid' => $errors->has('role')]) wire:model="inviteRole">
                            @foreach ($roleOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Send invitation</button>
                    <p class="small text-muted mb-0">The link expires after 7 days. Super admin, operations director, finance and HR must set up two-factor before they can use Hive.</p>
                </form>
            </section>
            <section class="card">
                <div class="card-header"><h2 class="card-title">Waiting to accept</h2></div>
                @if ($invitations->isEmpty())
                    <x-empty title="No open invitations">Invitations you send appear here until they are accepted or expire.</x-empty>
                @else
                    <ul class="list-rows">
                        @foreach ($invitations as $inv)
                            <li><div class="row-main"><div>{{ $inv->email }}</div><div class="row-sub">{{ $inv->role->label() }}, invited by {{ $inv->inviter->name }}, expires {{ Display::date($inv->expires_at) }}</div></div></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>

    <x-modal id="roles-modal" :title="$editingUser ? 'Roles for '.$editingUser->name : 'Roles'">
        <p class="small text-muted">A person can hold more than one role, for example a producer who is also a head of department.</p>
        @foreach ($roleOptions as $value => $label)
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="role-{{ $value }}" value="{{ $value }}" wire:model="roles">
                <label class="form-check-label" for="role-{{ $value }}">{{ $label }}</label>
            </div>
        @endforeach
        @error('roles')<div class="small text-negative mt-2">{{ $message }}</div>@enderror
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" wire:click="saveRoles" wire:loading.attr="disabled">Save roles</button>
        </x-slot:footer>
    </x-modal>

    <x-modal id="deactivate-modal" :title="$deactivatingUser ? 'Deactivate '.$deactivatingUser->name.'?' : 'Deactivate user'">
        <p class="mb-0">They are signed out everywhere straight away and can’t sign in again. Their history, timesheets and approvals stay in Hive. You can reactivate them later.</p>
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep active</button>
            <button type="button" class="btn btn-danger" wire:click="deactivate" wire:loading.attr="disabled">Deactivate and sign out</button>
        </x-slot:footer>
    </x-modal>
</div>
