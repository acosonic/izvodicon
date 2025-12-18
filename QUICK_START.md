# Brzi Start - Izvodi Konverter

## Docker Setup (Preporučeno)

### Korak 1: Pokreni kontejner

```bash
docker-compose up -d
```

### Korak 2: Otvori browser

Otvori: **http://localhost:5000**

### Korak 3: Upload i konvertuj

1. Prevuci HTML fajlove u upload zonu (ili klikni da odabereš)
2. Klikni "Konvertuj sve fajlove"
3. XML fajlovi će automatski biti preuzeti

### Zaustavi kontejner

```bash
docker-compose down
```

---

## Alternativa: Komandna linija

### Instalacija

```bash
pip install beautifulsoup4
```

### Konverzija

```bash
# Jedan fajl
python3 convert_html_to_xml.py "izvod.html"

# Batch konverzija
./batch_convert_all.sh
```

---

## Troubleshooting

**Problem:** Port 5000 zauzet
**Rešenje:** Izmeni port u `docker-compose.yml` (npr. "8080:5000")

**Problem:** Docker nije instaliran
**Rešenje:** Instaliraj Docker Desktop sa https://docker.com/

**Problem:** Konverzija ne radi
**Rešenje:** Proveri logove: `docker-compose logs -f`

---

## Tehnički detalji

- **Backend:** Flask (Python)
- **Frontend:** Vanilla JavaScript
- **Parser:** BeautifulSoup4
- **Port:** 5000
- **Max fajl:** 10MB
