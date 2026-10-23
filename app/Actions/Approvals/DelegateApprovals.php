<?php

namespace App\Actions\Approvals;

use App\Demo\Workspace;
use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An approver hands their approvals to a colleague for some dates, for
 * example while on leave. The colleague sees them in their inbox; the
 * decision is recorded as theirs.
 */
class DelegateApprovals
{
    public const PERMISSIONS = ['timesheets.approve', 'leave.approve', 'supplier_invoices.approve', 'expenses.approve', 'po.approve'];

    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array{delegate_id?: mixed, starts_on?: mixed, ends_on?: mixed, reason?: mixed}  $input
     */
    public function handle(User $user, array $input): ApprovalDelegation
    {
        abort_unless($user->person_id !== null && $user->canAny(self::PERMISSIONS), 403);

        $data = Validator::make($input, [
            'delegate_id' => ['required', 'integer', Rule::notIn([$user->person_id]), Rule::exists('users', 'person_id')->where('is_active', true)],
            'starts_on' => ['required', 'date', 'after_or_equal:'.$this->workspace->today()->toDateString()],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'delegate_id.required' => 'Choose who covers your approvals.',
            'delegate_id.not_in' => 'Choose someone else.',
            'delegate_id.exists' => 'They need a Hive sign-in to approve.',
            'starts_on.after_or_equal' => 'Start today or later.',
        ])->validate();

        $delegateUser = User::query()->active()->where('person_id', (int) $data['delegate_id'])->first();

        if ($delegateUser === null || ! $delegateUser->canAny(self::PERMISSIONS)) {
            throw ValidationException::withMessages(['delegate_id' => 'Choose someone who approves in Hive themselves.']);
        }

        $overlap = ApprovalDelegation::query()->whereNull('cancelled_at')->where('person_id', $user->person_id)
            ->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => 'You already have cover for some of those dates.']);
        }

        $delegation = ApprovalDelegation::query()->create([
            'person_id' => $user->person_id, 'delegate_id' => (int) $data['delegate_id'],
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'reason' => $data['reason'] ?? null,
        ]);

        $dates = DateRange::of($data['starts_on'], $data['ends_on'])->format();
        $name = $this->workspace->personName($user->person_id);
        $delegate = $this->workspace->personName((int) $data['delegate_id']);

        activity('approvals')
            ->causedBy($user)
            ->performedOn($delegation)
            ->withProperties(['ref' => 'AD-'.$delegation->id, 'attributes' => ['delegate' => $delegate, 'dates' => $dates]])
            ->event('delegated')
            ->log("{$name} handed approvals to {$delegate}, {$dates}");

        $this->workspace->notify((int) $data['delegate_id'], 'approval', "You’re covering {$name}’s approvals", "{$dates}. Their items appear in your approvals.", 'approvals');

        return $delegation;
    }

    /**
     * People who could cover: anyone else with a sign-in who approves.
     *
     * @return Collection<int, string> names by person id
     */
    public function candidates(User $user): Collection
    {
        return User::query()->active()->whereNotNull('person_id')->where('person_id', '!=', $user->person_id)->get()
            ->filter(fn (User $u) => $u->canAny(self::PERMISSIONS))
            ->mapWithKeys(fn (User $u) => [(int) $u->person_id => $this->workspace->personName($u->person_id)])
            ->sort()
            ->toBase();
    }

    public function cancel(User $user, int $id): void
    {
        $delegation = ApprovalDelegation::query()->whereNull('cancelled_at')->where('person_id', $user->person_id)->findOrFail($id);
        $delegation->update(['cancelled_at' => now()]);

        activity('approvals')
            ->causedBy($user)
            ->performedOn($delegation)
            ->withProperties(['ref' => 'AD-'.$id, 'old' => ['active' => true], 'attributes' => ['active' => false]])
            ->event('delegation-cancelled')
            ->log('Ended approval cover by '.$this->workspace->personName($delegation->delegate_id));
    }
}
