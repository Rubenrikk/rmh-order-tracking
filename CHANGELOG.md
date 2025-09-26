# Changelog

## 2.5.2
- Herstelde de oorspronkelijke adresopmaak per orderregel en dupliceer deze wanneer meerdere zendingen aanwezig zijn.
- Toon aantallen en leverdatums onder elk adresblok zonder extra labels voor de afleveradressen.
- Versienummer verhoogd naar 2.5.2.

## 2.5.1
- Toon meerdere afleveradressen per orderregel met aantallen en leverdatum op één regel.
- Label de adressectie automatisch enkelvoud of meervoud en behoud compatibiliteit met postcode-lookup.
- Versienummer verhoogd naar 2.5.1.

## 2.5.0
- Hardcode Print.com API en login endpoints in de plugin; instellingen voor base/auth/grant verwijderd.
- Authenticatie gebruikt enkel gebruikersnaam en wachtwoord en verwijderde Client ID/Secret opties.
- Legacy automatische productafbeeldingen en bijbehorende debugtools verwijderd; productfoto’s blijven handmatig per orderregel.
- Instellingenpagina opgeschoond en testknoppen gegroepeerd onder “Debug opties”.
- Print.com orders-overzicht toont nu de Invoice Ninja factuurhash en bewaart deze bij nieuwe koppelingen.
- Lookup shortcode verduidelijkt het postcodeveld voor leveradressen en ondersteunt meerdere afleveradressen per bestelling.

## 2.4.34
- Fix: orderpagina toont opnieuw automatische productafbeeldingen met 1-based indexering.
- Fix: URL-base overal via `home_url()` met controle op DOCUMENT_ROOT naar `https://rikkermediahub.com/productimg/`.
- Feat: uitgebreide diagnose in admin inclusief pad/URL per basis, cache-details en cache-buster knop.
- DX: HTML-debugcomment op orderregels bij `WP_DEBUG` voor snelle analyse van hits/misses.
- Safe: extra WordPress-guards voor includes, capabilities en context.

## 2.4.33
- Confirmed compatibility with WordPress 6.8.2 and PHP 8.3.

## 2.4.32
- Fix: URL-base met `home_url()` voor submap-installaties.
- Feat: Debugtools — shortcode `[rmh_test_image]`, admin testpagina en WP-CLI commando `wp rmh img-test`.
- Feat: Cache-buster via `RMH_IMG_CACHE_BUSTER` en configureerbare TTL’s voor hits/misses.
- Chore: Legacy volgorde afgedwongen en print.com-orderregel fallback uitgeschakeld ten gunste van factuurnummer + index.

## 2.4.31
- Nieuwe automatische productafbeelding-loader op basis van factuurnummer + orderregelindex (met retina `srcset` ondersteuning en 15-minuten caching).
- Helperfuncties en filters toegevoegd voor paden en extensies (`rmh_productimg_bases`, `rmh_productimg_exts`, `rmh_productimg_enable_autoload`, `rmh_productimg_render_html`).
- Admin-toggle én `RMH_DISABLE_LEGACY_IMAGES`-ondersteuning toegevoegd om legacy bijlagen als fallback uit te schakelen.
- Legacy print.com orderregel-koppeling verwijderd; fallback gebruikt bestaande bijlagen of placeholders wanneer autoload niets vindt.
