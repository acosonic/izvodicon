<?php
/**
 * Upload and Convert Backend
 * Prima HTML fajl, konvertuje ga u XML i vraća kao download
 */

// Omogući error reporting za development (ukloni u produkciji)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Ne prikazuj errore direktno, nego ih loguj

// Uključi konvertor
require_once __DIR__ . '/html_to_xml_converter.php';

// Set headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Funkcija za vraćanje JSON greške
function returnError($message, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $message
    ]);
    exit;
}

// Funkcija za logovanje (opcionalno)
function logConversion($filename, $success, $error = null) {
    $logFile = __DIR__ . '/conversions.log';
    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? 'SUCCESS' : 'FAILED';
    $logMessage = sprintf(
        "[%s] %s - %s%s\n",
        $timestamp,
        $filename,
        $status,
        $error ? " - Error: $error" : ''
    );
    @file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Proveri da li je POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    returnError('Method not allowed', 405);
}

// Proveri da li je fajl uploadovan
if (!isset($_FILES['html_file']) || $_FILES['html_file']['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'Fajl je prevelik (PHP limit)',
        UPLOAD_ERR_FORM_SIZE => 'Fajl je prevelik (form limit)',
        UPLOAD_ERR_PARTIAL => 'Fajl je samo delimično uploadovan',
        UPLOAD_ERR_NO_FILE => 'Nije odabran fajl',
        UPLOAD_ERR_NO_TMP_DIR => 'Nedostaje tmp direktorijum',
        UPLOAD_ERR_CANT_WRITE => 'Greška pri pisanju fajla',
        UPLOAD_ERR_EXTENSION => 'Upload blokiran ekstenzijom'
    ];
    
    if (isset($_FILES['html_file']['error']) && isset($errorMessages[$_FILES['html_file']['error']])) {
        $error = $errorMessages[$_FILES['html_file']['error']];
    } elseif (isset($_FILES['html_file']['error'])) {
        $error = 'Nepoznata greška pri upload-u';
    } else {
        $error = 'Fajl nije uploadovan';
    }
    
    returnError($error);
}

$uploadedFile = $_FILES['html_file'];
$originalFilename = $uploadedFile['name'];
$tmpFilePath = $uploadedFile['tmp_name'];

// Proveri ekstenziju fajla
$fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
if (!in_array($fileExtension, ['html', 'htm'])) {
    returnError('Samo HTML fajlovi su dozvoljeni');
}

// Proveri veličinu fajla (max 10MB)
$maxFileSize = 10 * 1024 * 1024; // 10MB
if ($uploadedFile['size'] > $maxFileSize) {
    returnError('Fajl je prevelik. Maksimalna veličina je 10MB.');
}

// Proveri MIME type (dodatna provera)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $tmpFilePath);
finfo_close($finfo);

$allowedMimes = ['text/html', 'text/plain', 'application/octet-stream'];
if (!in_array($mimeType, $allowedMimes)) {
    returnError('Tip fajla nije dozvoljen');
}

try {
    // Konvertuj HTML u XML
    $converter = new BankStatementConverter($tmpFilePath);
    $xmlString = $converter->generateXML();
    
    // Proveri da li je XML generisan
    if (empty($xmlString)) {
        throw new Exception('Generisan XML je prazan');
    }
    
    // Validiraj XML
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xmlString)) {
        throw new Exception('Generisan XML nije validan');
    }
    
    // Loguj uspešnu konverziju
    logConversion($originalFilename, true);
    
    // Generiši ime izlaznog fajla
    $xmlFilename = pathinfo($originalFilename, PATHINFO_FILENAME) . '.xml';
    
    // Vrati XML kao download
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $xmlFilename . '"');
    header('Content-Length: ' . strlen($xmlString));
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    
    echo $xmlString;
    
    // Očisti tmp fajl
    @unlink($tmpFilePath);
    
} catch (Exception $e) {
    // Loguj grešku
    logConversion($originalFilename, false, $e->getMessage());
    
    // Očisti tmp fajl
    @unlink($tmpFilePath);
    
    // Vrati grešku
    returnError('Greška pri konverziji: ' . $e->getMessage(), 500);
}
?>
