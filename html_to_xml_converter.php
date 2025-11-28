<?php
/**
 * HTML to XML Bank Statement Converter
 * Konvertuje HTML bankovni izvod (Erste Bank format) u XML format
 * 
 * Hibridna verzija koja kombinuje:
 * - Tačnu logiku za debit/credit na osnovu pozicije ćelije
 * - Poboljšanu logiku za izvlačenje opisa, primaoca i reference
 */

class BankStatementConverter {
    private $html;
    private $dom;
    private $xpath;
    private $debug = false;
    private $debugLog = [];
    
    public function __construct($htmlFile, $debug = false) {
        $this->html = file_get_contents($htmlFile);
        $this->dom = new DOMDocument();
        $this->debug = $debug;
        // Suppress HTML parsing warnings
        libxml_use_internal_errors(true);
        $this->dom->loadHTML($this->html);
        libxml_clear_errors();
        $this->xpath = new DOMXPath($this->dom);
    }
    
    private function log($message) {
        if ($this->debug) {
            $this->debugLog[] = $message;
            error_log("[BankConverter] " . $message);
        }
    }
    
    public function getDebugLog() {
        return $this->debugLog;
    }
    
    /**
     * Ekstraktuje tekst iz span elemenata
     */
    private function extractText($pattern) {
        $spans = $this->xpath->query("//span[contains(text(), '{$pattern}')]");
        if ($spans->length > 0) {
            $text = $spans->item(0)->textContent;
            return trim($text);
        }
        return '';
    }
    
    /**
     * Ekstraktuje broj računa
     */
    private function extractAccountNumber() {
        $text = $this->extractText('Platni račun broj:');
        if (preg_match('/Platni račun broj:\s*(\d+)/', $text, $matches)) {
            return $matches[1];
        }
        return '';
    }
    
    /**
     * Ekstraktuje iznos iz stringa
     */
    private function extractAmount($text) {
        // Ukloni RSD i pretvori u decimalni format
        $text = str_replace(['RSD', ' ', '.'], '', $text);
        $text = str_replace(',', '.', $text);
        return trim($text);
    }
    
    /**
     * Ekstraktuje početno stanje
     */
    private function extractOpeningBalance() {
        $spans = $this->xpath->query("//span[contains(text(), 'Početno stanje')]");
        if ($spans->length > 0) {
            $parentRow = $spans->item(0)->parentNode->parentNode;
            $cells = $this->xpath->query('.//span', $parentRow);
            foreach ($cells as $cell) {
                $text = $cell->textContent;
                if (preg_match('/[\d\.,]+\s*RSD/', $text)) {
                    return $this->extractAmount($text);
                }
            }
        }
        return '0.00';
    }
    
    /**
     * Ekstraktuje krajnje stanje
     */
    private function extractClosingBalance() {
        $spans = $this->xpath->query("//span[contains(text(), 'Krajnje stanje')]");
        if ($spans->length > 0) {
            $parentRow = $spans->item(0)->parentNode->parentNode;
            $cells = $this->xpath->query('.//span', $parentRow);
            foreach ($cells as $cell) {
                $text = $cell->textContent;
                if (preg_match('/[\d\.,]+\s*RSD/', $text)) {
                    return $this->extractAmount($text);
                }
            }
        }
        return '0.00';
    }
    
    /**
     * Ekstraktuje datum
     */
    private function extractDate() {
        $text = $this->extractText('Datum:');
        if (preg_match('/Datum:\s*(\d{2}\.\d{2}\.\d{4})/', $text, $matches)) {
            // Konvertuj DD.MM.YYYY u YYYY-MM-DD
            $parts = explode('.', $matches[1]);
            return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }
        return date('Y-m-d');
    }
    
