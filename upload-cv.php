<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

// Vérifications
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non authentifié.']);
    exit;
}

if ($_SESSION['user_role'] !== 'candidat') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès réservé aux candidats.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// Vérification CSRF
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF invalide.']);
    exit;
}

// Vérifier la présence du fichier
if (!isset($_FILES['cv_file']) || $_FILES['cv_file']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'error' => 'Aucun fichier sélectionné.']);
    exit;
}

// Traitement de l'upload
$file   = $_FILES['cv_file'];
$result = uploadCV($file);

$message = $result['message'] ?? ($result['success'] ? 'CV téléchargé avec succès.' : 'Erreur inconnue lors de l\'upload.');

if (!$result['success']) {
    flash('error', $message);
    header('Location: dashboard-candidat.php');
    exit;
}

// -------------------------------------------------------
// Upload réussi → enregistrement en base + extraction IA
// -------------------------------------------------------

$cvId            = null;
$texteExtrait    = null;
$competences     = null;
$anneesExp       = 0;
$formation       = null;
$extractionOk    = false;

try {
    $db = getDB();

    // Insertion du CV en base
    $stmt = $db->prepare(
        "INSERT INTO cvs (user_id, fichier_original, fichier_stocke, type_fichier, taille_fichier)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $_SESSION['user_id'],
        $result['original'],
        $result['filename'],
        $result['mime'],
        $result['size'],
    ]);
    $cvId = $db->lastInsertId();

} catch (PDOException $e) {
    error_log('[upload-cv] Erreur insertion BDD : ' . $e->getMessage());
    flash('error', 'CV téléchargé, mais impossible d\'enregistrer en base de données.');
    header('Location: dashboard-candidat.php');
    exit;
}

// -------------------------------------------------------
// Appel au microservice cv_extractor.py
// -------------------------------------------------------

$cvFilePath = UPLOAD_DIR . $result['filename'];

if (file_exists($cvFilePath)) {
    try {
        $fileData = base64_encode(file_get_contents($cvFilePath));

        $payload = json_encode([
            'filename' => $result['original'],
            'mimetype' => $result['mime'],
            'filedata' => $fileData,
        ]);

        // URL du service extracteur (port 5001 par défaut)
        $extractorUrl = defined('AGENT_SERVICE_URL')
            ? rtrim(AGENT_SERVICE_URL, '/') . '/extract-cv'
            : 'http://localhost:5001/extract-cv';

        $ch = curl_init($extractorUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'Accept: application/json',
            'Expect:',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!$curlError && $httpCode === 200 && $response) {
            $extracted = json_decode($response, true);

            if (!empty($extracted['success'])) {
                $texteExtrait = $extracted['texte']       ?? null;
                $competences  = $extracted['competences'] ?? null;
                $anneesExp    = (int)($extracted['annees_experience'] ?? 0);
                $formation    = $extracted['formation']   ?? null;
                $extractionOk = true;
            }
        } else {
            error_log('[upload-cv] cv_extractor.py indisponible : ' . ($curlError ?: "HTTP $httpCode"));
        }

    } catch (Exception $e) {
        error_log('[upload-cv] Erreur appel extracteur : ' . $e->getMessage());
    }
}

// -------------------------------------------------------
// Mise à jour du CV en base avec les données extraites
// -------------------------------------------------------

if ($cvId && $extractionOk) {
    try {
        $stmt = $db->prepare(
            "UPDATE cvs
             SET texte_extrait = ?, competences_extraites = ?, annees_experience = ?, formation = ?
             WHERE id = ?"
        );
        $stmt->execute([$texteExtrait, $competences, $anneesExp, $formation, $cvId]);
    } catch (PDOException $e) {
        error_log('[upload-cv] Erreur mise à jour données extraites : ' . $e->getMessage());
    }
}

// -------------------------------------------------------
// Redirection finale
// -------------------------------------------------------

if ($extractionOk) {
    flash('success', 'CV téléchargé et analysé par l\'IA avec succès !');
} else {
    flash('success', 'CV téléchargé avec succès. L\'analyse IA sera effectuée ultérieurement.');
}

header('Location: dashboard-candidat.php');
exit;