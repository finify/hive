<?php

namespace App\Actions\Gdpr;

use App\Models\AiRequest;
use App\Models\Document;
use App\Models\Expense;
use App\Models\RetentionFlag;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * HR decides on a flagged record: keep it, or remove it (leavers are
 * anonymised rather than deleted, so their financial records stay).
 */
class DecideRetention
{
    public function __construct(private readonly AnonymisePerson $anonymise) {}

    public function handle(User $user, int $flagId, bool $remove): RetentionFlag
    {
        Gate::forUser($user)->authorize('people.manage');
        $flag = RetentionFlag::query()->whereNull('decision')->findOrFail($flagId);

        $decision = 'keep';

        if ($remove) {
            $decision = match ($flag->category) {
                'leaver' => $this->anonymiseLeaver($user, $flag),
                'timesheet' => $this->delete(Timesheet::query()->find($flag->subject_id)),
                'expense' => $this->delete(Expense::query()->find($flag->subject_id)),
                'ai-request' => $this->delete(AiRequest::query()->find($flag->subject_id)),
                'document' => $this->deleteDocument($flag->subject_id),
                default => throw ValidationException::withMessages(['flag' => 'Unknown record type.']),
            };
        }

        $flag->update(['decision' => $decision, 'decided_by' => $user->person_id, 'decided_at' => now()]);

        activity('gdpr')->causedBy($user)
            ->withProperties(['ref' => 'RF-'.$flag->id, 'attributes' => ['category' => $flag->category, 'decision' => $decision]])
            ->event('retention-decided')->log(ucfirst($decision === 'keep' ? 'kept' : $decision)." {$flag->label}");

        return $flag;
    }

    private function anonymiseLeaver(User $user, RetentionFlag $flag): string
    {
        $this->anonymise->handle($user, $flag->subject_id, 'Past retention (flag RF-'.$flag->id.')');

        return 'anonymised';
    }

    private function delete(?Model $model): string
    {
        $model?->delete();

        return 'deleted';
    }

    private function deleteDocument(int $id): string
    {
        $doc = Document::query()->find($id);

        if ($doc?->file_path) {
            Storage::disk(config('filesystems.documents_disk', 'local'))->delete($doc->file_path);
        }

        $doc?->delete();

        return 'deleted';
    }
}
