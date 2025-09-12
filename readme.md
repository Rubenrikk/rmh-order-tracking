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

## Features
- Automatisch pagina’s aanmaken/bijwerken per ordernummer  
- Orderstatus, producten en Track & Trace links tonen  
- Cache instelbaar (default 30 minuten)  
- Ondersteuning voor eigen afbeelding per orderpagina  
- Tokens worden automatisch vernieuwd  

## Vereisten
- WordPress 6.0 of hoger  
- PHP 7.4 of hoger  

## License
GPL-2.0+ (zie LICENSE-bestand in deze repository)