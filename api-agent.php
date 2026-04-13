<?php
/**
 * CVMatch IA — API Agent Conversationnel
 * Pont PHP entre le dashboard recruteur et le microservice Python (port 5001)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Sécurité : recruteurs et admins uniquement
requireRole('recruteur');

header('Content-Type: application/json; charset=utf-8');

// Lire le body JSON
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Corps de requête invalide']);
    exit;
}

$message          = trim($body['message']          ?? '');
$requeteInitiale  = trim($body['requete_initiale'] ?? '');
$candidats        = $body['candidats']             ?? [];
$historique       = $body['historique']            ?? [];

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message vide']);
    exit;
}

// URL du service Python agent (port 5001)
$agentUrl = defined('AGENT_SERVICE_URL')
    ? AGENT_SERVICE_URL
    : 'http://localhost:5001';

// Préparer la requête vers Python
$payload = json_encode([
    'message'          => $message,
    'requete_initiale' => $requeteInitiale,
    'candidats'        => $candidats,
    'historique'       => $historique,
]);

$ch = curl_init($agentUrl . '/chat');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Si le service Python est indisponible → fallback local PHP
if ($curlError || $httpCode !== 200 || !$response) {
    $result = fallbackLocal($message, $candidats);
    echo json_encode($result);
    exit;
}

// Relayer la réponse Python
echo $response;
exit;


// ============================================================
// Fallback local PHP si Python est indisponible
// ============================================================
function fallbackLocal(string $instruction, array $candidats): array
{
    $m        = mb_strtolower($instruction);
    $filtered = $candidats;
    $message  = '';

    // Score
    if (preg_match('/(\d+)\s*%/', $m, $matches)) {
        $seuil    = (int) $matches[1];
        $filtered = array_values(array_filter($filtered, fn($c) => ($c['score'] ?? 0) >= $seuil));
        $message  = count($filtered) . " candidat(s) avec un score ≥ {$seuil}%.";

    // Ville
    } elseif (str_contains($m, 'abidjan')) {
        $filtered = array_values(array_filter($filtered, fn($c) => str_contains(mb_strtolower($c['ville'] ?? ''), 'abidjan')));
        $message  = count($filtered) . " candidat(s) basé(s) à Abidjan.";

    // Expérience
    } elseif (preg_match('/(\d+)\s*an/', $m, $matches)) {
        $ans      = (int) $matches[1];
        $filtered = array_values(array_filter($filtered, fn($c) => ($c['annees_experience'] ?? 0) >= $ans));
        $message  = count($filtered) . " candidat(s) avec ≥ {$ans} an(s) d'expérience.";

    // Tri par score
    } elseif (str_contains($m, 'trier') || str_contains($m, 'meilleur') || str_contains($m, 'top')) {
        usort($filtered, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $message = "Candidats triés par score décroissant (" . count($filtered) . " profil(s)).";

    } else {
        $message = "Service IA temporairement indisponible. Essayez : \"Score > 80%\", \"+3 ans d'expérience\", \"Basé à Abidjan\".";
    }

    return [
        'message'   => $message,
        'resultats' => $filtered,
        'count'     => count($filtered),
        'source'    => 'php_fallback',
    ];
}