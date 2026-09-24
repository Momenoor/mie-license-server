@php
    $updater = app(\App\Services\SelfUpdater::class);
    $state = $this->updateState;
    $current = $this->getCurrentVersion();
    $commit = \App\Support\AppVersion::commit();
    $label = 'font-size: .875rem; opacity: .7;';
    $value = 'font-size: 1.125rem; font-weight: 600;';
    $pre = 'max-height: 24rem; overflow: auto; white-space: pre-wrap; font-size: .75rem; margin: 0;';
@endphp

{{-- No custom theme on this panel: layout uses inline styles, not utility classes. --}}
<x-filament-panels::page>
    @if ($state === null)
        <x-filament::section heading="Version">
            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));">
                <div>
                    <div style="{{ $label }}">Installed version</div>
                    <div style="{{ $value }}">{{ $current ?? 'untagged' }}@if ($current === null && $commit) <span style="opacity: .6; font-weight: 400;">(commit {{ $commit }})</span>@endif</div>
                </div>
                <div>
                    <div style="{{ $label }}">Latest version on GitHub</div>
                    <div style="{{ $value }}">
                        {{ $this->latest['version'] ?? '—' }}
                        @if ($this->isUpdateAvailable())
                            <x-filament::badge color="warning" style="display: inline-flex;">Update available</x-filament::badge>
                        @elseif ($this->latest !== null)
                            <x-filament::badge color="success" style="display: inline-flex;">Up to date</x-filament::badge>
                        @endif
                    </div>
                </div>
            </div>

            @if ($this->checkError)
                <p style="margin-top: 1rem; color: rgb(var(--danger-600));">Could not check GitHub: {{ $this->checkError }}</p>
            @elseif ($this->latest === null)
                <p style="margin-top: 1rem; opacity: .7;">No vX.Y.Z tags on the repository yet — tag a release (e.g. v1.0.0) to enable updates.</p>
            @endif
        </x-filament::section>

        @if ($this->isUpdateAvailable() && filled($this->latest['notes'] ?? null))
            <x-filament::section heading="What's new in {{ $this->latest['version'] }}">
                <div class="fi-prose">
                    {!! \Illuminate\Support\Str::markdown($this->latest['notes'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                </div>
            </x-filament::section>
        @endif
    @else
        <div
            x-data="{
                running: false,
                waiting: false,
                live: '',
                poller: null,
                start() {
                    if (this.running) return;
                    this.running = true;
                    this.poller = setInterval(() => this.pollLive(), 2000);
                    this.tick();
                },
                stop() {
                    this.running = false;
                    clearInterval(this.poller);
                    this.pollLive();
                },
                // A plain route: a Livewire call would queue behind the step.
                pollLive() {
                    fetch(@js(route('self-update.live-output')), { headers: { Accept: 'application/json' } })
                        .then((response) => response.ok ? response.json() : null)
                        .then((data) => {
                            if (! data || data.output === this.live) return;
                            this.live = data.output;
                            this.$nextTick(() => { if (this.$refs.live) this.$refs.live.scrollTop = this.$refs.live.scrollHeight; });
                        })
                        .catch(() => {});
                },
                // runNextStep() (renderless) then refreshState() (redraw). A
                // step the web server timed out keeps running in PHP: the next
                // call answers 'busy' until it's done.
                tick() {
                    if ($wire.updateState === null || $wire.updateState.failed) {
                        this.stop();
                        return;
                    }
                    $wire.runNextStep()
                        .then((status) => $wire.refreshState().then(() => {
                            this.waiting = status === 'busy';
                            setTimeout(() => this.tick(), this.waiting ? 3000 : 0);
                        }))
                        .catch(() => this.retryLater());
                },
                retryLater() {
                    this.waiting = true;
                    setTimeout(() => $wire.refreshState().then(() => this.tick(), () => this.retryLater()), 3000);
                },
            }"
            x-init="
                // Handle failed requests here, not with Livewire's error pop-up.
                ['runNextStep', 'refreshState'].forEach((method) => $wire.$intercept(method, ({ onError }) => onError(({ preventDefault }) => preventDefault())));
                start();
            "
            style="display: flex; flex-direction: column; gap: 1.5rem;"
        >
            <div x-show="waiting" x-cloak>
                <x-filament::section>
                    Waiting for the server to finish this step… This can take a few minutes. If nothing changes for a long time, reload the page — the update resumes where it stopped.
                </x-filament::section>
            </div>

            <x-filament::section heading="Updating to {{ $state['version'] }}">
                @php $pendingShown = false; @endphp
                @foreach ($updater->steps() as $key => $stepLabel)
                    @php
                        $done = in_array($key, $state['completed'], true);
                        $isCurrent = ! $done && ! $pendingShown;
                        if ($isCurrent) { $pendingShown = true; }
                    @endphp
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .5rem 0; {{ ! $done && ! $isCurrent ? 'opacity: .5;' : '' }}">
                        <span>{{ $stepLabel }}</span>
                        @if ($done)
                            <x-filament::badge color="success">Done</x-filament::badge>
                        @elseif ($isCurrent && $state['failed'])
                            <x-filament::badge color="danger">Failed</x-filament::badge>
                        @elseif ($isCurrent)
                            <x-filament::loading-indicator style="width: 1.25rem; height: 1.25rem;" />
                        @else
                            <x-filament::badge color="gray">Pending</x-filament::badge>
                        @endif
                    </div>
                @endforeach

                @if ($state['failed'])
                    <div style="margin-top: 1rem;">
                        <x-filament::button x-on:click="$wire.retryStep().then(() => start())">Retry failed step</x-filament::button>
                    </div>
                @endif
            </x-filament::section>

            {{-- wire:ignore: Livewire redraws must not reset what polling filled in. --}}
            <div wire:ignore x-show="running && live !== ''" x-cloak>
                <x-filament::section heading="Live output">
                    <pre x-ref="live" x-text="live" style="{{ $pre }}"></pre>
                </x-filament::section>
            </div>

            @if (filled($state['log']))
                <x-filament::section heading="Log" collapsible>
                    <pre style="{{ $pre }}">{{ $state['log'] }}</pre>
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
