# DJ VLIEGTUIG RADIO: overdracht

Zusterproject: DJ Vliegtuig Discovery (`wouter-fodis/dj-vliegtuig-discovery`, fodis.nl/dj-vliegtuig-discovery). Wijzigingen voor Discovery horen niet in deze repo.

Webapp die laat zien welk vliegtuig er nu boven je vliegt (bij Schiphol of een andere Europese luchthaven), en daar muziek en landinfo bij toont. De vliegtuigen zijn de dj: het land van het toestel dat boven je is, bepaalt welk volkslied of welke radiozender er speelt.

Live: https://fodis.nl/fly/ · Repo: `wouter-fodis/vlieg` (publiek, geen geheimen erin).

## Bestanden

| Bestand | Wat |
|---|---|
| `index.html` | De hele app: HTML, CSS en JS in één bestand, geen build-stap. |
| `proxy.php` | Haalt alle externe data op namens de browser (CORS), met een cache en een whitelist van hosts. Bevat ook `?icy=<zender-uuid>` voor de radiotekst. |
| `deploy/deploy-fly.php` | Deployscript. Staat op de server in `/home/fodis/deploy-fly.php`, niet in deze map. |

## Vormgeving

Old-school luchthaven (jaren 60/70), sinds 10 okt 2026. Alles staat in een eigen blok onderaan de `<style>` ("Old-school luchthaven"), dat de oude variabelen overschrijft: walnoten lattenplafond als achtergrond, oranje/roest/mosterd-streep onder de titel, vluchtnummer als **split-flap-bord** in een groen frame (`setFlaps()`: elk teken een tegel die even doorbladert, niet bij `prefers-reduced-motion`), route op een creme bord in een aluminium lijst, koppen als zwarte bewegwijzering, landinfo als creme kaart met terrazzo-spikkels (eigen kleurvariabelen binnen `.country`), radar als groen omlijst torenscherm. Lettertypen: Barlow (Condensed) plus Space Mono voor alles wat van een vertrekbord komt.

**Terug naar het oude ontwerp:** de branch `voor-retro-design` is de versie van daarvoor. Terugzetten = de bestanden van die branch op `main` zetten en pushen.

## Afspraken in de code

- **`index.html` is puur ASCII.** Speciale tekens staan als `\uXXXX` in JS of als `&#NNN;` in HTML. Reden: de server/editor verminkte UTF-8 (pijl werd `&#8594;`, puntje werd `�`). Schrijf je nieuwe tekst met speciale tekens, zet die dan om voordat je opslaat.
- **Alle UI-tekst gaat via `t('sleutel', {vars})`.** Het woordenboek `I18N` staat bovenin het script, met `nl`, `en` en `de`. Statische HTML gebruikt `data-i18n="sleutel"` en `data-i18n-attr="attr:sleutel"`.
- **Taalkeuze:** opgeslagen keuze, anders de browsertaal (nl/de), anders Engels. Wisselen herlaadt de pagina.
- **Noem een lokale variabele nooit `t`.** Die overschaduwt `t()`. Gebruik `tgt` voor `anthemTarget(...)`.
- Getallen en tijden via `LOCALE`, `dec(n, digits)` en `fmtInt`/`fmtBig`; landnamen via `countryName(iso)` (Intl.DisplayNames).
- Commentaar in de code is in het Nederlands.

## Deploy

1. Push naar `main`.
2. Een cronjob op de DirectAdmin-server (gebruiker `fodis`) draait **elke minuut**:
   `/usr/local/bin/php -q /home/fodis/deploy-fly.php >> /home/fodis/deploy-fly.log 2>&1`
3. Het script vraagt de nieuwste commit op via `github.com/<repo>.git/info/refs`, zonder API-limiet. Is die nieuw, dan haalt het `index.html` en `proxy.php` van precies die commit op via raw.githubusercontent.com en vervangt het ze atomair in `public_html/fly/`. De laatst uitgerolde commit staat in `/home/fodis/.deploy-fly-commit`.
4. Alleen `index.html` en `proxy.php` worden uitgerold. Een nieuw bestand moet dus ook in `$FILES` in het deployscript, en dat script moet je met de hand op de server vervangen.

