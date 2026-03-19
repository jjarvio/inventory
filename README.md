# Inventory monitor & sync

**Snipe-IT ↔ WooCommerce -integraatio cron-ajona**

Inventory monitor & sync on PHP-pohjainen integraatio, joka synkronoi Snipe-IT:n ja
WooCommercen dataa turvallisesti cron-ajojen kautta.

- **Cron B**: WooCommerce-tilaukset → vähennetään Snipe-IT-varastoa
- **Cron C**: Snipe-IT consumables → päivitetään WooCommerce-tuotteet

---

## Repositorion rakenne

```text
bootstrap.php
cron_b_orders_to_snipe.php
cron_c_consumables_sync.php
webhook_order.php
wp-plugin/
└── inventory-monitor/
    └── inventory-monitor.php
README.md
```

---

## Cron B (WooCommerce → Snipe-IT)

### Mitä tekee

1. Hakee WooCommerce-tilaukset tiloista `processing` ja `completed`
2. Tunnistaa Snipe-consumablet SKU-muodosta `snipe-consumable-<id>`
3. Checkouttaa määrät Snipe-IT:hen
4. Merkitsee tilauksen synkatuksi (`_snipe_synced=yes`)
5. Estää rinnakkaiset ajot tiedostolukolla (`cron_b_orders.lock`)

### Tavoite

- Sama tilaus ei käsitellä kahdesti
- Snipe-IT:n saldo vähenee tuotteen jäljellä olevasta määrästä
- Tilaukset, joissa on ongelmia, voidaan merkitä manuaalista tarkastusta varten

---

## Cron C (Snipe-IT → WooCommerce)

### Myytävien tuotteiden valinta

Cron C käyttää **supplier-pohjaista suodatusta**:

- Snipe-IT haussa käytetään `search_fields=supplier.name`
- Hakusanana käytetään supplierin nimeä, joka vastaa `.env`-tiedoston arvoa `SALE_SUPPLIER_NAME`
- Lisäksi jokainen rivi varmistetaan vielä rivitasolla supplier-nimen perusteella

### Tuotteen elinkaari WooCommercessa

- Uusi tuote luodaan ensin `private` / `hidden`-tilaan
- Jos saldo = 0 → tuote piilotetaan
- Jos saldo > 0 ja tuote on ollut aiemmin julkaistu → tuote voidaan julkaista automaattisesti
- Tuotteet, joita ei enää löydy aktiivisesta Snipe-IT-listasta, piilotetaan automaattisesti

---

## Vaatimukset

- PHP 8.2+
- PHP CLI käytettävissä cron-ajossa
- WooCommerce REST API -avaimet
- Snipe-IT API token
- Snipe-IT:ssä vähintään yksi location, jota checkout käyttää
- Cron-ajojen tuki (cPanel / Unix cron)
- SSH- tai muu palvelinpääsy, jolla voit luoda `.env`-tiedoston ja cron-ajot

---

## Asennus tilanteessa, jossa Snipe-IT ja WooCommerce ovat jo valmiiksi asennettuina

Tämä osio olettaa, että:

- WooCommerce-verkkokauppa toimii jo normaalisti
- Snipe-IT on jo asennettu ja käytössä
- Haluat ottaa käyttöön vain tämän integraation cron-automaatioineen

### 1. Kopioi projektin tiedostot palvelimelle

Kirjaudu palvelimelle SSH:lla ja luo erillinen hakemisto cron-skripteille. Suositus on pitää ne **public_html**-hakemiston ulkopuolella.

```bash
mkdir -p ~/cron
mkdir -p ~/cron/logs
chmod 755 ~/cron/logs
```

Kopioi tämän repon tiedostot hakemistoon `~/cron`.

Suositeltu lopputulos:

```text
/home/USER/cron/
├── bootstrap.php
├── cron_b_orders_to_snipe.php
├── cron_c_consumables_sync.php
├── webhook_order.php
├── .env
└── logs/
```

> varmista, että skriptit ajetaan samasta hakemistosta, jossa myös `.env` sijaitsee.

### 2. Luo `.env`-tiedosto

Luo projektihakemistoon tiedosto `.env`:

```bash
nano ~/cron/.env
```

Lisää sisältö esimerkiksi näin:

```env
# WooCommerce
WOO_URL=https://kauppa.example.fi
WOO_CONSUMER_KEY=ck_xxx
WOO_CONSUMER_SECRET=cs_xxx

# Snipe-IT
SNIPE_BASE_URL=https://snipe.example.fi
SNIPE_API_TOKEN=xxxxxxxx
SNIPE_LOCATION_ID=
SNIPE_SOLD_ASSET_ID=

# Yleiset
LOG_PATH=/home/USER/cron/inventory2.0/logs
SALE_SUPPLIER_NAME=Myynnissä

# Debug (optional)
CRON_B_DEBUG=false
CRON_C_DEBUG=false
```

