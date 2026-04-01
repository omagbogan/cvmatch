<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'], ['recruteur', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès non autorisé.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

$candidatId = (int)($body['candidat_id'] ?? 0);
$objet      = trim($body['objet'] ?? '');
$message    = trim($body['message'] ?? '');

if (!$candidatId || empty($objet) || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Données manquantes.']);
    exit;
}

// Vérifier que le candidat existe
$db   = getDB();
$stmt = $db->prepare("SELECT id, nom FROM users WHERE id = ? AND role = 'candidat'");
$stmt->execute([$candidatId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Candidat introuvable.']);
    exit;
}

$success = envoyerEmailSimule($_SESSION['user_id'], $candidatId, $objet, $message);
echo json_encode(['success' => $success]);
