# Thales

Onderdelengebruik per motormerk en -type, opgebouwd uit de servicerapporten (Excel) in SharePoint.

Productie: https://sleutels.kvt.nl/thales

## Wat doet Thales

Elke nacht leest Thales de servicerapporten (Excel) uit SharePoint (`<eq>_<omschr>/101_SERV_REP_EXCEL/`) en bouwt per motormerk + type een lijst van gebruikte BC-artikelen: hoe vaak, totaal aantal en de bronrapporten. Alleen rapporten met taakcode **CO** of **GOH** tellen mee.

- **Bibliotheek**: merk → type → onderdelen → bronrapporten.
- **Test**: componentnummer → merk/type (bij een SET de motor) → fuzzy zoeken; eerst artikelen die op dit merk/type gebruikt zijn, daarna de rest.

Pagina's lezen alleen de eigen SQLite-cache (`web/data/`, door de app afgeschermd met `.htaccess`). Live calls naar SharePoint en BC (via Mímir) gebeuren alleen in `nightly.php`.

## Structuur

| Bestand | Doel |
|---|---|
| `web/index.php` | UI (Bibliotheek / Test + statusregel) |
| `web/nightly.php` | Nachtrun: Graph-delta, verwerken, Mímir-sync, aggregaten (lock + tijdsbudget, hervatbaar) |
| `web/thales_lib.php` | Opslag, Graph, Mímir, aggregaten en interne lees-API (`thales_brands`, `thales_parts`, `thales_part_sources`, `thales_component_lookup`, `thales_search`, `thales_status`) |
| `web/thales_parser.php` | Bestandsnaam, componenttabel (SET/DMTR/GEN), onderdelen (tab Intern, "Gebruikte onderdelen:") |
| `web/thales_xlsx.php` | Kleine xlsx/xlsm-lezer (ZipArchive + XMLReader) |
| `web/auth_TEMPLATE.php` | Voorbeeld voor `auth.php` (niet in git) |
| `web/tests/thales_test.php` | `php web/tests/thales_test.php [map]`: synthetisch rapport + nep-Graph/Mímir; echte voorbeelden optioneel |

## BC-velden (via Mímir, bevestigd door Ariadne)

- `AppComponentCard`: `No`, `Sub_Entity` (DIESEL ENGINE / GAS ENGINE / GENERATOR; SET = `EPG…` of `GENERATOR SET`), `Manufacturer_Code` (merksleutel), `Manufacturer_Name` (weergave), `Manufacturer_Model` (anders `Description_2`), `Component_No_Parent`. Geen wijzigingsveld: elke nacht volledig in delen (`startswith(No,…)`, max. 5000 per call, delen die vol zitten worden verder gesplitst).
- `AppItems`: `No`, `Description`, `Last_Date_Modified` (delta per datum).
- Motor: DMTR-rij uit het rapport, anders het DIESEL/GAS ENGINE-kind van de SET in BC. Het merk van een SET is de setbouwer en telt niet als motormerk. Zonder BC-merk of -type valt Thales terug op Excel (bron `excel`).
- Merk: `Manufacturer_Code` + vertaaltabel (alleen zekere: INDUSTRIAL/SABRE/DIPERK → PERKINS, LEROY SOM/LEROYSOMER → LEROY SOMER, VOLVO/VOLVO PENT → VOLVO PENTA, CAT MARINE → CAT). ONBEKEND/DIVERS/KVT/leeg = Onbekend. Type: sleutel zonder spaties/punten/streepjes, originele waarde getoond.

## Installatie (Tim)

1. **App-registratie** in Entra ID (bijv. "Thales"): client secret aanmaken, API-permissie *Microsoft Graph → Application → Sites.Selected*, dan **admin consent**.
2. **Site-grant** (als SharePoint-/globale admin), alleen lezen:
   `POST https://graph.microsoft.com/v1.0/sites/{site-id}/permissions` met body `{"roles":["read"],"grantedToIdentities":[{"application":{"id":"<client-id>","displayName":"Thales"}}]}`
   of `Grant-PnPAzureADAppSitePermission -AppId <client-id> -DisplayName Thales -Site <site-url> -Permissions Read`.
3. **auth.php** op de server: kopie van `auth_TEMPLATE.php` met tenant, client-id, secret, Mímir-URL/sleutels en gebruikers. Niet in git.
4. **GitHub-secret** `FTP_REMOTE_DIR` zetten (overige FTP-secrets zoals bij Elpis).
5. **Cron** op de server: `curl -s http://localhost/thales/nightly.php` elke nacht (de eerste nachten vult de backfill zich in delen van `thalesBudgetSeconds`).
6. **Thumbnail**: `web/thumbnail.png` is een placeholder; vervang hem eventueel door een echte.
