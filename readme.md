# Print.com Order Tracker

Een WordPress plugin die per ordernummer automatisch een **track & trace pagina** aanmaakt.  
De plugin toont live orderstatus, items en verzendinformatie via de Print.com API.  
Tokens worden automatisch vernieuwd. Ontworpen om goed samen te werken met **Divi**.

## Installatie
1. Upload de plugin of installeer via [WPPusher](https://wppusher.com).
2. Activeer de plugin via **Plugins → Geïnstalleerde plugins**.
3. Ga naar **Instellingen → Print.com Orders** en vul je API-gegevens in.
4. Voeg een ordernummer toe via het menu **Print.com Orders**.
   De plugin maakt automatisch een pagina aan met de shortcode:  [print_order_status order=“123456789”]
5. Plaats de shortcode [print_order_lookup] om klanten te laten zoeken op ordernummer en postcode.

## Features
- Automatisch pagina’s aanmaken/bijwerken per ordernummer
- Orderstatus, producten en Track & Trace links tonen
- Optionele betaallink-knop via Invoice Ninja uitnodigingen
- Zoekformulier op ordernummer + postcode via shortcode [print_order_lookup]
- Cache instelbaar (default 30 minuten)
- Ondersteuning voor eigen afbeelding per orderpagina
- Automatische productafbeeldingen vanuit `/productimg/` op basis van factuurnummer en regelnummers (met fallback op legacy bijlagen en placeholders)
- Tokens worden automatisch vernieuwd

## Gebruik

### Shortcodes
- `[print_order_status order="RMH-12345"]` – Toont de volledige statuspagina voor het opgegeven (eigen) ordernummer. De pagina wordt standaard door de plugin aangemaakt en beveiligt de inhoud met een token in de URL.
- `[print_order_lookup]` – Geeft een zoekformulier waarmee klanten op ordernummer + postcode zoeken. Bij een match worden ze automatisch doorgestuurd naar de juiste statuspagina.

### Orderpagina’s
- Nieuwe pagina’s worden onder `/bestellingen/` geplaatst en automatisch ingesteld op het Divi full-width template zonder zijbalk.
- In het metabox **Productfoto’s (per item)** kun je per orderItemNumber een eigen afbeelding koppelen die op de statuspagina verschijnt.
- Verwijder je een order, dan wist de plugin ook de gekoppelde pagina, cache en statusgegevens.

### Productafbeeldingen
- De plugin zoekt automatisch naar bestandsnamen in het formaat `{factuurnummer}-{regelindex}.{ext}` waarbij de regelindex 1-based is (dus de eerste regel gebruikt `-1`).
- Ondersteunde extensies zijn `png`, `jpg`, `jpeg` en `webp` (hoofd-/kleine letters zijn toegestaan). Varianten met `@2x` of `-large` worden automatisch aan `srcset` en `sizes` toegevoegd voor scherpe retina-beelden.
- Zoekvolgorde voor afbeeldingen:
  1. `ABSPATH . 'productimg'`
  2. `$_SERVER['DOCUMENT_ROOT'] . '/productimg'`
  3. `home_path() . 'productimg'`
  De eerste leesbare match wordt gebruikt; paden kunnen worden uitgebreid via de filter `rmh_productimg_bases`.
- Vind je meerdere varianten, dan genereert de plugin nette HTML: `<figure class="rmh-orderline-image"><img ... /></figure>` met `loading="lazy"` en `decoding="async"`.
- Wanneer er geen bestand wordt gevonden, valt de weergave terug op legacy bijlagen en daarna op de ingebouwde placeholder, tenzij legacy expliciet is uitgeschakeld.
- Legacy fallback uitschakelen kan via de optie **Legacy afbeeldingkoppeling uitschakelen** op de instellingenpagina of door `define('RMH_DISABLE_LEGACY_IMAGES', true);` in `wp-config.php` te plaatsen (de constante heeft voorrang).
- Resultaten van zoekacties worden 15 minuten gecachet in transients om schijf-hits te beperken. Extra filters: `rmh_productimg_exts`, `rmh_productimg_enable_autoload` en `rmh_productimg_render_html` voor geavanceerde aanpassingen.

### Cache & tokens
- API-resultaten worden als transient opgeslagen. Actieve orders worden standaard 5 minuten gecachet; afgeronde orders blijven 24 uur warm voor snelle laadtijden.
- Tokens verversen automatisch via een cron-taak. Je hoeft ze dus niet handmatig te vernieuwen.

## Vereisten
- WordPress 6.0 of hoger
- PHP 7.4 of hoger

## License
GPL-2.0+ (zie LICENSE-bestand in deze repository)
