{{-- Shell for the live mat scoreboard.

     Related to the venue display shell but not the same thing. Those pages are
     cached and reload themselves with a meta refresh, which is right for a
     bracket a hall reads. This one carries Livewire, because a scoreboard has
     to change within a second of a call rather than within ten.

     The board carries two themes. Dark is the venue default; light is for a
     bright hall or a daylight-lit projection. `?theme=light` on the URL pins
     one for a given projector, and without it the board follows the same Flux
     appearance setting the rest of the application writes. --}}
@php
    $pinnedTheme = in_array(request('theme'), ['light', 'dark'], true) ? request('theme') : null;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($pinnedTheme) data-theme="{{ $pinnedTheme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('Scoreboard') }}</title>

    @livewireStyles

    {{-- Loaded by the layout rather than by the component that needs it: a
         stylesheet link emitted inside a component view is a second root
         element, and Livewire binds to the first root it finds. It bound to
         the link, which left every control on the page outside the component
         and doing nothing when pressed. --}}
    @vite('resources/css/ceremony.css')

    {{-- The board's own behaviour, including the buzzer a contest ends on.
         This shell carries Livewire but is not the admin shell, so it has to
         ask for this itself — without it the board renders perfectly and
         stays silent, which is the one failure nobody sees coming. --}}
    @vite('resources/js/app.js')


    @unless ($pinnedTheme)
        {{-- Applied before first paint, so a light-themed board does not flash
             black on every poll-driven reload. --}}
        <script>
            (() => {
                const stored = localStorage.getItem('flux.appearance') ?? 'dark'
                const dark = stored === 'dark'
                    || (stored === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)

                document.documentElement.dataset.theme = dark ? 'dark' : 'light'
            })()
        </script>
    @endunless

    <style>
        /* Self-hosted, like the rest of the application: a venue machine is
           often on a locked-down network, and a board that loses its typeface
           mid-competition is a board nobody trusts. 900 is here because the
           names and counts are set in Black — at thirty metres the weight is
           what carries, not the size alone. */
        @font-face {
            font-family: 'Source Sans 3';
            src: url('/fonts/source-sans-3/SourceSans3-Semibold.woff2') format('woff2');
            font-weight: 600;
            font-display: swap;
        }

        @font-face {
            font-family: 'Source Sans 3';
            src: url('/fonts/source-sans-3/SourceSans3-Bold.woff2') format('woff2');
            font-weight: 700;
            font-display: swap;
        }

        @font-face {
            font-family: 'Source Sans 3';
            src: url('/fonts/source-sans-3/SourceSans3-Black.woff2') format('woff2');
            font-weight: 900;
            font-display: swap;
        }

        /* Dark is the default: an unthemed board is a venue board. */
        :root, :root[data-theme="dark"] {
            --bg: #05070a;
            --chrome: #0b0f15;
            --pane: #0e131a;
            --cell: #151d26;
            --cell-dim: #0d1218;
            --strip: #0a0e14;
            --text: #ffffff;
            --muted: #8a99ab;
            --dim: #4e5b6b;
            --line: #1b222c;
            --cell-line: #232d39;
            --flag-fill: repeating-linear-gradient(135deg, #171e27 0 10px, #1e262f 10px 20px);
            --clock-urgent: #ff2a17;

            /* The two panes are the athletes' yakhtak, so the tint has to be
               unmistakably blue and unmistakably green from the back of a hall
               — not a hint of one on a dark ground. */
            --blue-tint: #0a2c40;
            --green-tint: #06331b;

            /* G / Y / C in yellow, D / T / M in red. Lifted well clear of the
               plate in dark, because a mid-tone hue on near-black is the first
               thing a projector loses. */
            --score-yellow: #ffc63d;
            --score-yellow-fill: #33280a;
            --score-red: #ff6a5c;
            --score-red-fill: #3a1210;

            --jazzo-fill: #ffd54a;
            --jazzo-line: #b88700;
            --jazzo-text: #241a00;

            /* The board when a contest is decided: the winner's yakhtak,
               deep enough to hold white text at projector brightness. */
            --won-blue-bg: #06344c;
            --won-blue-chrome: #094863;
            --won-green-bg: #043b1f;
            --won-green-chrome: #06512c;
            --won-ink: #ffffff;
            --won-ink-muted: #cfe2ee;
        }

        :root[data-theme="light"] {
            --bg: #eef1f0;
            --chrome: #ffffff;
            --pane: #ffffff;
            --cell: #f2f5f4;
            --cell-dim: #f7f9f8;
            --strip: #e3e8e6;
            --text: #0d1613;
            --muted: #5d6d67;
            --dim: #a8b4af;
            --line: #dbe2df;
            --cell-line: #e2e8e5;
            --flag-fill: repeating-linear-gradient(135deg, #e6ebe9 0 10px, #eef2f0 10px 20px);
            --clock-urgent: #ff4a3a;

            --blue-tint: #d6ecfa;
            --green-tint: #d8f0e1;

            /* Darker steps of the same two hues on a light ground: the yellow
               that reads on black is invisible on white, and the board has to
               work under a daylit projection too. */
            --score-yellow: #8a5c00;
            --score-yellow-fill: #fdf3d9;
            --score-red: #a81828;
            --score-red-fill: #fdeaec;

            --jazzo-fill: #ffd54a;
            --jazzo-line: #a87c00;
            --jazzo-text: #241a00;

            /* Light theme keeps the same two hues at full strength: a winner
               screen that went pastel would stop reading as a result. */
            --won-blue-bg: #0b5b80;
            --won-blue-chrome: #0d6d99;
            --won-green-bg: #06642f;
            --won-green-chrome: #07793a;
            --won-ink: #ffffff;
            --won-ink-muted: #e2eef5;
        }

        :root {
            --blue: #1a9fd8;
            --green: #019a44;
            --gold: #e0a83c;

            /* The clock plate stays light-on-dark in both themes: a clock on a
               light ground loses its punch at thirty metres. */
            --clock-plate: #0d1613;
            --clock-text: #ff5b3c;
        }

        * { box-sizing: border-box; }

        html, body { height: 100%; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: 'Source Sans 3', system-ui, -apple-system, 'Segoe UI', sans-serif;
            overflow: hidden;
        }

        [wire\:loading] { display: none; }
    </style>
</head>
<body>
    {{ $slot }}

    @livewireScripts
</body>
</html>
