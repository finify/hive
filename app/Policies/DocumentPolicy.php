<?php

namespace App\Policies;

use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Models\Document;
use App\Models\Event;
use App\Models\Person;
use App\Models\Supplier;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Opening the file itself. Producers and finance see a document's status
     * on screen, not its contents.
     */
    public function download(User $user, Document $document): bool
    {
        if ($document->documentable_type === Person::class) {
            return $user->can('documents.view') || $user->person_id === $document->documentable_id;
        }

        if ($document->documentable_type === Supplier::class) {
            return $user->can('directory.manage');
        }

        if ($document->documentable_type === Event::class) {
            $event = app(Workspace::class)->event($document->documentable_id);

            return $event !== null && (new Viewer($user, app(Workspace::class)))->canSeeEvent($event);
        }

        return false;
    }
}
