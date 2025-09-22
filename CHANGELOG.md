# Changelog

## 2.4.31
- Nieuwe automatische productafbeelding-loader op basis van factuurnummer + orderregelindex (met retina `srcset` ondersteuning en 15-minuten caching).
- Helperfuncties en filters toegevoegd voor paden en extensies (`rmh_productimg_bases`, `rmh_productimg_exts`, `rmh_productimg_enable_autoload`, `rmh_productimg_render_html`).
- Admin-toggle én `RMH_DISABLE_LEGACY_IMAGES`-ondersteuning toegevoegd om legacy bijlagen als fallback uit te schakelen.
- Legacy print.com orderregel-koppeling verwijderd; fallback gebruikt bestaande bijlagen of placeholders wanneer autoload niets vindt.
