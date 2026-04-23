<?php
/**
 * api-match.php
 * Pont entre le dashboard recruteur et le microservice Python Flask.
 * Les candidats sont récupérés depuis MySQL et envoyés au Python.
 * Inclut désormais : genre, date_naissance, age (calculé en PHP).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

requireRole('recruteur');
set_time_limit(300);

header('Content-Type: application/json');
$requestStartedAt = microtime(true);

// Lecture du body JSON
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

// --- Récupération des candidats depuis MySQL ---
// Inclut genre et date_naissance pour enrichir le profil envoyé à Python
$candidates = [];
try {
    $db   = getDB();
    $stmt = $db->query(
        "SELECT u.id, u.nom, u.email, u.telephone, u.ville,
                u.genre, u.date_naissance,
                c.id as cv_id, c.competences_extraites, c.annees_experience,
                c.fichier_stocke, c.texte_extrait, c.formation, c.uploaded_at
         FROM cvs c
         JOIN users u ON u.id = c.user_id
         WHERE u.role = 'candidat'
         ORDER BY u.id, c.uploaded_at DESC"
    );
    $rows = $stmt->fetchAll();

    // Calcul de l'âge en PHP et ajout au tableau candidat
    $now = new DateTime();
    foreach ($rows as $row) {
        $age = null;
        if (!empty($row['date_naissance'])) {
            try {
                $dob = new DateTime($row['date_naissance']);
                $age = (int)$now->diff($dob)->y;
            } catch (Exception $e) {
                $age = null;
            }
        }
        $row['age'] = $age; // Ajout de l'âge calculé
        unset($row['date_naissance']); // On envoie l'âge, pas la date brute (confidentialité)
        $candidates[] = $row;
    }

} catch (Exception $e) {
    error_log('[api-match] Erreur récupération candidats : ' . $e->getMessage());
}

// --- Construction du payload ---
$payload = json_encode([
    'requete'    => $requete,
    'filtre'     => $filtre,
    'candidates' => $candidates,
]);

$estimatedSeconds = estimateAnalysisSeconds(count($candidates));

$serviceUrl = rtrim(IA_SERVICE_URL, '/');
$url        = $serviceUrl . $endpoint;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($payload),
    'Accept: application/json',
    'Expect:',
]);
curl_setopt($ch, CURLOPT_TIMEOUT, (int) IA_SERVICE_TIMEOUT);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Erreur de connexion → fallback lexical
if ($curlError || $response === false) {
    $results = fallbackScoring($candidates, $requete, $filtre);
    echo json_encode([
        'resultats' => $results,
        'fallback'  => true,
        'meta'      => [
            'duration_ms'       => elapsedMilliseconds($requestStartedAt),
            'estimated_seconds' => $estimatedSeconds,
            'candidate_count'   => count($candidates),
        ],
    ]);
    exit;
}

// Erreur HTTP
if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode([
        'error'  => 'Le service IA a retourné une erreur.',
        'detail' => $response,
        'code'   => $httpCode,
    ]);
    exit;
}

// Sauvegarde historique
try {
    $user = getCurrentUser();
    $stmt = $db->prepare("INSERT INTO recherches (recruteur_id, requete, filtre, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$user['id'], $requete, $filtre]);
} catch (Exception $e) {
    error_log('[api-match] Sauvegarde historique échouée : ' . $e->getMessage());
}

$decodedResponse = json_decode($response, true);
if (is_array($decodedResponse)) {
    $decodedResponse['meta'] = array_merge($decodedResponse['meta'] ?? [], [
        'duration_ms'       => elapsedMilliseconds($requestStartedAt),
        'estimated_seconds' => $estimatedSeconds,
        'candidate_count'   => count($candidates),
    ]);
    echo json_encode($decodedResponse);
    exit;
}

echo $response;

// --- Fallback scoring lexical ---
function fallbackScoring(array $candidates, string $requete, string $filtre): array {
    $terms = array_filter(explode(' ', strtolower($requete . ' ' . $filtre)), fn($t) => strlen($t) > 2);
    $results = [];
    foreach ($candidates as $c) {
        $content = strtolower(implode(' ', [
            $c['nom']                   ?? '',
            $c['ville']                 ?? '',
            $c['genre']                 ?? '',
            $c['competences_extraites'] ?? '',
            $c['texte_extrait']         ?? '',
            $c['formation']             ?? '',
        ]));
        $matches = 0;
        foreach ($terms as $term) {
            if (str_contains($content, $term)) $matches++;
        }
        $score = count($terms) > 0 ? (int) round(($matches / count($terms)) * 100) : 0;
        if ($score >= 30) {
            $results[] = [
                'id'                    => $c['id'],
                'nom'                   => $c['nom'],
                'email'                 => $c['email'],
                'telephone'             => $c['telephone'],
                'ville'                 => $c['ville'],
                'genre'                 => $c['genre'] ?? null,
                'age'                   => $c['age']   ?? null,
                'score'                 => $score,
                'annees_experience'     => (int)($c['annees_experience'] ?? 0),
                'competences_extraites' => $c['competences_extraites'],
                'cv_fichier'            => $c['fichier_stocke'],
                'resume_ia'             => 'Score calculé par méthode lexicale.',
            ];
        }
    }
    usort($results, fn($a, $b) => $b['score'] - $a['score']);
    return $results;
}

function estimateAnalysisSeconds(int $candidateCount): int {
    if ($candidateCount <= 0) return 2;
    return max(2, min(60, (int) ceil(2 + ($candidateCount / 4))));
}

function elapsedMilliseconds(float $startedAt): int {
    return (int) round((microtime(true) - $startedAt) * 1000);
}