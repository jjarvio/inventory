# Inventory 2.0

**Snipe-IT ↔ WooCommerce -integraatio cron-ajona**

Inventory 2.0 on PHP-pohjainen integraatio, joka synkronoi Snipe-IT:n ja
WooCommercen dataa turvallisesti cron-ajojen kautta.

-   **Cron B**: WooCommerce-tilaukset → vähennetään Snipe-IT-varastoa\
-   **Cron C**: Snipe-IT consumables → päivitetään WooCommerce-tuotteet

------------------------------------------------------------------------

## Mitä tämä projekti on (ja ei ole)

-   Tämä repo on ensisijaisesti **CLI-ajoihin tarkoitettu
    cron-integraatio**
-   Skriptit on suojattu web-ajolta (`php_sapi_name() !== 'cli'`)
-   Repossa on lisäksi **erillinen WP Admin -monitorointiplugin**
    (`wp-plugin/inventory2-monitor`), jolla voi:
    -   katsoa lokihistoriaa
    -   ajaa Cron B/C käsin
    -   tyhjentää lokit

------------------------------------------------------------------------

## Repositorion rakenne

    bootstrap.php
    cron_b_orders_to_snipe.php
    cron_c_consumables_sync.php
    wp-plugin/
    └── inventory2-monitor/
        └── inventory2-monitor.php
    README.md

------------------------------------------------------------------------

## Cron B (WooCommerce → Snipe-IT)

### Mitä tekee

1.  Hakee WooCommerce-tilaukset (`processing`, `completed`)
2.  Tunnistaa Snipe-consumablet SKU-muodosta `snipe-consumable-<id>`
3.  Checkouttaa määrät Snipe-IT:hen
4.  Merkitsee tilauksen synkatuksi (`_snipe_synced=yes`)
5.  Estää rinnakkaiset ajot tiedostolukolla (`cron_b_orders.lock`)

### Tavoite

-  Samaa tilausta ei käsitellä kahdesti
-  Jos Snipe checkout palauttaa 5xx-virheen, kyseinen tilaus merkitään manuaalitarkistukseen (`_snipe_sync_manual_review=yes`) eikä sitä yritetä automaattisesti uudelleen
-  Samassa ajossa loppujen tilausten käsittely keskeytetään (Snipe outage -suoja), jotta massana ei synny turhia manuaalitarkistuksia

------------------------------------------------------------------------

## Cron C (Snipe-IT → WooCommerce)

### Myytävien tuotteiden valinta

Cron C käyttää **supplier-pohjaista suodatusta**:

-   Snipe-IT haussa käytetään `search_fields=supplier.name`
-   `search=Myynnissä`
-   Lisäksi varmistetaan rivitasolla, että supplier vastaa
    `SALE_SUPPLIER_NAME`-arvoa

### Tuotteen elinkaari WooCommercessa

-   Uusi tuote luodaan private/hidden-tilaan
-   Jos saldo = 0 → piilotetaan
-   Jos saldo \> 0 ja tuote on ollut aiemmin julkaistu → voidaan
    julkaista automaattisesti

### Lisälogiikka

-   Cron C pitää listaa aktiivisista Snipe-ID:istä
-   Piilottaa Woo-tuotteet, joita ei enää löydy aktiivisesta
    supplier-listasta (`hide_products_not_in_active_list`)

------------------------------------------------------------------------

## Ympäristömuuttujat (.env)

Luo projektihakemistoon `.env`:

``` env
# WooCommerce
WOO_URL=https://kauppa.example.fi
WOO_CONSUMER_KEY=ck_xxx
WOO_CONSUMER_SECRET=cs_xxx

# Snipe-IT
SNIPE_BASE_URL=https://snipe.example.fi  # myös .../api/v1 käy
SNIPE_API_TOKEN=xxxxxxxx

# Yleiset
LOG_PATH=/home/USER/cron/logs
SALE_SUPPLIER_NAME=Myynnissä

# Debug (optional)
CRON_B_DEBUG=false
CRON_C_DEBUG=false
```

`.env` ei kuulu versionhallintaan.

`SNIPE_BASE_URL` voi olla joko palvelimen juuri-URL (suositus) tai API-URL (`.../api/v1`). Skriptit normalisoivat tämän automaattisesti.


------------------------------------------------------------------------

## Ajo

### Käsin testaus

``` bash
php cron_b_orders_to_snipe.php
php cron_c_consumables_sync.php
```

### cPanel / cron esimerkki

``` bash
/usr/local/bin/php /home/USER/cron/cron_b_orders_to_snipe.php
/usr/local/bin/php /home/USER/cron/cron_c_consumables_sync.php
```

### Suositus

-   Cron B: 1--5 min välein\
-   Cron C: 5--15 min välein

------------------------------------------------------------------------

## Lokit

-   Cron B: `LOG_PATH/cron_b_orders.log`
-   Cron C: `LOG_PATH/cron_c_consumables.log`

Tarkista lokit aina ensimmäisten ajojen jälkeen.

------------------------------------------------------------------------

## WordPress monitorointiplugin (optional)

Polku:

    wp-plugin/inventory2-monitor/inventory2-monitor.php

### Ominaisuudet

-   Cron B/C historiat WP Adminissa
-   Virherivien poiminta
-   Napit:
    -   Aja Cron B nyt
    -   Aja Cron C nyt
    -   Tyhjennä lokit nyt
-   Asetukset:
    -   PHP-binääri
    -   cron-scriptien polut
    -   lokipolut
    -   lokien automaattinen tyhjennysväli (päivinä)

### Asennus

1.  Kopioi `wp-plugin/inventory2-monitor` →
    `wp-content/plugins/inventory2-monitor`
2.  Aktivoi plugin WordPressissä
3.  Avaa WP Adminissa **Inventory 2.0**
4.  Aseta oikeat polut ympäristösi mukaan

------------------------------------------------------------------------

## Vaatimukset

-   PHP 8.2+
-   WooCommerce REST API -avaimet
-   Snipe-IT API token
-   Cron-ajojen tuki (cPanel / Unix cron)

------------------------------------------------------------------------

## Tekijä

jjarvio
