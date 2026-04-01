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

if ($result['success']) {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            "INSERT INTO cvs (user_id, fichier_original, fichier_stocke, type_fichier, taille_fichier) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $_SESSION['user_id'],
            $result['original'],
            $result['filename'],
            $result['mime'],
            $result['size'],
        ]);
    } catch (PDOException $e) {
        $message = 'CV téléchargé, mais impossible d\'enregistrer l\'historique en base de données.';
        flash('error', $message);
        header('Location: dashboard-candidat.php');
        exit;
    }

    flash('success', $message);
    header('Location: dashboard-candidat.php');
} else {
    flash('error', $message);
    header('Location: dashboard-candidat.php');
}
exit;
