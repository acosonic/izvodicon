#!/usr/bin/env python3
"""
HTML Bank Statement to iBank XML Converter - Working Version

Converts Serbian bank statements from HTML to iBank XML format.
Handles Erste Bank statement format with proper span-based parsing.
"""

import re
import sys
from pathlib import Path
from xml.etree.ElementTree import Element, SubElement, tostring
from xml.dom import minidom

try:
    from bs4 import BeautifulSoup
    HAS_BS4 = True
except ImportError:
    HAS_BS4 = False
    print("ERROR: BeautifulSoup4 required. Install with: pip install beautifulsoup4")
    sys.exit(1)


class Transaction:
    """Bank transaction."""
    def __init__(self):
        self.serial_no = ""
        self.fitid = ""
        self.trntype = "ibank.payment.pp3"
        self.benefit = "debit"
        self.dtposted = ""
        self.dtuser = ""
        self.dtavail = ""
        self.trnamt = 0.0
        self.purpose = ""
        self.payee_name = ""
        self.payee_account = ""
        self.payee_bank = ""
        self.payee_refnumber = ""
        self.payee_refmodel = "97"
        self.purposecode = "221"
        self.urgency = "INT"


class BankStatement:
    """Bank statement parser."""

    def __init__(self):
        self.statement_number = ""
        self.statement_date = ""
        self.account_number = ""
        self.iban = ""
        self.currency = "RSD"
        self.account_holder = ""
        self.beginning_balance = 0.0
        self.ending_balance = 0.0
        self.transactions = []
        self.total_debit = 0.0
        self.total_credit = 0.0

    def parse_html(self, html_content):
        """Parse HTML content."""
        soup = BeautifulSoup(html_content, 'html.parser')

        # Extract all span texts
        spans = soup.find_all('span')
        texts = [s.get_text(strip=True) for s in spans if s.get_text(strip=True)]

        # Parse basic info
        self._parse_basic_info(texts)

        # Parse transactions
        self._parse_transactions(texts)

        return self

    def _parse_basic_info(self, texts):
        """Extract basic statement information."""
        for i, text in enumerate(texts):
            # Statement number and date
            if 'izvod broj i datum:' in text.lower():
                match = re.search(r'(\d+)/(\d{2}\.\d{2}\.\d{4})', text)
                if match:
                    self.statement_number = match.group(1)
                    self.statement_date = match.group(2)

            # Currency
            elif 'Valuta:' in text:
                match = re.search(r'([A-Z]{3})', text)
                if match:
                    self.currency = match.group(1)

            # Account number
            elif 'Platni račun broj:' in text or 'račun broj:' in text:
                match = re.search(r'(\d{18}|\d{12})', text)
                if match:
                    self.account_number = match.group(1)

            # IBAN
            elif 'IBAN' in text:
                match = re.search(r'RS\d{20}', text)
                if match:
                    self.iban = match.group(0)

            # Account holder
            elif ('doo' in text.lower() or 'd.o.o' in text.lower() or
                  ' pr ' in text.lower()) and not self.account_holder:
                self.account_holder = text.split('\n')[0].split('Adresa:')[0].strip()

            # Balances
            elif 'Početno stanje' in text:
                balance_text = texts[i+1] if i+1 < len(texts) else text
                match = re.search(r'([\d.,]+)\s*[A-Z]{3}', balance_text)
                if match:
                    self.beginning_balance = self._parse_amount(match.group(1))

            elif 'Krajnje stanje' in text:
                balance_text = texts[i+1] if i+1 < len(texts) else text
                match = re.search(r'([\d.,]+)\s*[A-Z]{3}', balance_text)
                if match:
                    self.ending_balance = self._parse_amount(match.group(1))

            # Totals
            elif 'Ukupno na teret' in text and i+1 < len(texts):
                match = re.search(r'([\d.,]+)\s*[A-Z]{3}', texts[i+1])
                if match:
                    self.total_debit = self._parse_amount(match.group(1))

            elif 'Ukupno u korist' in text and i+1 < len(texts):
                match = re.search(r'([\d.,]+)\s*[A-Z]{3}', texts[i+1])
                if match:
                    self.total_credit = self._parse_amount(match.group(1))

    def _parse_transactions(self, texts):
        """Parse transactions from span sequence."""
        # Find transaction section
        trans_start = -1
        for i, text in enumerate(texts):
            if 'PREGLED SVIH VAŠIH TRANSAKCIJA' in text or 'PREGLED VAŠIH TRANSAKCIJA' in text:
                trans_start = i
                break

        if trans_start < 0:
            return

        # Skip column headers
        # Find the LAST occurrence of amount column headers
        # This ensures we skip all headers including "ISPLATE" and "UPLATE"
        data_start = trans_start
        for i in range(trans_start, min(trans_start + 20, len(texts))):
            if texts[i] in ['U KORIST', 'UPLATE', 'ISPLATE']:
                data_start = i + 1  # Keep updating to get the last one

        # Parse transactions sequentially
        # Pattern: SerialNo, Date1, Date2, Description, Reference, Amount(s)
        i = data_start
        max_iterations = len(texts) - data_start
        iteration = 0

        while i < len(texts) and iteration < max_iterations:
            iteration += 1

            # Check if this looks like a serial number (start of transaction)
            if re.match(r'^\d+$', texts[i]) and len(texts[i]) <= 3:
                trn = self._parse_transaction_sequence(texts, i)
                if trn and trn.trnamt > 0:
                    self.transactions.append(trn)
                    # Domestic transactions: spacing is exactly 6 spans
                    # Foreign transactions: spacing may vary
                    # Skip to next serial number (usually +6 for domestic)
                    i += 6
                else:
                    i += 1
            else:
                i += 1

    def _parse_transaction_sequence(self, texts, start_idx):
        """Parse a single transaction from sequential spans."""
        trn = Transaction()

        # Ensure we have enough spans to parse
        if start_idx + 2 >= len(texts):
            return None

        idx = start_idx

        # 1. Serial number
        if idx < len(texts):
            trn.serial_no = texts[idx]
            idx += 1

        # Check if next span contains FT reference (foreign exchange format)
        if idx < len(texts) and 'FT' in texts[idx]:
            # Foreign exchange format: description block, dates, amounts
            return self._parse_foreign_transaction(texts, idx, trn)

        # Domestic format continues...

        # 2. Receipt date
        if idx < len(texts):
            date_match = re.search(r'(\d{2}\.\d{2}\.\d{4})', texts[idx])
            if date_match:
                trn.dtposted = self._convert_date(date_match.group(1))
                trn.dtuser = trn.dtposted
                idx += 1

        # 3. Execution date
        if idx < len(texts):
            date_match = re.search(r'(\d{2}\.\d{2}\.\d{4})', texts[idx])
            if date_match:
                trn.dtavail = self._convert_date(date_match.group(1))
                idx += 1

        # 4. Description
        if idx < len(texts):
            desc = texts[idx]
            if not re.match(r'\d{2}\.\d{2}\.\d{4}', desc) and \
               not re.match(r'FT\d+', desc) and \
               not desc.isupper():
                trn.purpose = desc[:140]
                idx += 1

        # 5. Payee (usually all caps)
        if idx < len(texts):
            payee = texts[idx]
            if re.match(r'^[A-ZČĆŽŠĐ][A-ZČĆŽŠĐ\s]+$', payee):
                if 'BANK' in payee or 'BANKA' in payee:
                    trn.payee_bank = payee
                else:
                    trn.payee_name = payee
                idx += 1

        # 6. Reference number (FT format or similar)
        if idx < len(texts):
            ref = texts[idx]
            # Check if ref contains FT reference (may have prefixes like "PBZ:PBO:FT...")
            if 'FT' in ref and re.search(r'FT\d+[A-Z0-9]*', ref):
                # Extract the FT part
                ft_match = re.search(r'(FT\d+[A-Z0-9]*)', ref)
                if ft_match:
                    trn.fitid = ft_match.group(1)
                    trn.payee_refnumber = trn.fitid
                idx += 1
            elif re.match(r'FT\d+[A-Z0-9]*', ref) or re.match(r'\d+-\d+', ref):
                trn.fitid = ref
                trn.payee_refnumber = ref
                idx += 1

        # 7 & 8. Amounts (debit and/or credit)
        debit_amt = 0.0
        credit_amt = 0.0

        for _ in range(2):
            if idx < len(texts):
                amt_match = re.search(r'^([\d.,]+)$', texts[idx])
                if amt_match:
                    amt = self._parse_amount(amt_match.group(1))
                    if amt > 0:
                        if debit_amt == 0:
                            debit_amt = amt
                        elif credit_amt == 0:
                            credit_amt = amt
                    idx += 1
                else:
                    break

        # Determine benefit and amount
        if debit_amt > 0 and credit_amt == 0:
            trn.benefit = "debit"
            trn.trnamt = debit_amt
        elif credit_amt > 0 and debit_amt == 0:
            trn.benefit = "credit"
            trn.trnamt = credit_amt
        elif debit_amt > 0:
            trn.benefit = "debit"
            trn.trnamt = debit_amt

        return trn

    def _parse_foreign_transaction(self, texts, idx, trn):
        """Parse foreign exchange transaction format."""
        # Description block contains everything
        desc_block = texts[idx]

        # Extract FT reference
        ft_match = re.search(r'(FT\d+[A-Z0-9]+)', desc_block)
        if ft_match:
            trn.fitid = ft_match.group(1)
            trn.payee_refnumber = trn.fitid

        # Extract transaction type/description (first line after FT)
        lines = desc_block.split('\n')
        for line in lines:
            if 'FT' not in line and len(line) > 5:
                if not trn.purpose:
                    trn.purpose = line[:140]
                    break

        # Extract bank name (only the bank, not everything after)
        bank_match = re.search(r'Banka[^:]*:\s*([^N]+?)(?:Nalogodavac|Zemlja|$)', desc_block)
        if bank_match:
            trn.payee_bank = bank_match.group(1).strip()

        # Extract payer/payee name (only first part before "Zemlja")
        payer_match = re.search(r'Nalogodavac:\s*([^Z]+?)(?:Zemlja|$)', desc_block)
        if payer_match:
            # Clean up the name - take only meaningful part
            name = payer_match.group(1).strip()
            # If it contains numbered items, take only first one
            if '1/' in name:
                name = re.sub(r'^1/', '', name)
                name = re.sub(r',?\s*2/.*$', '', name)
            trn.payee_name = name[:140]

        # Extract purpose from "Osnov:" (reference number and description)
        purpose_match = re.search(r'Osnov:\s*([^\n]+)', desc_block)
        if purpose_match:
            purpose = purpose_match.group(1).strip()
            # Remove RRN suffix if present
            purpose = re.sub(r'RRN.*$', '', purpose).strip()
            trn.purpose = purpose[:140]

        # Better: extract description from "Opis:" field if available
        opis_match = re.search(r'Opis:\s*([^\n]+)', desc_block)
        if opis_match:
            desc = opis_match.group(1).strip()
            # Remove "Iznos:" suffix if present
            desc = re.sub(r'\s+Iznos:.*$', '', desc).strip()
            if desc:
                trn.purpose = desc[:140]

        idx += 1

        # Next span: dates
        if idx < len(texts):
            dates = re.findall(r'(\d{2}\.\d{2}\.\d{4})', texts[idx])
            if len(dates) >= 1:
                trn.dtposted = self._convert_date(dates[0])
                trn.dtuser = trn.dtposted
                trn.dtavail = trn.dtposted
            if len(dates) >= 2:
                trn.dtavail = self._convert_date(dates[1])
            idx += 1

        # Next span: amounts (format: debit_amount credit_amount or just one)
        # Amounts may be concatenated without spaces: "4.500,00446.748,75"
        if idx < len(texts):
            # Use more specific regex for Serbian number format
            # Pattern: optional digits with dots (thousands), comma, exactly 2 decimals
            amounts = re.findall(r'(\d{1,3}(?:\.\d{3})*,\d{2})', texts[idx])

            # Try to find amounts - typically 2 or 4 numbers (currency and RSD)
            valid_amounts = []
            for amt_str in amounts:
                amt = self._parse_amount(amt_str)
                if amt > 0:
                    valid_amounts.append(amt)

            # Determine which is debit/credit based on position
            # Foreign exchange: usually credit (incoming) shown in UPLATE column
            if len(valid_amounts) >= 1:
                # First amount is in foreign currency
                trn.trnamt = valid_amounts[0]
                trn.benefit = "credit"  # Usually foreign exchange is incoming

        return trn

    def _parse_amount(self, amount_str):
        """Convert Serbian amount format to float."""
        if not amount_str:
            return 0.0
        clean = amount_str.strip().replace('.', '').replace(',', '.')
        clean = re.sub(r'[A-Z]{3}', '', clean).strip()
        try:
            return float(clean)
        except:
            return 0.0

    def _convert_date(self, date_str):
        """Convert DD.MM.YYYY to ISO format."""
        try:
            parts = date_str.split('.')
            if len(parts) == 3:
                return f"{parts[2]}-{parts[1]}-{parts[0]}T00:00:00"
        except:
            pass
        return "2025-01-01T00:00:00"

    def _format_account_number(self, account):
        """Format Serbian account number."""
        if not account:
            return ""
        clean = re.sub(r'[^0-9]', '', account)
        if len(clean) == 18:
            return f"{clean[:3]}-{clean[3:16]}-{clean[16:]}"
        return account

    def to_ibank_xml(self):
        """Generate iBank XML."""
        root = Element('pmtnotification')

        SubElement(root, 'notificationtype').text = 'ibank.payment.notification.ledger'

        status = SubElement(root, 'status')
        SubElement(status, 'code').text = '0'
        SubElement(status, 'severity').text = 'INFO'
        SubElement(status, 'details')

        SubElement(root, 'curdef').text = self.currency
        SubElement(root, 'acctid').text = self._format_account_number(self.account_number)
        SubElement(root, 'stmtnumber').text = self.statement_number

        ledgerbal = SubElement(root, 'ledgerbal')
        SubElement(ledgerbal, 'balamt').text = f"{self.ending_balance:.2f}"
        SubElement(ledgerbal, 'dtasof').text = self._convert_date(self.statement_date) if self.statement_date else ""

        availbal = SubElement(root, 'availbal')
        SubElement(availbal, 'balamt').text = f"{self.ending_balance:.2f}"
        SubElement(availbal, 'dtasof').text = self._convert_date(self.statement_date) if self.statement_date else ""

        SubElement(root, 'reservedfunds').text = '0.00'

        instantbal = SubElement(root, 'instantbal')
        SubElement(instantbal, 'balamt').text = f"{self.ending_balance:.2f}"
        SubElement(instantbal, 'dtasof')

        extension = SubElement(root, 'extension')
        SubElement(extension, 'headercomment')
        SubElement(extension, 'footercomment')
        headerdetails = SubElement(extension, 'headerdetails')
        intem = SubElement(headerdetails, 'intem')
        intem.set('order', '')
        intem.set('label', '')
        intem.set('value', '')

        SubElement(root, 'overdraftremaining')
        SubElement(root, 'overdraftused')
        SubElement(root, 'directdebitreserved')
        SubElement(root, 'projectedavail')
        SubElement(root, 'marketingmessage')
        SubElement(root, 'overdraftinterest')
        SubElement(root, 'period')

        income = sum(t.trnamt for t in self.transactions if t.benefit == 'credit')
        outflow = sum(t.trnamt for t in self.transactions if t.benefit == 'debit')

        SubElement(root, 'feetotal').text = '0.00'
        SubElement(root, 'noncashorders').text = '0.00'
        SubElement(root, 'income').text = f"{income:.2f}"
        SubElement(root, 'outflow').text = f"{outflow:.2f}"
        SubElement(root, 'accountfee').text = '0.00'
        SubElement(root, 'feecomment').text = 'Oslobođeno poreza po članu 25. Zakona o PDV.'

        overdraft = SubElement(root, 'overdraft')
        SubElement(overdraft, 'amount').text = '0.00'
        SubElement(overdraft, 'dtasof')
        SubElement(overdraft, 'dtasto')
        SubElement(overdraft, 'intrt')

        trnlist = SubElement(root, 'trnlist')
        trnlist.set('count', str(len(self.transactions)))

        for trn in self.transactions:
            stmttrn = SubElement(trnlist, 'stmttrn')

            SubElement(stmttrn, 'trntype').text = trn.trntype
            SubElement(stmttrn, 'fitid').text = trn.fitid
            SubElement(stmttrn, 'trnuid')
            SubElement(stmttrn, 'benefit').text = trn.benefit

            payeeinfo = SubElement(stmttrn, 'payeeinfo')
            SubElement(payeeinfo, 'name').text = trn.payee_name
            SubElement(payeeinfo, 'city')

            payeeaccountinfo = SubElement(stmttrn, 'payeeaccountinfo')
            SubElement(payeeaccountinfo, 'acctid').text = trn.payee_account
            SubElement(payeeaccountinfo, 'bankid')
            SubElement(payeeaccountinfo, 'bankname').text = trn.payee_bank

            SubElement(stmttrn, 'dtposted').text = trn.dtposted
            SubElement(stmttrn, 'trnamt').text = f"{trn.trnamt:.2f}"
            SubElement(stmttrn, 'purpose').text = trn.purpose
            SubElement(stmttrn, 'purposecode').text = trn.purposecode
            SubElement(stmttrn, 'curdef').text = self.currency
            SubElement(stmttrn, 'payeerefnumber').text = trn.payee_refnumber
            SubElement(stmttrn, 'trnplace').text = '999905 OfficeBanking'
            SubElement(stmttrn, 'dtuser').text = trn.dtuser
            SubElement(stmttrn, 'dtavail').text = trn.dtavail
            SubElement(stmttrn, 'refnumber')
            SubElement(stmttrn, 'refmodel')
            SubElement(stmttrn, 'payeerefmodel').text = trn.payee_refmodel
            SubElement(stmttrn, 'urgency').text = trn.urgency
            SubElement(stmttrn, 'fee').text = '0'

            statusinfo = SubElement(stmttrn, 'statusinfo')
            SubElement(statusinfo, 'code').text = '80'
            SubElement(statusinfo, 'timeposted').text = trn.dtposted

        rejected = SubElement(root, 'rejected')
        rejected.set('count', '0')

        reservedfundstype = SubElement(root, 'reservedfundstype')
        reserveditem = SubElement(reservedfundstype, 'reserveditem')
        SubElement(reserveditem, 'description')
        SubElement(reserveditem, 'ordercount').text = '0'
        SubElement(reserveditem, 'ordersum').text = '0'
        SubElement(reserveditem, 'comment')

        return root