### 3. Luo WooCommerce REST API -avaimet

Cron B ja Cron C käyttävät WooCommercen REST APIa tilausten, tuotteiden ja kategorioiden lukemiseen sekä päivittämiseen.

#### WooCommerce-adminissa

1. Kirjaudu WordPress / WooCommerce -hallintaan admin-käyttäjällä.
2. Avaa **WooCommerce → Settings → Advanced → REST API**.
3. Valitse **Add key**.
4. Täytä esimerkiksi:
   - **Description**: `Inventory2 Cron Sync`
   - **User**: admin-käyttäjä tai tekninen integraatiokäyttäjä
   - **Permissions**: `Read/Write`
5. Luo avain.
6. Liitä nämä .env tiedostotoon niille kuuluville paikoille:
   - `Consumer key` → käytetään muuttujassa `WOO_CONSUMER_KEY`
   - `Consumer secret` → käytetään muuttujassa `WOO_CONSUMER_SECRET`

#### Mitä oikeuksia tarvitaan

- **Cron B** tarvitsee tilausten lukemisen ja tilausten metadatan päivittämisen
- **Cron C** tarvitsee tuotteiden, kategorioiden ja tuotetietojen luomisen / päivittämisen
- Siksi oikeustason tulee olla **Read/Write**

### 3. Luo Snipe-IT API token

Cron B ja Cron C käyttävät Snipe-IT APIa consumable-tuotteiden hakemiseen ja checkout-toimintoihin.

#### Snipe-IT-hallinnassa

1. Kirjaudu Snipe-IT:hen käyttäjällä, jolla on oikeudet käyttää APIa.
2. Avaa oikeasta yläkulmasta käyttäjävalikko.
3. Mene kohtaan **Manage API Keys** tai vastaava API-avainten hallintasivu.
4. Luo uusi API key / token integraatiota varten.
5. Anna avaimeen selkeä nimi, esimerkiksi `inventory2-cron`.
6. Kopioi token talteen.

Tämä arvo tallennetaan `.env`-tiedostoon muuttujaan:

```env
SNIPE_API_TOKEN=xxxxxxxx
```



### 4. Varmista supplier-nimi tuotteille, joiden halutaan näkyvän WooCommercessa

Cron C tuo WooCommerceen vain ne Snipe-IT consumablet, joiden supplier vastaa `.env`-tiedoston arvoa `SALE_SUPPLIER_NAME`.

Esimerkki:

```env
SALE_SUPPLIER_NAME=Myynnissä
```

Käytännössä tämä tarkoittaa:

- Snipe-IT:ssä jokaisella myyntiin tarkoitetulla consumablella pitää olla supplier
- Supplierin nimen pitää olla täsmälleen sama kuin `.env`-tiedostossa
- Jos supplier vaihtuu tai tuotetta ei enää löydy hausta, Cron C voi piilottaa tuotteen WooCommercessa



#### Muuttujien selitykset

- `WOO_URL` = WooCommerce-sivuston julkinen URL ilman loppupään kauttaviivaa
- `WOO_CONSUMER_KEY` = WooCommerce REST API consumer key
- `WOO_CONSUMER_SECRET` = WooCommerce REST API consumer secret
- `SNIPE_BASE_URL` = Snipe-IT:n perus-URL
- `SNIPE_API_TOKEN` = Snipe-IT API token
- `SNIPE_LOCATION_ID` = location ID, johon Cron B tekee checkoutin
- `LOG_PATH` = hakemisto, johon lokit ja lukitustiedosto kirjoitetaan
- `SALE_SUPPLIER_NAME` = supplierin nimi, jolla myytävät tuotteet tunnistetaan
- `CRON_B_DEBUG` / `CRON_C_DEBUG` = `true` tai `false`, kirjoitetaanko debug-lokia

#### Tärkeät huomiot

- `.env` ei kuulu versionhallintaan
- `SNIPE_BASE_URL` voi olla joko juuritason URL tai `.../api/v1`; skriptit normalisoivat sen automaattisesti
- `LOG_PATH`-hakemiston on oltava kirjoitettavissa sillä käyttäjällä, jolla cron ajetaan
- `.env`-tiedoston on sijaittava samassa hakemistossa kuin `bootstrap.php` ja cron-skriptit

### 5. Tarkista PHP CLI -polku

Selvitä, mikä PHP-binääri palvelimellasi on käytössä:

```bash
which php
php -v
```

Yleisiä esimerkkejä:

- `/usr/bin/php`
- `/usr/local/bin/php`
- cPanel-ympäristössä joskus versionhallittu polku, kuten `/opt/cpanel/ea-php82/root/usr/bin/php`

