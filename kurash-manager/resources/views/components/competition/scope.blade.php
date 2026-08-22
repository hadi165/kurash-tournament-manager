@props(['label' => null, 'route', 'championship'])

{{-- What a championship screen is currently narrowed to.

     The sidebar can take you into a competition but not back out of it, so the
     way out lives here, next to the statement of where you are. --}}
@if ($label)
    <div class="mb-[18px] flex flex-wrap items-center gap-2.5">
        <x-ui.tag variant="info">{{ __($label) }}</x-ui.tag>
        <span class="text-[13px] text-muted">{{ __('Showing this competition only.') }}</span>
        <x-ui.chip :href="route($route, $championship)" wire:navigate>{{ __('Show all') }}</x-ui.chip>
    </div>
@endif
