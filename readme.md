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
- Ingebouwde debugtools voor productafbeeldingen (shortcode, admin testpagina en WP-CLI commando)
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
- Bestandsnamen volgen het patroon `{factuurnummer}-{regelindex}.{ext}` waarbij de regelindex 1-based is (1, 2, 3, …). De sleutel is dus je eigen factuurnummer plus de positie van de orderregel op de pagina.
- Ondersteunde extensies zijn `png`, `jpg`, `jpeg` en `webp` (hoofd-/kleine letters zijn toegestaan). Varianten met `@2x` of `-large` worden automatisch aan `srcset` en `sizes` toegevoegd voor scherpe retina-beelden.
- Zoekvolgorde voor afbeeldingen:
  1. `ABSPATH . 'productimg'`
  2. `$_SERVER['DOCUMENT_ROOT'] . '/productimg'`
  3. `home_path() . 'productimg'`
  De URL van een gevonden bestand wordt altijd opgebouwd met `home_url()` zodat installaties in een submap geen ongewenste `/wp/` prefix krijgen. Je kunt extra paden toevoegen via de filter `rmh_productimg_bases`.
- Vind je meerdere varianten, dan genereert de plugin nette HTML: `<figure class="rmh-orderline-image"><img ... /></figure>` met `loading="lazy"` en `decoding="async"`.
- Wanneer er geen bestand wordt gevonden, valt de weergave terug op legacy bijlagen (mits ingeschakeld) en daarna op de ingebouwde placeholder.
- Resultaten van zoekacties worden gecachet via transients. Met de optionele constanten `RMH_IMG_CACHE_TTL_HIT`, `RMH_IMG_CACHE_TTL_MISS` en `RMH_IMG_CACHE_BUSTER` kun je TTL’s aanpassen en tijdens debuggen cache busten. Extra filters: `rmh_productimg_exts`, `rmh_productimg_enable_autoload` en `rmh_productimg_render_html` voor geavanceerde aanpassingen.

### Debuggen productafbeeldingen
- **Shortcode**: `[rmh_test_image invoice="RMH-0021" index="1"]` toont het pad, de URL en de afbeelding (alleen beschikbaar voor beheerders tenzij je de filter `rmh_enable_debug_shortcodes` activeert).
- **Admin testpagina**: via **Instellingen → RMH Productimg Test** kun je factuurnummer + regelindex invoeren en direct het resultaat zien.
- **WP-CLI**: `wp rmh img-test RMH-0021 1` controleert dezelfde resolver en geeft pad + URL terug. Bij een miss krijg je een waarschuwing en exitcode 1.
- Nieuwe bestanden direct testen? Zet tijdelijk `define('RMH_IMG_CACHE_BUSTER', 'v2');` in `wp-config.php` zodat alle caches worden vernieuwd.

### Configuratie
- `RMH_DISABLE_LEGACY_IMAGES` – schakelt legacy bijlagen als fallback uit.
- `RMH_IMG_CACHE_BUSTER` – voeg een willekeurige string toe om caches direct ongeldig te maken.
- `RMH_IMG_CACHE_TTL_HIT` / `RMH_IMG_CACHE_TTL_MISS` – pas de TTL (in seconden) voor respectievelijk hits en misses aan.

### Cache & tokens
- API-resultaten worden als transient opgeslagen. Actieve orders worden standaard 5 minuten gecachet; afgeronde orders blijven 24 uur warm voor snelle laadtijden.
- Tokens verversen automatisch via een cron-taak. Je hoeft ze dus niet handmatig te vernieuwen.

## Vereisten
- WordPress 6.0 of hoger
- PHP 7.4 of hoger

## License
GPL-2.0+ (zie LICENSE-bestand in deze repository)
