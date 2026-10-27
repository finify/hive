<?php

namespace App\Services\AskHive;

use App\Demo\Workspace;
use App\Models\User;
use App\Services\AskHive\Operations\Operation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The changes Ask Hive can propose. The model never changes anything: it
 * proposes, Hive checks the proposal by running the real Action as the user
 * inside a transaction that is rolled back (with notifications, mail and
 * jobs held back), and the user confirms it in the panel. Only then does
 * the Action run for real, through ConfirmProposal.
 */
class Proposals
{
    /** @var list<class-string<Operation>> */
    public const OPERATIONS = [
        Operations\RequestLeave::class,
        Operations\CancelLeave::class,
        Operations\AddTimesheetEntry::class,
        Operations\SubmitTimesheet::class,
        Operations\RespondToBooking::class,
        Operations\MarkUnavailable::class,
        Operations\ClaimMileage::class,
        Operations\ApproveTimesheetEntries::class,
        Operations\ReturnTimesheetEntry::class,
        Operations\DecideLeave::class,
        Operations\AddTask::class,
        Operations\SetTaskStatus::class,
        Operations\BookPerson::class,
        Operations\ReleaseBooking::class,
        Operations\TickChecklistItem::class,
    ];

    /** How long a proposal can be confirmed for. */
    public const EXPIRES_MINUTES = 30;

    public function __construct(private readonly Workspace $workspace) {}

    public function operation(string $name): ?Operation
    {
        foreach (self::OPERATIONS as $class) {
            if ($class::name() === $name) {
                return new $class($this->workspace);
            }
        }

        return null;
    }

    /**
     * The operations this user's role can use at all; the Action still
     * decides on each record.
     *
     * @return list<Operation>
     */
    public function offeredTo(User $user): array
    {
        return array_values(array_filter(
            array_map(fn (string $class) => new $class($this->workspace), self::OPERATIONS),
            fn (Operation $op) => $op->offeredTo($user),
        ));
    }

    /**
     * Checks a proposal without keeping anything.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: true, summary: string}|array{ok: false, error: string}
     */
    public function check(User $user, Operation $operation, array $input): array
    {
        if (! $operation->offeredTo($user)) {
            return ['ok' => false, 'error' => 'Your role can’t do that in Hive.'];
        }

        $notifications = Notification::getFacadeRoot();
        $mail = Mail::getFacadeRoot();
        $bus = Bus::getFacadeRoot();
        $queue = Queue::getFacadeRoot();

        Notification::fake();
        Mail::fake();
        Bus::fake();
        Queue::fake();
        DB::beginTransaction();

        try {
            $operation->perform($user, $input);
            $error = null;
        } catch (Throwable $e) {
            $error = self::explain($e);
        } finally {
            DB::rollBack();
            Notification::swap($notifications);
            Mail::swap($mail);
            Bus::swap($bus);
            Queue::swap($queue);
            // Anything read inside the transaction may have been cached
            // under a version the rollback undid: move every reader on.
            $this->workspace->forget();
        }

        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        try {
            return ['ok' => true, 'summary' => $operation->summary($user, $input)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => self::explain($e)];
        }
    }

    /**
     * A new pending proposal, as stored on the request.
     *
     * @param  array<string, mixed>  $input
     * @return array{id: string, operation: string, input: array<string, mixed>, summary: string, status: string, message: ?string, proposed_at: string}
     */
    public static function pending(string $operation, array $input, string $summary): array
    {
        return [
            'id' => (string) Str::ulid(), 'operation' => $operation, 'input' => $input, 'summary' => $summary,
            'status' => 'pending', 'message' => null, 'proposed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * What went wrong, in words the user (and the model) can act on. Other
     * failures are reported and never shown.
     */
    public static function explain(Throwable $e): string
    {
        return match (true) {
            $e instanceof ValidationException => implode(' ', array_unique($e->validator->errors()->all())),
            $e instanceof AuthorizationException, $e instanceof HttpException && $e->getStatusCode() === 403 => 'Your role can’t do that to this record.',
            $e instanceof ModelNotFoundException, $e instanceof HttpException && $e->getStatusCode() === 404 => 'That record doesn’t exist.',
            default => tap('Hive couldn’t check that change. Try it from the page itself.', fn () => report($e)),
        };
    }
}
