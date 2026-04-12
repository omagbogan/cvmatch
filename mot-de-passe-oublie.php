<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$token = trim($_GET['token'] ?? '');
$validToken = false;

if (empty($token)) {
    $error = 'Lien invalide ou expiré.';
} else {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        $error = 'Ce lien est invalide ou a expiré. Veuillez faire une nouvelle demande.';
    } else {
        $validToken = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de sécurité invalide.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif ($password !== $confirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?")
               ->execute([$hash, $reset['email']]);
            $db->prepare("UPDATE password_resets SET used = 1 WHERE token = ?")
               ->execute([$token]);

            $success = 'Mot de passe mis à jour avec succès ! Vous pouvez maintenant vous connecter.';
            $validToken = false;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVMatch IA - Réinitialiser le mot de passe</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .card { background: white; border-radius: 20px; padding: 40px; width: 450px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .logo { text-align: center; font-size: 24px; font-weight: 800; margin-bottom: 10px; }
        .logo span:first-child { color: #3b82f6; }
        .logo span:last-child { color: #1e293b; }
        .subtitle { text-align: center; color: #64748b; font-size: 14px; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #334155; font-size: 14px; }
        input { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-family: inherit; transition: border-color 0.2s; }
        input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .btn { width: 100%; padding: 14px; background: #3b82f6; color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.2s; }
        .btn:hover { background: #2563eb; }
        .footer { text-align: center; margin-top: 20px; font-size: 14px; color: #64748b; }
        .footer a { color: #3b82f6; text-decoration: none; }
        .message { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .message.error { background: #fee2e2; color: #dc2626; }
        .message.success { background: #d1fae5; color: #059669; }
        .back-link { text-align: center; margin-bottom: 20px; }
        .back-link a { color: #64748b; text-decoration: none; font-size: 14px; }
        .password-wrapper { position: relative; }
        .toggle-password { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #94a3b8; background: none; border: none; font-size: 16px; }
    </style>
</head>
<body>
<div class="card">
    <div class="back-link"><a href="connexion.php"><i class="fas fa-arrow-left"></i> Retour à la connexion</a></div>
    <div class="logo"><span>CV</span><span>Match IA</span></div>
    <p class="subtitle">Choisissez un nouveau mot de passe.</p>

    <?php if ($error): ?>
        <div class="message error"><i class="fas fa-exclamation-circle"></i> <?= clean($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="message success"><i class="fas fa-check-circle"></i> <?= clean($success) ?></div>
    <?php endif; ?>

    <?php if ($validToken): ?>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= clean($token) ?>">
        <div class="form-group">
            <label for="password">Nouveau mot de passe</label>
            <div class="password-wrapper">
                <input type="password" id="password" name="password" placeholder="••••••••" required minlength="8">
                <button type="button" class="toggle-password" onclick="togglePwd('password', 'eye1')">
                    <i class="fas fa-eye" id="eye1"></i>
                </button>
            </div>
        </div>
        <div class="form-group">
            <label for="confirm_password">Confirmer le mot de passe</label>
            <div class="password-wrapper">
                <input type="password" id="confirm_password" name="confirm_password" placeholder="••••••••" required minlength="8">
                <button type="button" class="toggle-password" onclick="togglePwd('confirm_password', 'eye2')">
                    <i class="fas fa-eye" id="eye2"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="btn"><i class="fas fa-lock"></i> Réinitialiser le mot de passe</button>
    </form>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="footer"><a href="connexion.php">Se connecter maintenant</a></div>
    <?php elseif (!$validToken && !$success): ?>
    <div class="footer"><a href="mot-de-passe-oublie.php">Faire une nouvelle demande</a></div>
    <?php endif; ?>
</div>
<script>
function togglePwd(fieldId, iconId) {
    const input = document.getElementById(fieldId);
    const icon = document.getElementById(iconId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>
</body>
</html>