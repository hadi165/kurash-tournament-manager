{{-- Flash messages are soft callouts rather than banners: they sit in the
     content column with the cards, so they take the same 12px radius and a
     tint of the colour that names them. --}}
@if (session('status'))
    <div class="flex items-start gap-2.5 rounded-md bg-brand-soft px-4 py-3 text-[13.5px] font-medium text-brand-deep">
        <span class="mt-[6px] size-[7px] flex-none rounded-full bg-brand"></span>
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div class="flex items-start gap-2.5 rounded-md bg-danger-soft px-4 py-3 text-[13.5px] font-medium text-danger-deep">
        <span class="mt-[6px] size-[7px] flex-none rounded-full bg-danger"></span>
        {{ session('error') }}
    </div>
@endif
