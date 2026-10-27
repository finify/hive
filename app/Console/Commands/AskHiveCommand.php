<?php

namespace App\Console\Commands;

use App\Actions\Ai\AskHive;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Asks Hive a question as a user from the command line, answering at once
 * rather than on the queue. Useful to check the API key and model after a
 * deploy; the request is logged like any other.
 */
class AskHiveCommand extends Command
{
    protected $signature = 'hive:ask {question : What to ask} {--as= : The email of the user to ask as}';

    protected $description = 'Ask Hive a question as a user and print the answer';

    public function handle(AskHive $ask): int
    {
        $user = User::query()->where('email', (string) $this->option('as'))->first();

        if ($user === null) {
            $this->error('Pass --as with the email of an active Hive user.');

            return self::FAILURE;
        }

        config(['queue.default' => 'sync']);
        $request = $ask->handle($user, (string) $this->argument('question'))->refresh();

        $this->line("Engine: {$request->engine}".($request->model ? " ({$request->model})" : '').", {$request->duration_ms} ms, {$request->input_tokens} tokens in, {$request->output_tokens} out.");
        $this->line('Tools: '.(collect($request->tools_called)->pluck('tool')->implode(', ') ?: 'none'));
        $this->newLine();

        if ($request->status === 'failed') {
            $this->error((string) $request->error);

            return self::FAILURE;
        }

        $this->line((string) $request->response);

        foreach ($request->answer['sources'] ?? [] as $source) {
            $this->line("  → {$source['label']}: {$source['href']}");
        }

        return self::SUCCESS;
    }
}
