# DJ VLIEGTUIG

Webapp die toont welk vliegtuig er nu boven je vliegt (bij Schiphol of elders), met volkslied, regeringsleider en landenfeiten.

- `index.html` – de app
- `proxy.php` – haalt de externe data op via je eigen server (geen CORS-problemen)

Live op https://fodis.nl/fly/. De server haalt elke 5 minuten de nieuwste versie van `main` op via een cronjob (`deploy-fly.php`, buiten `public_html`).