    /**
     * Ekstraktuje transakcije iz tabele
     */
    private function extractTransactions() {
        $transactions = [];
        
        // Pronađi sve tabele sa transakcijama
        $rows = $this->xpath->query("//tr");
        $inTransactionTable = false;
        
        $this->log("Ukupno pronađeno redova (tr): " . $rows->length);
        
        foreach ($rows as $rowIndex => $row) {
            $cells = $this->xpath->query('.//td', $row);
            $rowText = '';
            foreach ($cells as $cell) {
                $spans = $this->xpath->query('.//span', $cell);
                foreach ($spans as $span) {
                    $rowText .= ' ' . trim($span->textContent);
                }
            }
            
            // Proveri da li smo u tabeli sa transakcijama
            if ((stripos($rowText, 'REDNI') !== false && stripos($rowText, 'BROJ') !== false) ||
                (stripos($rowText, 'DATUM') !== false && stripos($rowText, 'PRIJEMA') !== false)) {
                $inTransactionTable = true;
                $this->log("Pronađen header tabele transakcija na redu " . $rowIndex);
                continue;
            }
            
            // Ako smo u tabeli transakcija, pokušaj da parsiraš red
            if ($inTransactionTable && $cells->length > 5) {
                $cellData = [];
                
                foreach ($cells as $cell) {
                    $spans = $this->xpath->query('.//span', $cell);
                    $cellText = '';
                    
                    foreach ($spans as $span) {
                        $text = trim($span->textContent);
                        if (!empty($text)) {
                            $cellText .= $text . ' ';
                        }
                    }
                    
                    $cellData[] = trim($cellText);
                }
                
                $this->log("Procesiranje reda " . $rowIndex . " sa " . count($cellData) . " ćelija");
                
                // Pokušaj da pronađeš transakciju u ovom redu
                $transaction = $this->parseTransactionRow($cellData);
                if ($transaction) {
                    $transactions[] = $transaction;
                    $this->log("Transakcija uspešno parsirana: " . $transaction['recipient']);
                } else {
                    $this->log("Red " . $rowIndex . " nije prepoznat kao transakcija. Podaci: " . implode(" | ", array_slice($cellData, 0, 5)));
                }
            }
        }
        
        $this->log("Ukupno pronađeno transakcija: " . count($transactions));
        
        return $transactions;
    }
    
