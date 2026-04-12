<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (!isLoggedIn() || $_SESSION['user_role'] !== 'candidat') {
    header('Location: connexion.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: dashboard-candidat.php');
    exit;
}

$cv_id = (int)($_POST['cv_id'] ?? 0);
if ($cv_id <= 0) {
    header('Location: dashboard-candidat.php');
    exit;
}

$db = getDB();

// Vérifier que le CV appartient bien à cet utilisateur
$stmt = $db->prepare("SELECT * FROM cvs WHERE id = ? AND user_id = ?");
$stmt->execute([$cv_id, $_SESSION['user_id']]);
$cv = $stmt->fetch();

if (!$cv) {
    flash('error', 'CV introuvable.');
    header('Location: dashboard-candidat.php');
    exit;
}

// Supprimer le fichier physique
$fichier = UPLOAD_DIR . $cv['fichier_stocke'];
if (file_exists($fichier)) {
    unlink($fichier);
}

// Supprimer en base
$db->prepare("DELETE FROM cvs WHERE id = ? AND user_id = ?")->execute([$cv_id, $_SESSION['user_id']]);

flash('success', 'CV supprimé avec succès.');
header('Location: dashboard-candidat.php');
exit;