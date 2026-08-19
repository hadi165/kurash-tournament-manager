{{-- Shell for the live mat scoreboard.

     Related to the venue display shell but not the same thing. Those pages are
     cached and reload themselves with a meta refresh, which is right for a
     bracket a hall reads. This one carries Livewire, because a scoreboard has
     to change within a second of a call rather than within ten. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('Scoreboard') }}</title>

    @livewireStyles

    <style>
        :root {
            --bg: #07090f;
            --panel: #11151f;
            --line: #222839;
            --text: #f4f6fb;
            --muted: #7d8aa8;
            --blue: #2f6fe0;
            --blue-soft: #10203c;
            --green: #2f9e4f;
            --green-soft: #0e2417;
            --gold: #e0a83c;
        }

        * { box-sizing: border-box; }

        html, body { height: 100%; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            /* Everything below is sized in vw/vh: the same page has to read on
               a 15in laptop at the scorers' table and a projector at the end of
               a hall, and neither should need its own stylesheet. */
            font-size: clamp(16px, 1.15vw, 26px);
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
