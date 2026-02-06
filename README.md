# Inventory 2.0

**Production-ready Snipe-IT ↔ WooCommerce -integraatio**

Inventory 2.0 on PHP-pohjainen integraatio, joka synkronoi **Snipe-IT**-inventaariojärjestelmän ja **WooCommerce**-verkkokaupan varastosaldot ja tuotteiden näkyvyyden hallitusti ja turvallisesti.

Ratkaisu on suunniteltu *production-käyttöön*: automaatio ei koskaan julkaise keskeneräisiä tuotteita, kaikki muutokset ovat jäljitettävissä, ja synkronointi tapahtuu idempotenteilla cron-ajolla virallisten REST API -rajapintojen kautta.

---

## Keskeiset ominaisuudet

* 🔁 **Kaksisuuntainen synkronointi**

  * WooCommerce-tilaukset vähentävät varastosaldoa Snipe-IT:ssä
  * Snipe-IT:n inventaario päivittää WooCommercen
* 🛑 **Turvallinen julkaisumalli (safe-by-design)**

  * Uusia tuotteita ei koskaan julkaista automaattisesti
  * Ihminen hyväksyy tuotteen kerran, automaatio hoitaa jatkon
* 📦 **Consumables- ja Components-tuki**
* 🧠 **Kategoriapohjainen hallinta** (`-myynnissä`-pääte)
* 🧾 **Idempotentit cron-ajot** (ei kaksoiskäsittelyä)
* 🪵 **Lokitus virheiden selvitykseen ja auditointiin**

---

## Arkkitehtuuri (yleiskuva)

```
WooCommerce ──(tilaukset)──▶ Cron B ──▶ Snipe-IT
     ▲                                   │
     │                                   ▼
     └──────────── Cron C ◀──── inventaario
```

* **Cron B**: WooCommerce → Snipe-IT (tilaukset vähentävät saldoa)
* **Cron C**: Snipe-IT → WooCommerce (inventaario synkataan kauppaan)

Kaikki liikenne kulkee REST API -rajapintojen kautta API-avaimilla.

---

## Repositorion rakenne

Versionhallintaan kuuluu vain varsinainen synkronointilogiikka. Salaisuudet ja ympäristökohtaiset asetukset on eriytetty.

```
cron/
├── bootstrap.php                  # Yhteinen bootstrap (autoload, env, asetukset)
├── .env                           # Ympäristömuuttujat (EI GitHubiin)
├── cron_b_orders_to_snipe.php     # Tilaukset → consumables
├── cron_b_components.php          # Tilaukset → components
├── cron_c_consumables_sync.php    # Inventaario → Woo (consumables)
├── cron_c_components_sync.php     # Inventaario → Woo (components)
├── helpers/                       # Jaetut apufunktiot
└── logs/                          # Ajojen lokit
```

---

## Synkronointilogiikka

### Cron B – WooCommerce → Snipe-IT

Ajetaan ajastetusti käsittelemään WooCommercen valmiit tilaukset.

**Vaiheet:**

1. Haetaan käsittelemättömät tilaukset
2. Käydään tilausrivit läpi
3. SKU-mäppäys → Snipe-IT item
4. Vähennetään varastosaldo:

   * Consumables
   * Components
5. Merkitään tilaus synkatuksi (order meta)

**Takuut:**

* Jokainen tilaus käsitellään vain kerran
* Aiemmin synkatut tilaukset ohitetaan

---

### Cron C – Snipe-IT → WooCommerce

Ajetaan ajastetusti synkronoimaan inventaarion tila verkkokauppaan.

#### Suodatus

* Synkataan vain kategoriat, joiden nimi päättyy `-myynnissä`

#### Tuotteen elinkaari

| Tilanne                              | Toiminta                    |
| ------------------------------------ | --------------------------- |
| Uusi tuote                           | Luodaan piilotettuna        |
| Varastosaldo = 0                     | Piilotetaan automaattisesti |
| Varastosaldo > 0 + aiemmin julkaistu | Julkaistaan automaattisesti |

Tuote voidaan julkaista automaattisesti vasta sen jälkeen, kun se on **kerran julkaistu käsin** (tieto tallennetaan metadataan).

---

## Design-päätökset

### Miksi uusia tuotteita ei julkaista automaattisesti?

Tämä estää:

* Keskeneräisten tuotteiden päätymisen kauppaan
* Virheelliset hinnat
* Puuttuvat kuvat ja kuvaukset

Ratkaisu pakottaa **ihminen mukana -mallin** ensimmäisessä julkaisussa.

---

## Vaatimukset

* PHP **8.2+**
* cPanel (tai vastaava) cron-ajojen tuki
* WooCommerce REST API -avaimet
* Snipe-IT REST API -avaimet

---

## Asennus (nykyinen toteutus)

### 1. Repositorion kloonaus

Kloonaa repo esimerkiksi cPanel-ympäristöön:

```
cd /home/USER/
git clone https://github.com/jjarvio/inventory2.0.git cron
```

---

### 2. `.env`-tiedoston luonti

Luo `cron/.env` ja lisää vähintään seuraavat muuttujat:

```
# WooCommerce
WC_API_URL=https://kauppa.example.fi
WC_CONSUMER_KEY=ck_xxx
WC_CONSUMER_SECRET=cs_xxx

# Snipe-IT
SNIPE_API_URL=https://snipe.example.fi
SNIPE_API_TOKEN=xxxxxxxx

# Yleiset
LOG_PATH=/home/USER/cron/logs
```

⚠️ `.env` **ei kuulu versionhallintaan**.

---

### 3. `bootstrap.php`

Kaikki cron-skriptit lataavat ensin `bootstrap.php`-tiedoston.

Bootstrap vastaa:

* `.env`-muuttujien lataamisesta
* Autoloadista / helperien sisällytyksestä
* Yhteisten asetusten alustamisesta

Esimerkki (yksinkertaistettu):

```
require __DIR__ . '/bootstrap.php';
```

---

### 4. Oikeudet ja hakemistot

Varmista, että:

* `logs/` on kirjoitettava
* Cron-skripteillä on ajo-oikeus

---

### 5. Cron-ajojen lisääminen cPanelissa

Esimerkki:

```
/usr/local/bin/php /home/USER/cron/cron_b_orders_to_snipe.php
/usr/local/bin/php /home/USER/cron/cron_c_consumables_sync.php
```

Suositus:

* Cron B: 1–5 min välein
* Cron C: 5–15 min välein

---

### 6. Testaus

Aja jokainen skripti ensin käsin:

```
php cron_b_orders_to_snipe.php
php cron_c_consumables_sync.php
```

Tarkista `logs/`-hakemisto mahdollisten virheiden varalta ennen tuotantokäyttöä.

---

## Lokitus ja virheenkäsittely

* Jokainen cron tuottaa aikaleimatun lokin
* API-virheet kirjataan
* Ajo on turvallista toistaa (idempotentti)

---

## Projektin tila

🚧 **Aktiivisessa kehityksessä**

Suunnitteilla:

* Tarkempi virheluokittelu
* Webhook-pohjainen tilausten käsittely
* Yksikkötestit
* Docker-kehitysympäristö

---

## Tekijä

**jjarvio**
Inventory 2.0
