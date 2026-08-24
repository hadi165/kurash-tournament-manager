<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Makes the print copies of the flags.
 *
 * Dompdf draws an SVG without establishing a viewport for it, so any artwork
 * that reaches outside its own viewBox is drawn on the page anyway — Kazakhstan
 * at the scale of a whole running order, over the names underneath. Nothing
 * bounds it: overflow, background images and clip paths were each tried.
 *
 * A raster image cannot do that. It is drawn into the box it is given, whatever
 * is in it. So paper gets PNG copies and the screens keep the vectors.
 *
 * Rasterising needs something that can read SVG, which PHP cannot; this borrows
 * the browser already on the machine. It runs when the flags are installed
 * rather than when a document is asked for — `npm run flags` does both halves —
 * so a server serving PDFs never needs a browser on it.
 *
 * Every flag in the set shares a 640×480 viewBox, so this is one grid render
 * and a few hundred crops rather than a few hundred browser launches.
 */
class RasteriseFlags extends Command
{
    protected $signature = 'flags:rasterise
                            {--force : Redraw even where the print copies are current}
                            {--chrome=google-chrome : The browser binary to render with}';

    protected $description = 'Render the flag SVGs to print-safe PNGs';

    /**
     * Four times the size they print at, which is enough for the press and
     * still a couple of kilobytes each.
     */
    private const WIDTH = 64;

    private const HEIGHT = 48;

    /** Wide enough to keep the sheet a sensible shape at any set size. */
    private const COLUMNS = 16;

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->error('The GD extension is needed to cut the sheet up. Install php-gd.');

            return self::FAILURE;
        }

        $flags = array_map(
            fn (string $path) => pathinfo($path, PATHINFO_FILENAME),
            File::glob(public_path('flags/*.svg'))
        );

        // Sorted so the sheet is laid out the same way every time it is made,
        // which keeps a regenerated set to the flags that actually changed.
        sort($flags);

        if ($flags === []) {
            $this->error('No flags found in public/flags.');

            return self::FAILURE;
        }

        $count = count($flags);
        $destination = public_path('flags/print');

        // This runs ahead of every `npm run dev`, and almost every time there
        // is nothing to do: the vectors come from a pinned package and change
        // only when it is upgraded. So it looks first, and a developer without
        // a browser installed is stopped only when there is actual work.
        $signature = $this->vectorSignature($flags);

        if (! $this->option('force') && $this->current($signature, $destination)) {
            $this->components->info("Print flags are current ({$count}).");

            return self::SUCCESS;
        }

        $workspace = storage_path('app/flag-rastering');
        File::ensureDirectoryExists($workspace);

        $sheet = $workspace.'/sheet.png';
        File::put($workspace.'/sheet.html', $this->contactSheet($flags));

        $this->components->task(
            "Rendering {$count} flags",
            fn () => $this->render($workspace.'/sheet.html', $sheet, $count)
        );

        if (! is_file($sheet)) {
            $this->error('The browser produced no sheet. Try --chrome=/path/to/chrome.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($destination);

        $this->components->task(
            'Cutting the sheet up',
            fn () => $this->slice($sheet, $flags, $destination)
        );

        File::put($destination.'/.signature', $signature);
        File::deleteDirectory($workspace);

        $this->components->info("{$count} flags written to public/flags/print.");

        return self::SUCCESS;
    }

    /**
     * What the vectors currently are, as one line.
     *
     * Name and size rather than modification time: the copy step rewrites every
     * timestamp each time it runs, so times would say everything had changed
     * whenever nothing had. The set comes from a pinned package, and an upgrade
     * that alters a flag alters its length.
     *
     * @param  list<string>  $flags
     */
    private function vectorSignature(array $flags): string
    {
        $lines = array_map(
            fn (string $iso) => $iso.':'.filesize(public_path("flags/{$iso}.svg")),
            $flags
        );

        return sha1(implode("\n", $lines));
    }

    /** Whether a complete set was already cut from exactly these vectors. */
    private function current(string $signature, string $destination): bool
    {
        $manifest = $destination.'/.signature';

        return is_file($manifest) && trim(File::get($manifest)) === $signature;
    }

    /**
     * One page holding every flag on a fixed grid, so each one lands on a cell
     * whose corner is arithmetic rather than something to measure afterwards.
     *
     * The border is drawn here, at four times the size it prints at, so a flag
     * with a white edge still reads as a flag on white paper. `border-box`
     * keeps it inside the cell rather than adding to it.
     *
     * @param  list<string>  $flags
     */
    private function contactSheet(array $flags): string
    {
        $cells = '';

        foreach ($flags as $index => $iso) {
            $left = ($index % self::COLUMNS) * self::WIDTH;
            $top = intdiv($index, self::COLUMNS) * self::HEIGHT;

            // Encoded a segment at a time: the project can sit under a path
            // with spaces in it, and a file: URL will not carry those raw.
            $url = 'file://'.implode('/', array_map(
                rawurlencode(...),
                explode('/', public_path("flags/{$iso}.svg"))
            ));

            $cells .= sprintf(
                '<img src="%s" style="left:%dpx;top:%dpx">',
                $url,
                $left,
                $top
            );
        }

        $width = self::WIDTH;
        $height = self::HEIGHT;

        return <<<HTML
            <!doctype html>
            <meta charset="utf-8">
            <style>
                html, body { margin: 0; padding: 0; background: transparent; }
                img {
                    position: absolute;
                    width: {$width}px;
                    height: {$height}px;
                    box-sizing: border-box;
                    border: 2px solid #cfd8d4;
                }
            </style>
            {$cells}
            HTML;
    }

    /** @return bool Whether the browser wrote a sheet. */
    private function render(string $page, string $sheet, int $count): bool
    {
        $rows = (int) ceil($count / self::COLUMNS);

        $process = new Process([
            (string) $this->option('chrome'),
            '--headless',
            '--disable-gpu',
            '--no-sandbox',
            '--hide-scrollbars',
            // Transparent, so a flag that is not a full rectangle keeps its
            // shape instead of gaining a white one.
            '--default-background-color=00000000',
            '--screenshot='.$sheet,
            sprintf('--window-size=%d,%d', self::COLUMNS * self::WIDTH, $rows * self::HEIGHT),
            $page,
        ]);

        $process->setTimeout(120)->run();

        return is_file($sheet);
    }

    /**
     * @param  list<string>  $flags
     */
    private function slice(string $sheet, array $flags, string $destination): bool
    {
        $source = imagecreatefrompng($sheet);

        if ($source === false) {
            return false;
        }

        foreach ($flags as $index => $iso) {
            $flag = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

            // Kept transparent through the copy rather than blended onto black,
            // so a flag that is not a full rectangle keeps its shape.
            $nothing = imagecolorallocatealpha($flag, 0, 0, 0, 127);

            if ($nothing === false) {
                imagedestroy($flag);
                imagedestroy($source);

                return false;
            }

            imagealphablending($flag, false);
            imagesavealpha($flag, true);
            imagefill($flag, 0, 0, $nothing);

            imagecopy(
                $flag,
                $source,
                0,
                0,
                ($index % self::COLUMNS) * self::WIDTH,
                intdiv($index, self::COLUMNS) * self::HEIGHT,
                self::WIDTH,
                self::HEIGHT
            );

            imagepng($flag, $destination."/{$iso}.png", 9);
            imagedestroy($flag);
        }

        imagedestroy($source);

        return true;
    }
}
