<?php
/**
 * CVMatch IA — API Agent Conversationnel
 * Pont PHP entre le dashboard recruteur et le microservice Python (port 5002)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

requireRole('recruteur');

header('Content-Type: application/json; charset=utf-8');

// ============================================================
// Lecture du body
// ============================================================
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Corps de requête invalide']);
    exit;
}

$message         = trim($body['message']          ?? '');
$requeteInitiale = trim($body['requete_initiale'] ?? '');
$candidats       = $body['candidats']             ?? [];
$historique      = $body['historique']            ?? [];

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message vide']);
    exit;
}

// ============================================================
// URL du service Python — port 5002
// ============================================================
$agentUrl = defined('AGENT_SERVICE_URL')
    ? AGENT_SERVICE_URL
    : 'http://localhost:5002';   // ← corrigé (était 5001)

// ============================================================
// Appel vers le microservice Python
// ============================================================
$payload = json_encode([
    'message'          => $message,
    'requete_initiale' => $requeteInitiale,
    'candidats'        => $candidats,
    'historique'       => $historique,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($agentUrl . '/chat');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,    // abandon rapide si Python est mort
    CURLOPT_TIMEOUT        => 25,   // délai max pour une réponse normale
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
    ],
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ============================================================
// Erreur réseau ou Python indisponible → fallback PHP
// ============================================================
if ($curlError || $httpCode !== 200 || !$response) {
    // Log discret pour le débogage serveur
    error_log(sprintf(
        '[CVMatch Agent] Python indisponible — code: %d | erreur cURL: %s | url: %s',
        $httpCode,
        $curlError ?: 'aucune',
        $agentUrl
    ));

    $result = fallbackLocal($message, $candidats);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// Vérifier que Python a retourné du JSON valide
// ============================================================
$decoded = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log('[CVMatch Agent] Réponse Python non-JSON : ' . substr($response, 0, 200));
    $result = fallbackLocal($message, $candidats);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// Relayer la réponse Python telle quelle
echo $response;
exit;


// ============================================================
// Fallback local PHP si Python est indisponible
// Couvre : score, villes CI, expérience, tri, compétences
// Retourne les mêmes champs que le microservice Python
// ============================================================
function fallbackLocal(string $instruction, array $candidats): array
{
    $m        = mb_strtolower($instruction, 'UTF-8');
    $filtered = $candidats;
    $message  = '';
    $suggestion = null;

    // --- Score ---
    if (preg_match('/(\d+)\s*%/', $m, $matches)) {
        $seuil    = (int) $matches[1];
        $filtered = array_values(array_filter(
            $filtered,
            fn($c) => ($c['score'] ?? 0) >= $seuil
        ));
        $message = count($filtered) . " candidat(s) avec un score ≥ {$seuil}%.";
        if (count($filtered) === 0) {
            $suggestion = "Aucun profil au-dessus de {$seuil}%. Essayez un seuil plus bas, par exemple " . ($seuil - 15) . "%.";
        }

    // --- Villes Côte d'Ivoire ---
    } elseif (preg_match('/abidjan|bouak[eé]|yamoussoukro|daloa|korhogo|san.?p[eé]dro/u', $m, $villeMatch)) {
        $ville    = $villeMatch[0];
        $filtered = array_values(array_filter(
            $filtered,
            fn($c) => str_contains(mb_strtolower($c['ville'] ?? '', 'UTF-8'), $ville)
        ));
        $message = count($filtered) . " candidat(s) basé(s) à " . ucfirst($ville) . ".";
        if (count($filtered) === 0) {
            $suggestion = "Aucun profil dans cette ville. Essayez sans contrainte de localisation ou élargissez à une autre ville.";
        }

    // --- Expérience ---
    } elseif (preg_match('/(\d+)\s*an/', $m, $matches)) {
        $ans      = (int) $matches[1];
        $filtered = array_values(array_filter(
            $filtered,
            fn($c) => ($c['annees_experience'] ?? 0) >= $ans
        ));
        $message = count($filtered) . " candidat(s) avec ≥ {$ans} an(s) d'expérience.";
        if (count($filtered) === 0 && $ans > 1) {
            $suggestion = "Aucun profil avec {$ans} ans d'expérience. Essayez " . ($ans - 1) . " an(s).";
        }

    // --- Tri par score ---
    } elseif (preg_match('/trier|meilleur|top|classer/u', $m)) {
        usort($filtered, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $message = "Candidats triés par score décroissant (" . count($filtered) . " profil(s)).";

    // --- Tri par expérience ---
    } elseif (preg_match('/exp[eé]rience|senior|ancien/u', $m)) {
        usort($filtered, fn($a, $b) => ($b['annees_experience'] ?? 0) <=> ($a['annees_experience'] ?? 0));
        $message = "Candidats triés par expérience décroissante (" . count($filtered) . " profil(s)).";

    // --- Filtre compétence générique ---
    } else {
        $mots = array_filter(
            explode(' ', $m),
            fn($w) => mb_strlen($w, 'UTF-8') > 3
        );
        if (!empty($mots)) {
            $filtered = array_values(array_filter(
                $filtered,
                function ($c) use ($mots) {
                    $comp = mb_strtolower($c['competences_extraites'] ?? '', 'UTF-8');
                    foreach ($mots as $mot) {
                        if (str_contains($comp, $mot)) return true;
                    }
                    return false;
                }
            ));
            $message = count($filtered) . " candidat(s) correspondent à votre critère.";
            if (count($filtered) === 0) {
                $suggestion = "Service IA indisponible. Aucun profil trouvé avec ce critère. Réessayez avec un terme plus général.";
            }
        } else {
            $message = "Service IA temporairement indisponible. Essayez : \"Score > 80%\", \"+3 ans d'expérience\", \"Basé à Abidjan\".";
        }
    }

    $result = [
        'message'   => $message,
        'resultats' => $filtered,
        'count'     => count($filtered),
        'intention' => 'filtrage',
        'source'    => 'php_fallback',
    ];

    if ($suggestion !== null) {
        $result['suggestion_assouplissement'] = $suggestion;
    }

    return $result;
}