Käytä samaa polkua cron-ajastuksessa.

### 6. Testaa yhteydet manuaalisesti ennen cronin aktivointia

Suorita ensin skriptit käsin projektihakemistosta:

```bash
cd ~/cron
php cron_b_orders_to_snipe.php
php cron_c_consumables_sync.php
```

Jos haluat enemmän lokitietoa ensimmäisiin testiajoihin, muuta `.env`-tiedostoon:

```env
CRON_B_DEBUG=true
CRON_C_DEBUG=true
```

Kun testaus on valmis, voit palauttaa arvot takaisin `false`-tilaan.

### 7. Lisää cron-ajot

#### Esimerkki perinteiseen crontabiin

Avaa käyttäjän crontab:

```bash
crontab -e
```

Lisää esimerkiksi:

```cron
*/5 * * * * /usr/local/bin/php /home/USER/cron/cron_b_orders_to_snipe.php >> /home/USER/cron/inventory2.0/logs/cron_b_runner.log 2>&1
*/10 * * * * /usr/local/bin/php /home/USER/cron/cron_c_consumables_sync.php >> /home/USER/cron/inventory2.0/logs/cron_c_runner.log 2>&1
```

#### Esimerkki cPanel Cron Jobs -näkymään

- **Cron B** komento:

```bash
/usr/local/bin/php /home/USER/cron/cron_b_orders_to_snipe.php >> /home/USER/cron/logs/cron_b_runner.log 2>&1
```

- **Cron C** komento:

```bash
/usr/local/bin/php /home/USER/cron/cron_c_consumables_sync.php >> /home/USER/cron/logs/cron_c_runner.log 2>&1
```

#### Suositellut ajovälit

- **Cron B**: 1–5 minuutin välein, jos tilausten synkronoinnin pitää tapahtua nopeasti
- **Cron C**: 5–15 minuutin välein, koska tämä ajo päivittää tuote- ja saldotietoja

---

## Manuaalinen testaus: miten varmistat, että skriptit toimivat

Tässä kohdassa oletetaan, että `.env` on jo kunnossa ja skriptit löytyvät palvelimelta.

### Testi 1: perusajo ilman cron-ajastusta

Aja skriptit käsin:

```bash
cd ~/cron
php cron_b_orders_to_snipe.php
php cron_c_consumables_sync.php
```

Tarkista, että komennot päättyvät ilman PHP fatal error -virheitä.

### Testi 2: tarkista, että lokit syntyvät

```bash
tail -n 50 ~/cron/logs/cron_b_orders.log
tail -n 50 ~/cron/logs/cron_c_consumables.log
```

Odotettu tulos:

- Lokitiedostot syntyvät
- Lokissa näkyy vähintään käynnistys- ja käsittelyrivejä
- Mahdolliset API- tai tunnistautumisvirheet näkyvät lokissa heti

### Testi 3: testaa Cron B oikealla WooCommerce-tilauksella

1. Luo verkkokauppaan testitilaus tuotteelle, jonka SKU on muodossa:

```text
snipe-consumable-123
```

2. Varmista, että tilaus on tilassa `processing` tai `completed`.
3. Aja Cron B käsin:

```bash
cd ~/cron
php cron_b_orders_to_snipe.php
```

4. Tarkista tämän jälkeen:
   - Snipe-IT:ssä kyseisen consumablen saldo pieneni
   - WooCommerce-tilauksen metadataan lisättiin `_snipe_synced=yes`
   - Lokissa ei ole checkout-virheitä

Jos ajo epäonnistuu, tarkista erityisesti:

- onko SKU varmasti muodossa `snipe-consumable-ID`
- onko `SNIPE_LOCATION_ID` oikea
- riittävätkö Snipe-IT API-oikeudet checkoutiin

### Testi 4: varmista, ettei sama tilaus käsittele itseään uudelleen

Aja Cron B toisen kerran heti perään:

```bash
cd ~/cron
php cron_b_orders_to_snipe.php
```

Odotettu tulos:

- Sama jo synkattu tilaus ei enää tee uutta vähennystä Snipe-IT:hen
- Lokissa näkyy, että jo käsitellyt tilaukset ohitetaan

### Testi 5: testaa Cron C yhdellä myytävällä Snipe-IT-tuotteella

1. Valitse Snipe-IT:stä consumable, jolla:
   - on supplier, jonka nimi vastaa `SALE_SUPPLIER_NAME`-arvoa
   - on saldoa jäljellä
   - on järkevä nimi, SKU / tunniste ja mahdollinen kuva
2. Aja Cron C käsin:

```bash
cd ~/cron
php cron_c_consumables_sync.php
```