Let op: fodis.nl is een WordPress-site van het bedrijf. `public_html` in de thuismap is een symlink naar `domains/fodis.nl/public_html`.

## Databronnen (allemaal via `proxy.php`, behalve audio en afbeeldingen)

| Bron | Waarvoor | Cache |
|---|---|---|
| adsb.lol, airplanes.live (reserve) | Vliegtuigposities, elke 2 s | 1 s |
| adsbdb.com, daarna hexdb.io | Route per vluchtnummer (ook zonder voorloopnullen: AMX025 → AMX25) | 1 dag |
| Wikidata SPARQL | Regeringsleider + portret, democratie-index (EIU, via de eigenschap op Q326174), landenfeiten, bekende bands | 1 dag |
| Wereldbank-API | Inwoners, bbp, levensverwachting, internetgebruik | 1 dag |
| Frankfurter | Wisselkoersen (bedragen in euro) | 1 uur |
| The Economist big-mac-data (GitHub CSV) | Big Mac-index | 1 dag |
| Apple Music RSS (`rss.marketingtools.apple.com`) | Top 5 per land | 1 uur |
| radio-browser.info (de1/at1/nl1) | Radiozenders per land en genre | 1 dag |
| Nominatim (OSM) | Plaatsnaam bij je locatie, zoeken | 1 dag |
| Overpass (OSM) | Plaatsnamen op de radar | 1 dag |
| Wikimedia Commons | Volksliederen (audio rechtstreeks in de browser), portretten | n.v.t. |
| OurAirports (publiek domein) | Luchthavens en baankoppen, ingebakken in `AIRPORTS`/`RWYS` | n.v.t. |

**Afgewezen of verwijderd:** Our World in Data (blokkeert de server, 502), Open-Meteo (gratis versie alleen niet-commercieel), Schiphol Flight API (alleen voor passagiers), REST Countries (sleutel nodig). De tabbladen Kunst en Auto's zijn verwijderd omdat ze slecht werkten. "Best verkochte auto" en "langst op nummer 1" zijn bewust weggelaten, want daar is geen live bron voor.

## Hoe het werkt (kern)

- **Boven je:** het toestel dat binnen `LOOKAHEAD_S` (40 s) je straal in komt, berekend met doorgerekende positie, snelheid en richting (dichtstbijzijnd punt). Het huidige toestel blijft staan, tenzij een ander minstens 8 s eerder boven je is.
- **Straal:** 1 km bij een baankop, 3 km bij Mijn locatie of Zelf invoeren.
- **Plekken:** 256 grote Europese luchthavens (OurAirports `large_airport` met lijnvluchten). Per baankop een punt 100 m voorbij de kop, op het verlengde van de middenlijn. Schiphol houdt zijn baannamen (Polderbaan enz.); standaardplek is `polderbaan-18r`.
- **Land van een toestel** (`anthemTarget`): bij een vertrekker de bestemming; bij een lander (bestemming binnen 60 km van je plek) het land van herkomst.
- **Geluid (de mixer):** maximaal 3 landen tegelijk, volume naar afstand. De dj (het laatste toestel dat binnen je straal was) blijft op minstens `HOLD_LEVEL` (80%) spelen tot er een nieuw toestel binnen je straal komt. Andere toestellen klinken zacht mee in de ruimte die overblijft. **Overgang:** is het dj-toestel uit je straal en nadert een nieuw toestel (tussen 1,6× en 1× de straal), dan zakt de dj geleidelijk van 80% naar 50% en komt het nieuwe land op van 0 naar 50%; bij binnenkomst neemt het nieuwe land over. Volumes veranderen hooguit 20% per seconde (radio, `setLevel`) of met een tijdconstante van 0,8 s (volksliederen).
  - **Volksliederen:** via Web Audio, met fades, ook op iOS.
  - **Radio:** gewone audio-elementen; zenderstreams staan geen Web Audio toe. Fades via `audio.volume`. iOS negeert volume, dus daar speelt alleen het dichtstbijzijnde land.
  - **Genres** via de tags van radio-browser. Is er geen (werkende) genrezender, dan speelt de populairste, met een melding. Bij "Populairste zender" gaat de publieke jongerenzender voor (`RADIO_HINT`: 3FM, Trójka, NRK P3…).
  - **Speellijst:** met een afspeelknop kies je zelf een zender en staat de dj uit; "Terug naar live" zet hem weer aan.
