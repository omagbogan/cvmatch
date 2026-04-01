<?php
/**
 * api-match.php
 * Pont entre le dashboard recruteur et le microservice Python Flask.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

requireRole('recruteur');
set_time_limit(300); // ← ajoute cette ligne

header('Content-Type: application/json');

// Lecture du body JSON envoyé par le dashboard
$rawInput = file_get_contents('php://input');
$body     = json_decode($rawInput, true);

if (!$body || empty($body['requete'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Requête manquante.']);
    exit;
}

$requete = trim($body['requete'] ?? '');
$filtre  = trim($body['filtre']  ?? '');
$mode    = trim($body['mode']    ?? 'match');

$endpoint = ($mode === 'chat') ? '/chat' : '/match';

$payload = json_encode([
    'requete' => $requete,
    'filtre'  => $filtre,
    'db_host' => DB_HOST,
    'db_port' => defined('DB_PORT') && DB_PORT ? DB_PORT : 3306,
    'db_user' => DB_USER,
    'db_pass' => DB_PASS,
    'db_name' => DB_NAME,
]);

// Forcer 127.0.0.1 pour éviter les problèmes DNS sous XAMPP
$serviceUrl = str_replace('localhost', '127.0.0.1', rtrim(IA_SERVICE_URL, '/'));
$url        = $serviceUrl . $endpoint;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($payload),
    'Accept: application/json',
    'Expect:',  // désactive le 100-continue qui bloque cURL
]);
curl_setopt($ch, CURLOPT_TIMEOUT, (int) IA_SERVICE_TIMEOUT);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Erreur de connexion (Python non démarré)
if ($curlError || $response === false) {
    http_response_code(503);
    echo json_encode([
        'error'  => 'Impossible de joindre le service IA Python. Vérifiez qu\'il est démarré sur ' . $url,
        'detail' => $curlError,
    ]);
    exit;
}

// Erreur HTTP retournée par Python
if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode([
        'error'  => 'Le service IA a retourné une erreur.',
        'detail' => $response,
        'code'   => $httpCode,
    ]);
    exit;
}

// Sauvegarder dans l'historique (non bloquant)
try {
    $db   = getDB();
    $user = getCurrentUser();
    $stmt = $db->prepare("INSERT INTO recherches (recruteur_id, requete, filtre, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$user['id'], $requete, $filtre]);
} catch (Exception $e) {
    error_log('[api-match] Sauvegarde historique échouée : ' . $e->getMessage());
}

echo $response;