3. Tarkista WooCommercesta:
   - tuote luotiin tai päivitettiin
   - SKU on muodossa `snipe-consumable-ID`
   - tuote näkyy odotetulla näkyvyydellä
   - saldo / saatavuus päivittyi odotetusti

### Testi 6: testaa piilotuslogiikka

Voit testata Cron C:n piilotuslogiikkaa kahdella tavalla:

- muuta testituotteen supplier sellaiseksi, ettei se enää vastaa `SALE_SUPPLIER_NAME`-arvoa
- tai muuta `.env`-tiedoston `SALE_SUPPLIER_NAME` tilapäisesti toiseen arvoon testauksen ajaksi

Aja sen jälkeen:

```bash
cd ~/cron
php cron_c_consumables_sync.php
```

Odotettu tulos:

- tuote piilotetaan WooCommercessa, jos se ei enää kuulu aktiiviseen supplier-listaan

### Testi 7: testaa cron-ajastus oikeasti

Kun käsiajo toimii, varmista vielä että myös cron ajaa skriptit:

```bash
crontab -l
tail -n 100 ~/cron/logs/cron_b_runner.log
tail -n 100 ~/cron/logs/cron_c_runner.log
```

Odotettu tulos:

- crontabissa näkyvät molemmat ajot
- runner-lokeihin ilmestyy uusia rivejä ajastetusti
- varsinaiset sovelluslogit (`cron_b_orders.log`, `cron_c_consumables.log`) päivittyvät myös

---

## Vianmääritys

### Virhe: `.env file missing`

Syy:

- `.env` puuttuu projektihakemistosta
- skriptiä ajetaan väärästä sijainnista tai tiedosto ei ole samassa hakemistossa kuin skriptit

Ratkaisu:

- varmista, että `.env` on samassa kansiossa kuin `bootstrap.php`

### Virhe: `Missing ENV variable`

Syy:

- yksi tai useampi pakollinen ympäristömuuttuja puuttuu tai on tyhjä

Ratkaisu:

- tarkista `.env` huolellisesti, erityisesti `SNIPE_LOCATION_ID`, `LOG_PATH`, `WOO_*` ja `SNIPE_*`

### WooCommerce API palauttaa 401 / 403

Syy:

- REST API -avaimet ovat väärät
- avaimella ei ole `Read/Write`-oikeuksia
- `WOO_URL` osoittaa väärään domainiin

Ratkaisu:

- luo uusi WooCommerce REST API -avain ja päivitä `.env`

### Snipe-IT API palauttaa 401 / 403

Syy:

- token on väärä, vanhentunut tai poistettu
- käyttäjällä ei ole tarvittavia oikeuksia

Ratkaisu:

- luo uusi Snipe-IT API token ja päivitä `.env`

### Lokit eivät kirjoitu

Syy:

- `LOG_PATH` on väärä
- hakemisto ei ole kirjoitettava

Ratkaisu:

```bash
mkdir -p ~/cron/logs
chmod 755 ~/cron/logs
```

Tarvittaessa tarkista myös tiedostojen omistaja:

```bash
ls -ld ~/cron/logs
```

### Cron toimii käsin mutta ei ajastettuna

Syy:

- cron käyttää eri PHP-binääriä
- ympäristöpolut eroavat käsiajosta
- tiedostopolut ovat väärin crontabissa

Ratkaisu:

- käytä cronissa absoluuttisia polkuja
- tarkista `which php`
- ohjaa stdout/stderr erilliseen runner-lokiin

---

## Lokit

- Cron B sovellusloki: `LOG_PATH/cron_b_orders.log`
- Cron B lukitustiedosto: `LOG_PATH/cron_b_orders.lock`
- Cron C sovellusloki: `LOG_PATH/cron_c_consumables.log`
- Suositus: lisäksi omat runner-lokit cron-komentojen stdout/stderrille

Tarkista lokit aina ensimmäisten ajojen jälkeen ja aina konfiguraatiomuutosten yhteydessä.

---

## WordPress monitorointiplugin (optional)

Polku:

```text
wp-plugin/inventory-monitor/inventory-monitor.php
```

### Ominaisuudet

- Cron B/C historiat WP Adminissa
- Virherivien poiminta
- Napit:
  - Aja tilausten synkronointi nyt
  - Aja varastosynkronointi nyt
  - Tyhjennä lokit nyt
- Asetukset:
  - PHP-binääri
  - cron-scriptien polut
  - lokipolut

### Asennus

1. Kopioi `wp-plugin/inventory-monitor` → `wp-content/plugins/inventory-monitor`
2. Aktivoi plugin WordPressissä
3. Avaa WP Adminissa **Inventory Monitor**
4. Aseta oikeat polut ympäristösi mukaan

---

## Tekijä

jjarvio
