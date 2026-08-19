{{-- Flux owns the appearance setting; this is the same switch the settings
     screen writes, surfaced in the utility bar where the design asks for it.
     Both labels are rendered and the dark: variant picks one, so the control
     is correct on first paint rather than after Alpine reads the theme. --}}
<button
    type="button"
    x-data
    x-on:click="$flux.appearance = $flux.appearance === 'dark' ? 'light' : 'dark'"
    {{ $attributes->class('border border-divider px-3 py-1 text-xs font-bold text-ink hover:bg-ink/7') }}
>
    <span class="hidden dark:inline">{{ __('Light mode') }}</span>
    <span class="dark:hidden">{{ __('Dark mode') }}</span>
</button>
