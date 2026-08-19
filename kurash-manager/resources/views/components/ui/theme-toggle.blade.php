{{-- Flux owns the appearance setting; this is the same switch the settings
     screen writes, surfaced in the top row where the design asks for it. Both
     labels are rendered and the dark: variant picks one, so the control is
     correct on first paint rather than after Alpine reads the theme. --}}
<button
    type="button"
    x-data
    x-on:click="$flux.appearance = $flux.appearance === 'dark' ? 'light' : 'dark'"
    {{ $attributes->class('rounded-full px-4 py-2 text-[13.5px] font-semibold text-ink transition-colors hover:bg-line-soft') }}
>
    <span class="hidden dark:inline">{{ __('Light') }}</span>
    <span class="dark:hidden">{{ __('Dark') }}</span>
</button>
