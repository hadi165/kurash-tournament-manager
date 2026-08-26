{{-- Flux owns the appearance setting; this is the same switch the settings
     screen writes, surfaced in the top row where the design asks for it.

     An icon rather than the word "Light". The text was the *destination*, not
     the current state, so the control read as a label for the theme you were
     not in — and next to a page title it looked like one more heading. Both
     icons are rendered and the dark: variant picks one, so the button is
     correct on first paint rather than after Alpine has read the theme.

     The accessible name is a plain sr-only string. It says what the button
     does, which is the one thing that does not change with the theme. --}}
<button
    type="button"
    x-data
    x-on:click="$flux.appearance = $flux.appearance === 'dark' ? 'light' : 'dark'"
    {{ $attributes->class('grid size-9 place-items-center rounded-full text-ink transition-colors hover:bg-line-soft') }}
>
    <flux:icon.sun class="hidden size-[18px] dark:block" />
    <flux:icon.moon class="size-[18px] dark:hidden" />

    <span class="sr-only">{{ __('Switch theme') }}</span>
</button>
