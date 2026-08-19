@props([
    'route',
    'params' => [],
    'label' => null,
])

{{-- Plain links, deliberately without wire:navigate: these are downloads, and
     Livewire's SPA navigation would try to render the file as a page. --}}
<div class="flex items-center gap-2 print:hidden">
    @if ($label)
        <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ $label }}</flux:text>
    @endif

    <flux:button
        size="xs"
        variant="ghost"
        icon="document-arrow-down"
        :href="route($route, $params + ['format' => 'pdf'])"
    >{{ __('PDF') }}</flux:button>

    <flux:button
        size="xs"
        variant="ghost"
        icon="table-cells"
        :href="route($route, $params + ['format' => 'csv'])"
    >{{ __('Excel') }}</flux:button>
</div>
