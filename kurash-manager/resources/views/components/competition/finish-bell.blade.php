@props([
    'bout' => null,       // the contest on screen, or null
    'decided' => false,   // whether it has been won
])

@php($source = trim((string) config('scoreboard.finish_sound')))

{{-- The buzzer a contest ends on.

     Sits on both the mat screen and the wall board, from one file, because two
     screens that sounded the end at different moments — or one that did and one
     that did not — is worse than either. --}}
@if ($source !== '')
    <div
        x-data="finishBell({ src: @js(asset($source)), bout: @js($bout?->getKey()), decided: @js((bool) $decided) })"
        x-effect="watch(@js($bout?->getKey()), @js((bool) $decided))"
        class="contents"
    >
        {{-- A board on a projector may never be touched, and a browser will not
             play a sound on a page nobody has touched. Rather than fail
             quietly, it asks — once, small, and out of the way once pressed. --}}
        <button
            type="button"
            x-show="! armed"
            x-cloak
            x-on:click="arm()"
            class="fixed bottom-4 end-4 z-50 rounded-full border border-line bg-surface px-3.5 py-2
                   text-[12.5px] font-semibold text-ink shadow-chip"
        >
            {{ __('Tap to enable the end-of-contest sound') }}
        </button>
    </div>
@endif
