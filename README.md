# HTML → iBank XML Konverter

Konverter koji transformiše HTML izvode Erste banke u iBank XML format.

## Instalacija

```bash
pip install beautifulsoup4
```

## Upotreba

### Pojedinačna konverzija

```bash
python3 convert_html_to_xml.py "Dinarski izvod#001101901597100327147220251022.html"
```

### Batch konverzija svih izvoda

```bash
./batch_convert_all.sh
```

## Primer izlaza

```
✓ Dinarski izvod#001101901597100327147220251022.html
  Račun: 340000001101901597
  Izvod #43 - 22.10.2025
  Valuta: RSD
  Stanje: 263952.69 RSD
  Transakcije: 1
```

## Generisani XML format

```xml
<pmtnotification>
  <notificationtype>ibank.payment.notification.ledger</notificationtype>
  <curdef>RSD</curdef>
  <acctid>340-0000011019015-97</acctid>
  <stmtnumber>43</stmtnumber>
  <ledgerbal>
    <balamt>263952.69</balamt>
    <dtasof>2025-10-22T00:00:00</dtasof>
  </ledgerbal>
  <trnlist count="1">
    <stmttrn>
      <trntype>ibank.payment.pp3</trntype>
      <fitid>FT25295WQ5Z4</fitid>
      <benefit>debit</benefit>
      <payeeinfo>
        <name/>
      </payeeinfo>
      <payeeaccountinfo>
        <bankname>ERSTE BANK AD NOVI SAD</bankname>
      </payeeaccountinfo>
      <dtposted>2025-10-22T00:00:00</dtposted>
      <trnamt>1500.00</trnamt>
      <purpose>Naknada za reizdavanje osnovne kart</purpose>
      <purposecode>221</purposecode>
      <curdef>RSD</curdef>
      ...
    </stmttrn>
  </trnlist>
</pmtnotification>
```

## Ekstraktovani podaci

### Zaglavlje izvoda
- Broj i datum izvoda
- Broj računa (formatiran kao BBB-PPPPPPPPPPPPP-KK)
- IBAN (ako postoji)
- Vlasnik računa
- Valuta (RSD, USD, EUR...)
- Početno i krajnje stanje

### Transakcije
- Tip transakcije (`ibank.payment.pp3`)
- Referenca (FITID)
- Datum knjiženja i valutiranja
- Iznos
- Duguje/Potražuje
- Svrha plaćanja
- Primalac/Platilac
- Banka primaoca/platioca
- Referentni broj

## Podržani formati

- ✅ Dinarski izvodi (RSD)
- ✅ Devizni izvodi (USD, EUR, itd.)
- ✅ Erste Bank HTML format

## Napomene

- Konverter parsira HTML koristeći BeautifulSoup4
- Podržava kompleksne ugnježene HTML tabele
- Automatski formatira brojeve računa u srpski format
- Konvertuje datume iz DD.MM.YYYY u ISO 8601 format
- Parsira iznose iz evropskog formata (1.234,56) u decimalni (1234.56)

## Fajlovi

- `convert_html_to_xml.py` - Glavni konverter script
- `batch_convert_all.sh` - Batch konverzija svih izvoda
- `README.md` - Ova dokumentacija

## Primer komandne linije

```bash
# Konvertuj jedan fajl
python3 convert_html_to_xml.py "Devizni izvod#030000146059100298347720250924.html"

# Konvertuj sa custom izlaznim imenom
python3 convert_html_to_xml.py input.html output.xml

# Batch konverzija
./batch_convert_all.sh
```

## Web Interface (Docker)

Konverter može da se pokrene kao web aplikacija koristeći Docker.

### Pokretanje sa Docker Compose (preporučeno)

```bash
# Build i pokreni kontejner
docker-compose up -d

# Proveri status
docker-compose ps

# Zaustavi kontejner
docker-compose down
```

### Pokretanje sa Docker-om

```bash
# Build Docker image
docker build -t izvodi-converter .

# Pokreni kontejner
docker run -d -p 5000:5000 --name izvodi-converter izvodi-converter

# Proveri da li radi
docker ps

# Zaustavi kontejner
docker stop izvodi-converter
docker rm izvodi-converter
```

### Pristup web interfejsu

Nakon pokretanja, otvori browser na:
```
http://localhost:5000
```

### Funkcionalnosti web interfejsa

- ✅ Drag & Drop za HTML fajlove
- ✅ Batch konverzija više fajlova odjednom
- ✅ Progress bar za svaki fajl
- ✅ Automatsko preuzimanje konvertovanih XML fajlova
- ✅ Maksimalna veličina fajla: 10MB
- ✅ Responsive dizajn

### Docker logovi

```bash
# Prati logove
docker-compose logs -f

# Ili sa docker komandom
docker logs -f izvodi-converter
```

## Troubleshooting

**Problem**: `ModuleNotFoundError: No module named 'bs4'`
**Rešenje**: `pip install beautifulsoup4`

**Problem**: `Permission denied: ./batch_convert_all.sh`
**Rešenje**: `chmod +x batch_convert_all.sh`

**Problem**: Transakcije se ne prikazuju
**Rešenje**: Proverite da li HTML sadrži sekciju "PREGLED SVIH VAŠIH TRANSAKCIJA"
