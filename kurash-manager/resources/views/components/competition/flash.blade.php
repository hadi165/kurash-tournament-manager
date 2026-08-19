@if (session('status'))
    <flux:callout variant="success" icon="check-circle" class="mb-0">
        {{ session('status') }}
    </flux:callout>
@endif

@if (session('error'))
    <flux:callout variant="danger" icon="exclamation-triangle" class="mb-0">
        {{ session('error') }}
    </flux:callout>
@endif
