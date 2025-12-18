# HTML to iBank XML Converter

Converts Serbian bank statements from HTML format to iBank XML format (pmtnotification structure).

## Features

- ✅ Supports both **domestic** (dinarski) and **foreign exchange** (devizni) statements
- ✅ Extracts account information, balances, and transactions
- ✅ Generates valid iBank XML format
- ✅ Handles multiple date formats and amount formats
- ✅ Two versions available: basic and improved (with BeautifulSoup)

## Files

- `html_to_ibank_xml.py` - Basic version (no external dependencies)
- `html_to_ibank_xml_improved.py` - Improved version (requires BeautifulSoup)

## Installation

### Basic Version
No additional dependencies required. Uses Python's built-in HTML parser.

```bash
python3 html_to_ibank_xml.py statement.html
```

### Improved Version (Recommended)
Requires BeautifulSoup4 for better HTML parsing:

```bash
# Install dependency
pip install beautifulsoup4

# Or using apt on Ubuntu/Debian
sudo apt install python3-bs4

# Run converter
python3 html_to_ibank_xml_improved.py statement.html
```

## Usage

### Basic Usage

```bash
# Convert a single file (output will be auto-generated)
python3 html_to_ibank_xml_improved.py "Devizni izvod#030000146059100298347720250924.html"

# Specify output file
python3 html_to_ibank_xml_improved.py input.html output.xml
```

### Batch Conversion

Convert all HTML files in current directory:

```bash
# Using bash loop
for file in *.html; do
    python3 html_to_ibank_xml_improved.py "$file"
done
```

Or create a batch script:

```bash
#!/bin/bash
# batch_convert.sh

for html_file in "Dinarski izvod"*.html "Devizni izvod"*.html; do
    if [ -f "$html_file" ]; then
        echo "Converting: $html_file"
        python3 html_to_ibank_xml_improved.py "$html_file"
    fi
done

echo "All conversions completed!"
```

Make it executable and run:

```bash
chmod +x batch_convert.sh
./batch_convert.sh
```

## Output Format

The converter generates XML in the iBank pmtnotification format:

```xml
<?xml version="1.0" ?>
<pmtnotification>
  <notificationtype>ibank.payment.notification.ledger</notificationtype>
  <status>
    <code>0</code>
    <severity>INFO</severity>
    <details/>
  </status>
  <curdef>USD</curdef>
  <acctid>030000146059</acctid>
  <stmtnumber>11</stmtnumber>
  <ledgerbal>
    <balamt>4500.00</balamt>
    <dtasof>2025-09-24T00:00:00</dtasof>
  </ledgerbal>
  <!-- ... more elements ... -->
  <trnlist count="1">
    <stmttrn>
      <trntype>ibank.payment.pp3</trntype>
      <fitid>FT252670F701</fitid>
      <benefit>credit</benefit>
      <!-- ... transaction details ... -->
    </stmttrn>
  </trnlist>
</pmtnotification>
```

## Extracted Data

The converter extracts the following information from HTML statements:

### Statement Information
- Statement number and date
- Account number (domestic format: XXX-XXXXXXXXXXXXX-XX)
- IBAN number
- Currency code (RSD, USD, EUR, etc.)
- Account holder name and details
- Beginning and ending balances

### Transaction Details
- Transaction reference (FITID)
- Transaction type
- Date posted and value date
- Amount
- Debit/Credit indicator
- Purpose/description
- Payee information (name, account, bank)
- Reference numbers

## Example Output

```
✓ Converted: Devizni izvod#030000146059100298347720250924.html
  Output: Devizni izvod#030000146059100298347720250924_converted.xml
  Account: 030000146059
  Statement #11 - 24.09.2025
  Currency: USD
  Holder: ALEKSANDAR PAVIĆ PR LCP SERVICES
  Beginning: 0.00
  Ending: 4500.00
  Transactions: 1
  Total Credit: 4500.00
  Total Debit: 0.00

✓ Conversion completed successfully!
```

## Supported HTML Formats

The converter has been tested with HTML statements from:

- **Erste Bank a.d. Novi Sad**
  - Domestic statements (Dinarski izvod)
  - Foreign exchange statements (Devizni izvod)

The converter should work with other Serbian banks that use similar HTML formats, but may require adjustments for specific bank formats.

## Troubleshooting

### Issue: BeautifulSoup not found

**Solution:**
```bash
pip install beautifulsoup4
```

### Issue: Account number not extracted

The converter looks for these patterns:
- "Platni račun broj:" for domestic accounts
- "Devizni račun broj:" for FX accounts

If your HTML uses different text, you may need to adjust the parsing logic.

### Issue: Transactions not parsed correctly

The converter uses pattern matching to extract transaction data. Complex or non-standard HTML structures may require manual adjustment of the parsing logic in `_parse_transaction_table_bs4()` method.

### Issue: Incorrect amounts

Make sure amounts in HTML use Serbian number format:
- Thousands separator: `.` (dot)
- Decimal separator: `,` (comma)
- Example: `1.234,56` for 1234.56

## Customization

To customize the converter for your specific needs:

1. **Modify transaction type:** Edit `transaction.trntype` default value
2. **Adjust purpose code:** Edit `transaction.purposecode` default value
3. **Change date format:** Modify `_convert_date()` method
4. **Add custom fields:** Extend the `Transaction` or `BankStatement` classes

## Python API Usage

You can also use the converter as a Python module:

```python
from html_to_ibank_xml_improved import convert_html_to_xml, BankStatement

# Convert a file
output_path = convert_html_to_xml('statement.html', 'output.xml')

# Or parse manually
with open('statement.html', 'r', encoding='utf-8') as f:
    html_content = f.read()

statement = BankStatement()
statement.parse_html_with_bs4(html_content)

print(f"Account: {statement.account_number}")
print(f"Balance: {statement.ending_balance}")
print(f"Transactions: {len(statement.transactions)}")

# Generate XML
xml_root = statement.to_ibank_xml()
```

## Technical Notes

### Date Format
- Input: `DD.MM.YYYY` (Serbian format)
- Output: `YYYY-MM-DDTHH:MM:SS` (ISO 8601)

### Amount Format
- Input: `1.234,56` (European format)
- Output: `1234.56` (Standard decimal)

### Account Number Format
- Domestic: `XXX-XXXXXXXXXXXXX-XX` (18 digits total)
- Foreign Exchange: `XXXXXXXXXXXX` (12 digits)

### Character Encoding
- Both input and output use UTF-8 encoding
- Supports Serbian Cyrillic and Latin characters

## License

This converter is provided as-is for processing Serbian bank statements.

## Support

For issues or questions, please refer to the CLAUDE.md documentation in this repository.
