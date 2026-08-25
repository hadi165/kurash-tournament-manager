{{-- Venue display shell.

     Self-contained: no Vite, no Livewire, no JavaScript. These pages are
     served from cache to every screen in the hall, and a display left running
     all weekend must recover on its own from a dropped network — a meta
     refresh does that, a JS poller left in a broken state does not. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="{{ $refresh ?? 10 }}">
    <title>{{ $title }} — {{ $championship->title }}</title>
    <style>
        :root {
            --bg: #0b1020;
            --panel: #161d33;
            --line: #2a3350;
            --text: #f2f5ff;
            --muted: #8f9ec4;
            --blue: #3b82f6;
            --green: #22c55e;
            --gold: #fbbf24;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 1.5rem 2rem;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 20px;
            line-height: 1.35;
        }

        header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
            border-bottom: 3px solid var(--line);
            padding-bottom: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .association { font-size: 1.1rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
        .competition { color: var(--muted); font-size: 0.95rem; }

        h1 { margin: 0; font-size: 1.6rem; }

        table { width: 100%; border-collapse: collapse; }

        th {
            text-align: left;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            padding: 0.4rem 0.6rem;
            border-bottom: 2px solid var(--line);
        }

        td { padding: 0.55rem 0.6rem; border-bottom: 1px solid var(--line); }

        tbody tr:nth-child(even) { background: rgba(255, 255, 255, 0.025); }

        .num { font-variant-numeric: tabular-nums; font-weight: 700; }
        .muted { color: var(--muted); }
        .blue { color: var(--blue); }
        .green { color: var(--green); }
        .win { font-weight: 700; color: var(--gold); }

        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }

        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 1rem 1.15rem; }

        .panel h2 { margin: 0 0 0.6rem; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.09em; color: var(--muted); }

        .scroll { overflow-x: auto; }

        footer { margin-top: 1.5rem; color: var(--muted); font-size: 0.8rem; }

        .empty { color: var(--muted); font-style: italic; padding: 1.5rem 0; text-align: center; }

        /* Flags. Sized in em so they scale with whatever text they sit beside,
           from a table row to the large name on the mat panels. */
        .flag {
            width: 1.55em;
            height: 1.16em;
            border-radius: 2px;
            object-fit: cover;
            vertical-align: -0.16em;
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.18);
            flex: none;
        }

        .flag-blank { display: inline-block; background: #33406a; }

        .competitor { display: inline-flex; align-items: center; gap: 0.45em; min-width: 0; }

        .noc { color: var(--muted); font-size: 0.72em; font-weight: 600; letter-spacing: 0.04em; }

        .brand { display: flex; align-items: center; gap: 0.7rem; }
        .brand img { height: 2.6rem; width: 2.6rem; object-fit: contain; }
        .brand .monogram {
            height: 2.4rem; width: 2.4rem; border: 2px solid currentColor; border-radius: 999px;
            display: grid; place-items: center; font-size: 0.7rem; font-weight: 700;
        }
    </style>

    {{-- A page with geometry of its own — the bracket tree — puts it here
         rather than in the body: these screens load no stylesheet, so a page
         that needs rules has nowhere else to keep them. --}}
    {{ $styles ?? '' }}
</head>
<body>
    @php
        $logo = config('branding.logo');
        $hasLogo = $logo && is_file(public_path($logo));
    @endphp

    <header>
        <div class="brand">
            @if ($hasLogo)
                <img src="{{ asset($logo) }}" alt="{{ config('branding.organisation') }}">
            @else
                <span class="monogram" aria-hidden="true">{{ config('branding.short_name') }}</span>
            @endif

            <div>
                <div class="association">{{ config('branding.organisation') }}</div>
                <div class="competition">{{ $championship->title }}@if ($championship->location) · {{ $championship->location }}@endif</div>
            </div>
        </div>
        <h1>{{ $title }}</h1>
    </header>

    {{ $slot }}

    <footer>Updated {{ now()->format('H:i:s') }} · refreshes automatically</footer>
</body>
</html>
