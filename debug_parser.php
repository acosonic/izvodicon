<?php
/**
 * Debug script za testiranje HTML parsera
 */

require_once __DIR__ . '/html_to_xml_converter.php';

// Proveri da li je prosleđen HTML fajl
if ($argc < 2) {
    echo "Upotreba: php debug_parser.php <html_file>\n";
    exit(1);
}

$htmlFile = $argv[1];

if (!file_exists($htmlFile)) {
    echo "Greška: Fajl '{$htmlFile}' ne postoji!\n";
    exit(1);
}

echo "=== DEBUG: Parsiranje HTML fajla ===\n";
echo "Fajl: {$htmlFile}\n\n";

// Kreiraj konvertor sa debug modom
$converter = new BankStatementConverter($htmlFile, true);

// Generiši XML
$xml = $converter->generateXML();

// Parsiraj XML da vidimo šta je generisano
$dom = new DOMDocument();
$dom->loadXML($xml);

// Proveri broj transakcija
$transactions = $dom->getElementsByTagName('stmttrn');
echo "Broj pronađenih transakcija: " . $transactions->length . "\n\n";

if ($transactions->length > 0) {
    echo "=== Primeri transakcija ===\n";
    for ($i = 0; $i < min(3, $transactions->length); $i++) {
        $trn = $transactions->item($i);
        echo "\nTransakcija #" . ($i + 1) . ":\n";
        
        $date = $trn->getElementsByTagName('dtposted')->item(0);
        $payee = $trn->getElementsByTagName('name')->item(0);
        $purpose = $trn->getElementsByTagName('purpose')->item(0);
        $amount = $trn->getElementsByTagName('trnamt')->item(0);
        $benefit = $trn->getElementsByTagName('benefit')->item(0);
        
        echo "  Datum: " . ($date ? $date->textContent : 'N/A') . "\n";
        echo "  Primalac: " . ($payee ? $payee->textContent : 'N/A') . "\n";
        echo "  Svrha: " . ($purpose ? $purpose->textContent : 'N/A') . "\n";
        echo "  Iznos: " . ($amount ? $amount->textContent : 'N/A') . "\n";
        echo "  Tip: " . ($benefit ? $benefit->textContent : 'N/A') . "\n";
    }
} else {
    echo "PROBLEM: Nijedna transakcija nije pronađena!\n\n";
    echo "=== Pokušavam da analiziram HTML strukturu ===\n";
    
    // Učitaj HTML
    $html = file_get_contents($htmlFile);
    $htmlDom = new DOMDocument();
    libxml_use_internal_errors(true);
    $htmlDom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($htmlDom);
    
    // Proveri tabele
    $tables = $xpath->query("//table");
    echo "Broj tabela u HTML-u: " . $tables->length . "\n";
    
    // Proveri redove
    $rows = $xpath->query("//tr");
    echo "Broj redova (tr) u HTML-u: " . $rows->length . "\n";
    
    // Proveri span elemente
    $spans = $xpath->query("//span");
    echo "Broj span elemenata: " . $spans->length . "\n";
    
    // Prikaži prvih nekoliko span-ova
    echo "\n=== Primeri span elemenata (prvih 20) ===\n";
    for ($i = 0; $i < min(20, $spans->length); $i++) {
        $text = trim($spans->item($i)->textContent);
        if (!empty($text)) {
            echo ($i + 1) . ". " . substr($text, 0, 100) . "\n";
        }
    }
}

// Prikaži debug log
echo "\n=== DEBUG LOG ===\n";
$debugLog = $converter->getDebugLog();
if (count($debugLog) > 0) {
    foreach ($debugLog as $logEntry) {
        echo $logEntry . "\n";
    }
} else {
    echo "Nema debug log poruka.\n";
}

// Sačuvaj XML za pregled
$outputFile = str_replace('.html', '_debug.xml', $htmlFile);
file_put_contents($outputFile, $xml);
echo "\n\nXML sačuvan u: {$outputFile}\n";
echo "Možete pregledati generisan XML da vidite šta nedostaje.\n";
?>
