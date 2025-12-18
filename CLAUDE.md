# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Purpose

This repository processes Serbian bank statements (izvodi) in multiple formats. It handles both domestic payment transactions (dinarski platni promet) and foreign exchange transactions (devizni platni promet) according to Serbian National Bank (NBJ) regulations and the FX CLIENT specification.

## Document Formats

The repository works with bank statements in the following formats:

### XML Formats
- **Domestic Payment XML (Dinarski izvod)**: Follows `ibank.payment.stmtrs.ledger` or `ibank.payment.notification.ledger` schema
- **Foreign Exchange XML (Devizni izvod)**: Follows `ibank.fps.stmtrs.statement` schema
- **Payment Orders (Nalozi)**: Both domestic (`pmtorderrq`) and foreign exchange (`fpspmtorderrq`) formats

### HTML Formats
Bank-generated HTML statements with embedded transaction data, typically from Erste Bank and other Serbian banks.

### Other Formats
- **TXT/SAP**: Fixed-width text format for SAP integration (180-character records)
- **Excel**: Structured spreadsheet format with header and transaction rows
- **PDF**: Format specification document only

## XML Schema Structure

### Domestic Payment Statement (Dinarski izvod)
Key elements:
- Root: `<stmtrs>` or `<pmtnotification>`
- Account number: `<acctid>` in format `XXX-XXXXXXXXXXXXX-XX` (bank-partition-control)
- Statement number: `<stmtnumber>` - sequential number per year
- Balances: `<ledgerbal>`, `<availbal>` with amounts and dates
- Transactions: `<trnlist>` containing multiple `<stmttrn>` elements
- Currency: `<curdef>` - typically "DIN" or "RSD" for domestic
- Transaction types: `ibank.payment.pp0` through `ibank.payment.pp4`

### Foreign Exchange Statement (Devizni izvod)
Key elements:
- Root: `<stmtrs>` with `rstype` = `ibank.fps.stmtrs.statement`
- GL account: `<glacct>` and `<glacctdesc>`
- Document list: `<documentlist>` with detailed transaction documents
- Document types: `ibank.fps.document.internalorder`, `ibank.fps.document.remittance`, `ibank.fps.document.generalorder`, `ibank.fps.document.payment`
- International elements: SWIFT codes, IBAN, correspondent banks

## Transaction Types

### Domestic Payment Instruments
- `ibank.payment.pp0`: Cash payments (Gotovinska plaćanja)
- `ibank.payment.pp1`: Payment order type 1
- `ibank.payment.pp2`: Payment order type 2
- `ibank.payment.pp3`: Payment order type 3 (most common - general payments)
- `ibank.payment.pp4`: Payment order type 4

### Urgency Codes
- `INT`: Internal/normal processing
- `ACH`: Net settlement (KLIRING)
- `RTGS`: Real-time gross settlement

## Key Data Elements

### Account Format
Serbian bank accounts follow the format: `BBB-PPPPPPPPPPPPP-KK`
- BBB: 3-digit unique bank code
- PPPPPPPPPPPPP: 13-digit partition number
- KK: 2-digit control number (modulo 97)

### Payment Purpose Codes
Three-digit codes according to NBJ codebook (šifarnik NBJ):
- 100: Cash payments - deposits and withdrawals
- 221: Trade in goods and services
- 321: Final consumption
- etc.

### Reference Numbers (Poziv na broj)
- Format: `(model)(reference)` e.g., `(97)(321231)`
- Model: 2-digit reference model code
- Reference: up to 20-character reference number

## Transaction Processing

### Statement Flow
1. Bank generates statement with opening balance (`ledgerbal`)
2. Transactions (`stmttrn`) posted with dates:
   - `dtposted`: Booking date
   - `dtavail`: Value date
   - `dtuser`: Entry date
3. Closing balance (`availbal`) calculated
4. Statement assigned sequential number (`stmtnumber`)

### Transaction Directions
- `benefit="credit"`: Incoming payment (odobrenje, potražuje)
- `benefit="debit"`: Outgoing payment (zaduženje, duguje)

## SAP Integration Format

The SAP format uses 180-character fixed-width records:

### Statement Structure
- Leading record (tip sloga = 9): Account summary with totals
- Detail records (tip sloga = 1): Individual transactions
- Cover file (_cov.txt): Balance information with record type 01

### SWIFT SAP Format
When `export_type=2` in settings.ini, generates MT940-like format with fields:
- `:20:` Transaction reference
- `:25:` Account number
- `:28C:` Statement number
- `:60F:` Opening balance
- `:61:` Transaction line
- `:86:` Transaction details
- `:62F:` Closing balance

## Common Development Tasks

### Parsing XML Statements
When parsing XML files:
- Check root element to determine statement type (domestic vs. foreign)
- Handle both `<stmtrs>` and `<pmtnotification>` root elements
- Parse amounts as decimals with 2 decimal places
- Extract dates in UTC format: `YYYY-MM-DDTHH:MM:SS`
- Process character encoding as UTF-8

### Validating Against XSD Schemas
The PDF specification (fx-client-opis-formata.pdf) contains complete XSD schemas for:
- Domestic payment statements (pages 6-9)
- Domestic payment orders (pages 13-15)
- Foreign exchange statements (pages 31-37)
- Foreign exchange payment orders (pages 42-47)

### Handling Multiple Currencies
- Domestic: Currency code "DIN" or "RSD"
- Foreign: ISO 4217 currency codes (EUR, USD, etc.)
- Amounts always formatted with 2 decimal places
- Foreign exchange transactions include local currency conversion

## File Naming Conventions

Based on observed files:
- Domestic HTML: `Dinarski izvod#<bank_account><date>.html`
- Foreign HTML: `Devizni izvod#<bank_account><date>.html`
- XML notifications: `[<account>][<statement_number>].xml`
- Generic: `<account> Izvod br. <number>-<type>.XML`

## Character Encoding

All text files use UTF-8 encoding, particularly important for Serbian Cyrillic/Latin characters.

## Important Notes

- Statement numbers (`stmtnumber`) are sequential per calendar year
- Control numbers in account format use modulo 97 algorithm
- SWIFT codes required for international transactions
- Correspondent banks (`viabank`) used for international payments
- Statistics tracking (`stat`) required for foreign exchange transactions with contract numbers and years
