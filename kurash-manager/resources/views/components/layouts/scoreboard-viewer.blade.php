{{-- The shell a scoreboard account gets, and the only one it ever gets.

     Chosen server-side by the component's Layout attribute rather than by
     hiding things inside the application shell: the sidebar, the navigation
     and the settings links are not rendered and then concealed — they are
     never rendered at all, so there is nothing in the payload to un-hide. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark" data-layout="scoreboard-viewer">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('Scoreboard') }}</title>

    @livewireStyles
    @vite('resources/css/ceremony.css')

    {{-- The board's own behaviour, including the buzzer a contest ends on.
         This shell carries Livewire but is not the admin shell, so it has to
         ask for this itself — without it the board renders perfectly and
         stays silent, which is the one failure nobody sees coming. --}}
    @vite('resources/js/app.js')


    <style>
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

        * { box-sizing: border-box; }

        html, body { height: 100%; }

        body {
            margin: 0;
            background: #070c12;
            color: #f2f6f4;
            font-family: "Segoe UI", "Segoe UI Variable", "Source Sans 3", system-ui, sans-serif;
            /* The board sizes itself; the page never scrolls sideways on any
               screen this is opened on. */
            overflow-x: hidden;
        }
    </style>
</head>
<body>
    {{ $slot }}

    @livewireScripts
</body>
</html>
