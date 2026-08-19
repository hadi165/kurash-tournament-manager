# Federation artwork

Drop the official logo here and it appears across the whole system at once —
the sidebar, the login screen, every venue display and the header of every PDF.
Nothing else needs changing.

| File | Used by | Notes |
| --- | --- | --- |
| `logo.svg` | screens, venue displays | Any square-ish aspect. SVG stays crisp on a projector. |
| `logo.png` | PDF exports | ~200×200 px, transparent background. Dompdf renders PNG far more reliably than SVG. |

Both paths are configurable in `config/branding.php`, or by environment:

```dotenv
BRANDING_ORGANISATION="International Kurash Association"
BRANDING_SHORT_NAME="IKA"
BRANDING_LOGO="images/logo.svg"
BRANDING_LOGO_PRINT="images/logo.png"
```

Until a file is present the system shows a plain typographic monogram. That is
deliberate: this project has no right to invent something that would read as a
federation's emblem, so the placeholder is obviously a placeholder.

## Country flags

Not here — `public/flags/` holds 271 SVGs copied out of the `flag-icons`
package by `npm run flags`, which `npm run build` does automatically. That
directory is generated and is not committed.

Flags are looked up by NOC code through `App\Support\Noc`, which maps the
three-letter IOC codes competitions use onto the two-letter ISO codes the
artwork is filed under. The two do not agree in any derivable way — `BRN` is
Bahrain while Brunei is `BRU` — so it is a table, and it is tested.
