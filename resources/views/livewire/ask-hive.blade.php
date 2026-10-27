<div class="offcanvas offcanvas-end ask-panel" tabindex="-1" id="ask-hive" aria-labelledby="ask-hive-title" wire:ignore.self
     x-data x-on:ask-hive-open.window="bootstrap.Offcanvas.getOrCreateInstance($el).show()">
    <div class="offcanvas-header border-bottom">
        <div>
            <h2 class="offcanvas-title fs-6 fw-medium" id="ask-hive-title">Ask Hive</h2>
            <div class="small text-muted">Answers come only from records you can see. Changes happen only when you confirm them.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close Ask Hive"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
        <div class="ask-log flex-grow-1" aria-live="polite" @if ($waiting) wire:poll.1500ms @endif>
            @forelse ($conversation as $turn)
                <div class="ask-q" wire:key="q-{{ $turn['id'] }}">{{ $turn['q'] }}</div>
                <div class="ask-a" wire:key="a-{{ $turn['id'] }}">
                    @if ($turn['pending'])
                        <p class="text-muted mb-0">Looking through Hive…</p>
                    @else
                        <div @class(['ask-answer', 'text-negative' => $turn['a']['failed'] ?? false])>
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
                        </div>
                        @foreach ($turn['a']['proposals'] ?? [] as $p)
                            <div @class(['ask-proposal', 'is-pending' => $p['status'] === 'pending']) wire:key="p-{{ $p['id'] }}">
                                <div class="ask-proposal-label">{{ $p['status'] === 'pending' ? 'Proposed change' : 'Change' }}</div>
                                <div>{{ $p['summary'] }}</div>
                                @if ($p['status'] === 'pending')
                                    <div class="d-flex gap-2 mt-2">
                                        <button type="button" class="btn btn-primary btn-sm" wire:click="confirm({{ $turn['id'] }}, @js($p['id']))" wire:loading.attr="disabled" wire:target="confirm, cancelProposal">Confirm</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="cancelProposal({{ $turn['id'] }}, @js($p['id']))" wire:loading.attr="disabled" wire:target="confirm, cancelProposal">Cancel</button>
                                    </div>
                                @else
                                    <div @class(['ask-proposal-result', 'text-negative' => in_array($p['status'], ['failed', 'expired'], true), 'text-muted' => $p['status'] === 'cancelled'])>
                                        <i @class(['bi', 'bi-check-circle-fill text-positive' => $p['status'] === 'done', 'bi-x-circle' => $p['status'] !== 'done']) aria-hidden="true"></i>
                                        {{ $p['message'] }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        @if ($turn['a']['sources'])
                            <div class="ask-sources" aria-label="Sources">
                                @foreach ($turn['a']['sources'] as $s)
                                    <a href="{{ $s['href'] }}" wire:navigate>{{ $s['label'] }}</a>
                                @endforeach
                            </div>
                        @endif
                        <div class="ask-tools mt-2 d-flex flex-wrap gap-2 align-items-center">
                            @if ($turn['a']['tools'])
                                <span>Looked at: {{ implode(', ', array_unique(array_map(fn ($t) => str_replace('_', ' ', $t), $turn['a']['tools']))) }}</span>
                            @endif
                            @unless ($turn['a']['failed'] ?? false)
                                <button type="button" class="btn btn-link link small p-0" x-data="{ copied: false }"
                                        x-on:click="navigator.clipboard.writeText($el.closest('.ask-a').querySelector('.ask-answer').innerText).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                                        x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                            @endunless
                        </div>
                    @endif
                </div>
            @empty
                <div>
                    <p class="text-muted">Try one of these, or ask your own:</p>
                    @foreach ($suggestions as $s)
                        <button type="button" class="ask-suggestion" wire:click="suggest(@js($s))" wire:loading.attr="disabled">{{ $s }}</button>
                    @endforeach
                    <p class="small text-muted mt-3 mb-0">Ask Hive can propose a change, like a leave request or a booking. Nothing happens until you check it and press Confirm, and it can only do what your role can.</p>
                </div>
            @endforelse
            <div wire:loading wire:target="ask, suggest, workflow" class="text-muted small">Sending…</div>
        </div>

        <form wire:submit="ask" class="pt-3 border-top mt-3">
            <label for="ask-question" class="visually-hidden">Your question</label>
            <div class="d-flex gap-2">
                <input id="ask-question" @class(['form-control', 'is-invalid' => $errors->has('question')]) wire:model="question" placeholder="Ask, or tell Hive what to do" autocomplete="off" maxlength="500">
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