def convert(html_file, output_file=None):
    """Convert HTML to XML."""
    html_path = Path(html_file)
    if not html_path.exists():
        raise FileNotFoundError(f"File not found: {html_file}")

    with open(html_path, 'r', encoding='utf-8') as f:
        html_content = f.read()

    statement = BankStatement().parse_html(html_content)
    xml_root = statement.to_ibank_xml()

    xml_string = tostring(xml_root, encoding='unicode')
    dom = minidom.parseString(xml_string)
    pretty_xml = dom.toprettyxml(indent='  ')
    pretty_xml = '\n'.join([line for line in pretty_xml.split('\n') if line.strip()])

    if output_file is None:
        output_file = html_path.with_suffix('.xml')

    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(pretty_xml)

    print(f"✓ {html_path.name}")
    print(f"  Račun: {statement.account_number}")
    print(f"  Izvod #{statement.statement_number} - {statement.statement_date}")
    print(f"  Valuta: {statement.currency}")
    print(f"  Stanje: {statement.ending_balance:.2f} {statement.currency}")
    print(f"  Transakcije: {len(statement.transactions)}")

    return output_file


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("HTML → iBank XML Konverter")
        print("\nUpotreba: python convert_html_to_xml.py <html_file> [output_file]")
        sys.exit(1)

    try:
        convert(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else None)
        print("\n✓ Konverzija uspešna!")
    except Exception as e:
        print(f"\n✗ Greška: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)