- **Menu** bovenin: RADIO en DISCOVER (/dj-vliegtuig-discovery/), met slogan per taal (`tagRadio`, `tagDiscover`).
- **Delen** (`shareLink`, `applyShared`): knop "Deel" maakt een link met `spot=<baankop-id>` of `at=lat,lon` (~100 m) plus `name`, `r`, `mode` (radio/anthem) en `genre`. Een gedeelde link gaat voor op opgeslagen instellingen. **WhatsApp-knop** (`shareWa`) opent `wa.me/?text=` met `shareMessage()`: plek (bij Mijn locatie de plaatsnaam uit `herePlace`) en straal, radio + genre of volksliederen, een vast gekozen zender, en de laatste 8 regels uit de speellijst (tijd, vlag, nummer of zender). De gewone Deel-knop stuurt dezelfde tekst mee in het deelmenu.
- **Banen op de radar:** elke baankop is een stip met de kopnaam en is klikbaar (`radarHits`, klik → `selectSpot`). **In gebruik** (`trackRunways`, elke seconde): kwam er de laatste 10 minuten een toestel onder 2500 ft binnen 1,5 km over de kop, in de lijn van de baan, dan is de stip geel en staat er "in gebruik" achter de baan in de keuzelijst. Vertrekkers die over de andere kop klimmen tellen ook mee voor die kop.
- **Zoom op de radar:** `viewTarget`/`viewKm` (straal in km, 2 tot 30, standaard 8 NM ~ 15 km, `localStorage` `flyZoom`). Knoppen +/−, scrollwiel, knijpen met twee vingers (canvas `touch-action: pan-y`, zodat de pagina nog scrolt). Zoom glijdt zacht. De ophaalstraal (`fetchNm()`) groeit mee boven 8 NM; Overpass zoekt dan 32 km in plaats van 16. Sporen staan in km, zodat ze na zoomen kloppen. Boven 16 km: geen labels bij baankoppen en andere toestellen (alleen bij het toestel boven je).
- **Layout:** linkerkolom vlucht, radar, landinfo (radar boven de landinfo).
- **Groet:** bij vertrek "Doei allemaal, goede vlucht!" in de taal van de bestemming (`GREET`, 41 talen). Bij landing "Welkom in <plaats>!" in de taal van het vliegtuig, plus een zin over het land van de luchthaven (`HOSTS`, 44 landen) in de taal van de app. Voorlezen via de spraak van de browser.

## Testen

Er is geen testsuite in de repo. Tijdens de bouw is getest met Playwright (Python, Chromium staat in de werkomgeving), met nagemaakte antwoorden voor `proxy.php?url=...` en `?icy=`. Scenario's die het waard zijn om opnieuw te maken:
- een toestel dat wegvliegt (de dj blijft op 80%) en een overname door een nieuw toestel;
- een genre zonder zender (terugval naar de populairste);
- een zelf gekozen zender en "Terug naar live";
- de drie talen en een Franse browser (wordt Engels);
- de Up next/Now playing-labels.

## Openstaande ideeën

- **Serverbelasting** (niet gedaan):
  - één gedeelde vluchtcache per gebied in plaats van per gebruikerslocatie;
  - pauzeren als het tabblad niet zichtbaar is;
  - de cachebestanden in de tijdelijke map opruimen.
  - Grens nu grofweg: tientallen gelijktijdige gebruikers, dan gaan de vluchtbronnen afremmen.
- **Welkomstzin:** de zin over het land staat nu in de taal van de app. Volledig in de taal van het vliegtuig zou 44 × 41 vertalingen vragen.
- **Lijst luchthavens:** eventueel inperken tot de drukste (bijv. top 50 op passagiers).
