{{-- No custom theme on this panel: inline styles, not utility classes. --}}
<x-filament-panels::page>
    <x-filament::section heading="The update worked">
        <p style="margin: 0;">This page was added in version 1.0.11. If you can see it, the self-update installed the new code.</p>
        <p style="margin: 1rem 0 0; font-size: 1.125rem; font-weight: 600;">Installed version: {{ $this->getVersion() }}</p>
    </x-filament::section>
</x-filament-panels::page>