    /**
     * HIBRIDNO PARSIRANJE - kombinuje najbolje iz oba pristupa
     */
    private function parseTransactionRow($cellData) {
        // Pronađi prvo polje koje je numerički redni broj
        $rbIndex = -1;
        for ($i = 0; $i < count($cellData); $i++) {
            if (is_numeric(trim($cellData[$i])) && strlen(trim($cellData[$i])) <= 3) {
                $rbIndex = $i;
                break;
            }
        }
        
        if ($rbIndex === -1) {
            return null;
        }
        
        // Inicijalizuj transakciju
        $transaction = [
            'rb' => trim($cellData[$rbIndex]),
            'date_posted' => '',
            'date_value' => '',
            'description' => '',
            'recipient' => '',
            'reference' => '',
            'debit' => '0.00',
            'credit' => '0.00'
        ];
        
        // Pronađi datume (format DD.MM.YYYY.)
        $dates = [];
        for ($i = $rbIndex + 1; $i < count($cellData) && $i < $rbIndex + 10; $i++) {
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}\.?$/', trim($cellData[$i]))) {
                $dates[] = trim($cellData[$i]);
            }
        }
        
        if (count($dates) >= 1) {
            $transaction['date_posted'] = $this->convertDate($dates[0]);
            $transaction['date_value'] = isset($dates[1]) ? $this->convertDate($dates[1]) : $transaction['date_posted'];
        }
        
        // POBOLJŠANA LOGIKA - jednostavno pronađi description, recipient, reference i iznose
        $foundDescription = false;
        $foundRecipient = false;
        $foundReference = false;
        $totalCells = count($cellData);
        
        for ($i = $rbIndex + 1; $i < count($cellData); $i++) {
            $value = trim($cellData[$i]);
            
            // Preskoči prazna polja
            if (empty($value)) {
                continue;
            }
            
            // Preskoči datume
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}\.?$/', $value)) {
                continue;
            }
            
            // Proveri da li je iznos
            if (preg_match('/^[\d\.,]+$/', $value) && strlen($value) > 3) {
                $amount = $this->extractAmount($value);
                if (!empty($amount) && floatval($amount) > 0) {
                    // ISPRAVNA LOGIKA - koristi poziciju ćelije za određivanje debit/credit
                    // U Erste bankovnom izvodu:
                    // - Ćelija 6 ili 8 = DUGUJE (debit - troškovi)
                    // - Ćelija 7 ili 9 = POTRAŽUJE (credit - prihodi)
                    
                    if ($totalCells == 11) {
                        if ($i == 8) {
                            $transaction['debit'] = $amount;
                        } elseif ($i == 9) {
                            $transaction['credit'] = $amount;
                        }
                    } elseif ($totalCells == 8) {
                        // Za 8 kolona (test format)
                        if ($i == 6) {
                            $transaction['debit'] = $amount;
                        } elseif ($i == 7) {
                            $transaction['credit'] = $amount;
                        }
                    } else {
                        // Za druge strukture, koristi logiku relativne pozicije
                        // Prvi iznos = debit, drugi = credit
                        if (floatval($transaction['debit']) > 0) {
                            $transaction['credit'] = $amount;
                        } else {
                            $transaction['debit'] = $amount;
                        }
                    }
                }
                continue;
            }
            
            // Proveri da li je referenca (format: 97-12345-67890 ili slično)
            if (preg_match('/^\d{2}-[\d-]+$/', $value)) {
                if (!$foundReference) {
                    $transaction['reference'] = $value;
                    $foundReference = true;
                }
                continue;
            }
            
            // Ako sadrži slova i nije datum - opis ili primalac
            if (preg_match('/[A-Za-zА-Яа-яĐđŠšČčĆćŽž]/', $value)) {
                if (!$foundDescription) {
                    $transaction['description'] = $value;
                    $foundDescription = true;
                } elseif (!$foundRecipient) {
                    $transaction['recipient'] = $value;
                    $foundRecipient = true;
                }
            }
        }
        
        // Ako nema opisa, recipient ili reference, vrati null
        if (empty($transaction['description']) && empty($transaction['recipient'])) {
            return null;
        }
        
        // Pokušaj da izvučeš primaoca iz opisa ako opis sadrži datum (Erste Bank format)
        if (!empty($transaction['description']) && preg_match('/\d{2}-\d{2}-\d{4}/', $transaction['description'])) {
            $this->extractRecipientFromDescription($transaction);
        }
        
        return $transaction;
    }
    
    /**
     * Izvlači primaoca iz opisa transakcije (za Erste Bank kartične transakcije)
     */
    private function extractRecipientFromDescription(&$transaction) {
        $description = $transaction['description'];
        
        // Pattern za Erste Bank kartične transakcije:
        // "4322621*****0911 15-11-2025 TROJKA PECENJARA Novi Sad"
        // Format: [broj kartice] [datum] [NAZIV PRIMAOCA] [grad]
        
        // Pokušaj da pronađeš naziv primaoca nakon datuma
        // Pattern: datum (DD-MM-YYYY) + razmak + tekst do kraja ili do adrese (koja počinje sa slovom+brojevi)
        if (preg_match('/\d{2}-\d{2}-\d{4}\s+(.+?)(?:\s*E\d+|\s*Kartična|\s*$)/iu', $description, $matches)) {
            $recipient = trim($matches[1]);
            
            if (!empty($recipient)) {
                $transaction['recipient'] = $recipient;
                $this->log("Izvučen primalac iz opisa: " . $recipient);
            }
        }
        
        // Pokušaj da pronađeš referencu (npr. "FT253207VDYB")
        if (preg_match('/\b([A-Z]{2}\d{6,}[A-Z0-9]*)\b/', $description, $matches)) {
            if (empty($transaction['reference'])) {
                $transaction['reference'] = $matches[1];
                $this->log("Izvučena referenca iz opisa: " . $matches[1]);
            }
        }
    }
    
    /**
     * Konvertuje datum iz DD.MM.YYYY u YYYY-MM-DD
     */
    private function convertDate($date) {
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $date, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }
        return $date;
    }
    
    /**
     * Generiše XML
     */
    public function generateXML($outputFile = null) {
        $accountNumber = $this->extractAccountNumber();
        $openingBalance = $this->extractOpeningBalance();
        $closingBalance = $this->extractClosingBalance();
        $date = $this->extractDate();
        $transactions = $this->extractTransactions();
        
        // Kreiraj XML
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        
        // Root element
        $root = $xml->createElement('pmtnotification');
        $xml->appendChild($root);
        
        // Notification type
        $notificationType = $xml->createElement('notificationtype', 'ibank.payment.notification.ledger');
        $root->appendChild($notificationType);
        
        // Status
        $status = $xml->createElement('status');
        $root->appendChild($status);
        $code = $xml->createElement('code', '0');
        $status->appendChild($code);
        $severity = $xml->createElement('severity', 'INFO');
        $status->appendChild($severity);
        $details = $xml->createElement('details');
        $status->appendChild($details);
        
        // Currency
        $curdef = $xml->createElement('curdef', 'RSD');
        $root->appendChild($curdef);
        
        // Account ID
        $acctid = $xml->createElement('acctid', htmlspecialchars($accountNumber));
        $root->appendChild($acctid);
        
        // Statement number
        $stmtnumber = $xml->createElement('stmtnumber', '1');
        $root->appendChild($stmtnumber);
        
        // Ledger balance
        $ledgerbal = $xml->createElement('ledgerbal');
        $root->appendChild($ledgerbal);
        $balamt = $xml->createElement('balamt', $closingBalance);
        $ledgerbal->appendChild($balamt);
        $dtasof = $xml->createElement('dtasof', $date . 'T00:00:00');
        $ledgerbal->appendChild($dtasof);
        
        // Available balance
        $availbal = $xml->createElement('availbal');
        $root->appendChild($availbal);
        $balamt2 = $xml->createElement('balamt', $closingBalance);
        $availbal->appendChild($balamt2);
        $dtasof2 = $xml->createElement('dtasof', $date . 'T00:00:00');
        $availbal->appendChild($dtasof2);
        
        // Reserved funds
        $reservedfunds = $xml->createElement('reservedfunds', '0.00');
        $root->appendChild($reservedfunds);
        
        // Instant balance
        $instantbal = $xml->createElement('instantbal');
        $root->appendChild($instantbal);
        $balamt3 = $xml->createElement('balamt', $closingBalance);
        $instantbal->appendChild($balamt3);
        $dtasof3 = $xml->createElement('dtasof');
        $instantbal->appendChild($dtasof3);
        
        // Extension
        $extension = $xml->createElement('extension');
        $root->appendChild($extension);
        $headercomment = $xml->createElement('headercomment');
        $extension->appendChild($headercomment);
        $footercomment = $xml->createElement('footercomment');
        $extension->appendChild($footercomment);
        $headerdetails = $xml->createElement('headerdetails');
        $extension->appendChild($headerdetails);
        $intem = $xml->createElement('intem');
        $intem->setAttribute('order', '');
        $intem->setAttribute('label', '');
        $intem->setAttribute('value', '');
        $headerdetails->appendChild($intem);
        
        // Empty elements
        $elements = ['overdraftremaining', 'overdraftused', 'directdebitreserved', 
                     'projectedavail', 'marketingmessage', 'overdraftinterest', 'period'];
        foreach ($elements as $elem) {
            $el = $xml->createElement($elem);
            $root->appendChild($el);
        }
        
        // Fee total
        $feetotal = $xml->createElement('feetotal', '0.00');
        $root->appendChild($feetotal);
        
        // Non-cash orders
        $noncashorders = $xml->createElement('noncashorders', '0.00');
        $root->appendChild($noncashorders);
        
        // Income and outflow
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($transactions as $trn) {
            $totalDebit += floatval($trn['debit']);
            $totalCredit += floatval($trn['credit']);
        }
        
        $income = $xml->createElement('income', number_format($totalCredit, 2, '.', ''));
        $root->appendChild($income);
        
        $outflow = $xml->createElement('outflow', number_format($totalDebit, 2, '.', ''));
        $root->appendChild($outflow);
        
        // Account fee
        $accountfee = $xml->createElement('accountfee', '0.00');
        $root->appendChild($accountfee);
        
        // Fee comment
        $feecomment = $xml->createElement('feecomment', 'Oslobođeno poreza po članu 25. Zakona o PDV.');
        $root->appendChild($feecomment);
        
        // Overdraft
        $overdraft = $xml->createElement('overdraft');
        $root->appendChild($overdraft);
        $amount = $xml->createElement('amount', '0.00');
        $overdraft->appendChild($amount);
        $dtasof4 = $xml->createElement('dtasof');
        $overdraft->appendChild($dtasof4);
        $dtasto = $xml->createElement('dtasto');
        $overdraft->appendChild($dtasto);
        $intrt = $xml->createElement('intrt');
        $overdraft->appendChild($intrt);
        
        // Transaction list
        $trnlist = $xml->createElement('trnlist');
        $trnlist->setAttribute('count', count($transactions));
        $root->appendChild($trnlist);
        
        // Add transactions
        foreach ($transactions as $idx => $trn) {
            $stmttrn = $xml->createElement('stmttrn');
            $trnlist->appendChild($stmttrn);
            
            // Transaction type
            $trntype = $xml->createElement('trntype', 'ibank.payment.pp3');
            $stmttrn->appendChild($trntype);
            
            // FIT ID
            $fitid = $xml->createElement('fitid', htmlspecialchars($trn['reference']));
            $stmttrn->appendChild($fitid);
            
            // Transaction UID
            $trnuid = $xml->createElement('trnuid');
            $stmttrn->appendChild($trnuid);
            
            // Benefit (debit or credit)
            $benefit = floatval($trn['debit']) > 0 ? 'debit' : 'credit';
            $benefitEl = $xml->createElement('benefit', $benefit);
            $stmttrn->appendChild($benefitEl);
            
            // Payee info
            $payeeinfo = $xml->createElement('payeeinfo');
            $stmttrn->appendChild($payeeinfo);
            $name = $xml->createElement('name', htmlspecialchars($trn['recipient']));
            $payeeinfo->appendChild($name);
            $city = $xml->createElement('city');
            $payeeinfo->appendChild($city);
            
            // Payee account info
            $payeeaccountinfo = $xml->createElement('payeeaccountinfo');
            $stmttrn->appendChild($payeeaccountinfo);
            $acctid2 = $xml->createElement('acctid');
            $payeeaccountinfo->appendChild($acctid2);
            $bankid = $xml->createElement('bankid');
            $payeeaccountinfo->appendChild($bankid);
            $bankname = $xml->createElement('bankname', htmlspecialchars($trn['recipient']));
            $payeeaccountinfo->appendChild($bankname);
            
            // Date posted
            $dtposted = $xml->createElement('dtposted', $trn['date_posted'] . 'T00:00:00');
            $stmttrn->appendChild($dtposted);
            
            // Transaction amount
            $trnamt = floatval($trn['debit']) > 0 ? $trn['debit'] : $trn['credit'];
            $trnamtEl = $xml->createElement('trnamt', $trnamt);
            $stmttrn->appendChild($trnamtEl);
            
            // Purpose
            $purpose = $xml->createElement('purpose', htmlspecialchars($trn['description']));
            $stmttrn->appendChild($purpose);
            
            // Purpose code
            $purposecode = $xml->createElement('purposecode', '221');
            $stmttrn->appendChild($purposecode);
            
            // Currency
            $curdef2 = $xml->createElement('curdef', 'RSD');
            $stmttrn->appendChild($curdef2);
            
            // Payee ref number
            $payeerefnumber = $xml->createElement('payeerefnumber', htmlspecialchars($trn['reference']));
            $stmttrn->appendChild($payeerefnumber);
            
            // Transaction place
            $trnplace = $xml->createElement('trnplace', '999905 OfficeBanking');
            $stmttrn->appendChild($trnplace);
            
            // User date
            $dtuser = $xml->createElement('dtuser', $trn['date_value'] . 'T00:00:00');
            $stmttrn->appendChild($dtuser);
            
            // Available date
            $dtavail = $xml->createElement('dtavail', $trn['date_value'] . 'T00:00:00');
            $stmttrn->appendChild($dtavail);
            
            // Reference number
            $refnumber = $xml->createElement('refnumber');
            $stmttrn->appendChild($refnumber);
            
            // Reference model
            $refmodel = $xml->createElement('refmodel');
            $stmttrn->appendChild($refmodel);
            
            // Payee ref model
            $payeerefmodel = $xml->createElement('payeerefmodel', '97');
            $stmttrn->appendChild($payeerefmodel);
            
            // Urgency
            $urgency = $xml->createElement('urgency', 'INT');
            $stmttrn->appendChild($urgency);
            
            // Fee
            $fee = $xml->createElement('fee', '0');
            $stmttrn->appendChild($fee);
            
            // Status info
            $statusinfo = $xml->createElement('statusinfo');
            $stmttrn->appendChild($statusinfo);
            $code2 = $xml->createElement('code', '80');
            $statusinfo->appendChild($code2);
            $timeposted = $xml->createElement('timeposted', $trn['date_posted'] . 'T09:48:58');
            $statusinfo->appendChild($timeposted);
        }
        
        // Rejected
        $rejected = $xml->createElement('rejected');
        $rejected->setAttribute('count', '0');
        $root->appendChild($rejected);
        
        // Reserved funds type
        $reservedfundstype = $xml->createElement('reservedfundstype');
        $root->appendChild($reservedfundstype);
        $reserveditem = $xml->createElement('reserveditem');
        $reservedfundstype->appendChild($reserveditem);
        $description = $xml->createElement('description');
        $reserveditem->appendChild($description);
        $ordercount = $xml->createElement('ordercount', '0');
        $reserveditem->appendChild($ordercount);
        $ordersum = $xml->createElement('ordersum', '0');
        $reserveditem->appendChild($ordersum);
        $comment = $xml->createElement('comment');
        $reserveditem->appendChild($comment);
        
        // Save or return XML
        if ($outputFile) {
            $xml->save($outputFile);
            return true;
        } else {
            return $xml->saveXML();
        }
    }
}

// Ako je fajl pokrenut direktno iz CLI
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $inputFile = $argv[1];
    $outputFile = isset($argv[2]) ? $argv[2] : str_replace('.html', '.xml', $inputFile);
    
    if (!file_exists($inputFile)) {
        echo "Greška: Fajl '{$inputFile}' ne postoji!\n";
        exit(1);
    }
    
    try {
        $converter = new BankStatementConverter($inputFile);
        $converter->generateXML($outputFile);
        echo "XML uspešno kreiran: {$outputFile}\n";
    } catch (Exception $e) {
        echo "Greška: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
