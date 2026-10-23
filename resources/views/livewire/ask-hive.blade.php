<div class="offcanvas offcanvas-end ask-panel" tabindex="-1" id="ask-hive" aria-labelledby="ask-hive-title" wire:ignore.self>
    <div class="offcanvas-header border-bottom">
        <div>
            <h2 class="offcanvas-title fs-6 fw-medium" id="ask-hive-title">Ask Hive</h2>
            <div class="small text-muted">Answers come only from records you can see, with links to them.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close Ask Hive"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
        <div class="ask-log flex-grow-1" aria-live="polite">
            @forelse ($conversation as $turn)
                <div class="ask-q">{{ $turn['q'] }}</div>
                <div class="ask-a">
                    @foreach ($turn['a']['paragraphs'] as $p)
                        <p>{{ $p }}</p>
                    @endforeach
                    @if ($turn['a']['bullets'])
                        <ul>
                            @foreach ($turn['a']['bullets'] as $b)
                                <li>{{ $b }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($turn['a']['sources'])
                        <div class="ask-sources" aria-label="Sources">
                            @foreach ($turn['a']['sources'] as $s)
                                <a href="{{ $s['href'] }}" wire:navigate>{{ $s['label'] }}</a>
                            @endforeach
                        </div>
                    @endif
                    @if ($turn['a']['tools'])
                        <div class="ask-tools mt-2">Looked at: {{ implode(', ', array_unique(array_map(fn ($t) => str_replace('_', ' ', $t), $turn['a']['tools']))) }}</div>
                    @endif
                </div>
            @empty
                <div>
                    <p class="text-muted">Try one of these, or ask your own:</p>
                    @foreach ($suggestions as $s)
                        <button type="button" class="ask-suggestion" wire:click="suggest(@js($s))" wire:loading.attr="disabled">{{ $s }}</button>
                    @endforeach
                    <p class="small text-muted mt-3 mb-0">Ask Hive reads; people decide. It never approves, books, pays or deletes anything.</p>
                </div>
            @endforelse
            <div wire:loading wire:target="ask, suggest" class="text-muted small">Looking through Hive…</div>
        </div>

        <form wire:submit="ask" class="pt-3 border-top mt-3">
            <label for="ask-question" class="visually-hidden">Your question</label>
            <div class="d-flex gap-2">
                <input id="ask-question" @class(['form-control', 'is-invalid' => $errors->has('question')]) wire:model="question" placeholder="Ask about events, crew, time or money" autocomplete="off" maxlength="500">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="ask">Ask</button>
            </div>
            @error('question')
                <div class="small text-negative mt-1">{{ $message }}</div>
            @enderror
            @if ($conversation)
                <button type="button" class="btn btn-link link small p-0 mt-2" wire:click="clear">Start again</button>
            @endif
        </form>
    </div>
</div>
