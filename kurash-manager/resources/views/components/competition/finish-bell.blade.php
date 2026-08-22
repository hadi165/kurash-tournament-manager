@props([
    'court',              // whose buzzer this is — each mat has its own
    'bout' => null,       // the contest on screen, or null
    'decided' => false,   // whether it has been won
])

@php($source = (string) $court->finishSound())

{{-- Host-relative on purpose. asset() builds from APP_URL, which is the
     address the server was started on — a board opened at 127.0.0.1 would then
     be sent to fetch its buzzer from the LAN address, across an origin, from a
     host that machine may not even be able to reach. Served from wherever the
     page came from instead. --}}
@php($src = $source === '' ? '' : '/'.ltrim($source, '/'))

{{-- The buzzer a contest ends on.

     Sits on both the mat screen and the wall board, from one file, because two
     screens that sounded the end at different moments — or one that did and one
     that did not — is worse than either. --}}
@if ($source !== '')
    <div
        x-data="finishBell({ src: @js($src), bout: @js($bout?->getKey()), decided: @js((bool) $decided) })"
        {{-- Read from the element rather than from an expression Alpine would
             have to be re-evaluated to see. Livewire rewrites these on every
             poll, and the component reads them on its own clock — a buzzer is
             not something to hang on which framework notices an attribute
             changed. --}}
        data-bout="{{ $bout?->getKey() }}"
        data-decided="{{ $decided ? '1' : '0' }}"
        style="display: contents"
    >
        {{-- A board on a projector may never be touched, and a browser will
             not play a sound on a page nobody has touched. Rather than fail
             quietly, it asks.

             Styled inline rather than with utility classes: this sits on the
             wall board too, and that shell carries the board's own stylesheet
             rather than the application's, so a class here would render as
             nothing at all. --}}
        <button
            type="button"
            x-show="! armed"
            x-on:click.stop="test()"
            style="position: fixed; inset-inline-end: 1rem; inset-block-end: 1rem; z-index: 60;
                   display: inline-flex; align-items: center; gap: .5rem;
                   padding: .55rem 1rem; border: 0; border-radius: 999px;
                   background: rgba(17, 24, 39, .88); color: #fff;
                   font: 600 13px/1.2 system-ui, sans-serif; cursor: pointer;
                   box-shadow: 0 2px 10px rgba(0, 0, 0, .35);"
        >
            <span aria-hidden="true">🔔</span>
            {{ __('Tap to test the end-of-contest sound') }}
        </button>
    </div>
@endif
