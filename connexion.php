<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Rediriger si déjà connecté
if (isLoggedIn()) {
    $role = $_SESSION['user_role'];
    header('Location: ' . ($role === 'candidat' ? 'dashboard-candidat.php' : 'dashboard-recruteur.php'));
    exit;
}

$error = '';
$success = '';

// Traitement du formulaire POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Vérification CSRF
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de sécurité invalide. Veuillez réessayer.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Veuillez remplir tous les champs.';
        } elseif (!validateEmail($email)) {
            $error = 'Adresse email invalide.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, nom, email, password_hash, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Régénérer l'ID de session (sécurité)
                session_regenerate_id(true);

                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_nom']   = $user['nom'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role']  = $user['role'];

                flash('success', 'Bienvenue, ' . $user['nom'] . ' !');

                if ($user['role'] === 'candidat') {
                    header('Location: dashboard-candidat.php');
                } else {
                    header('Location: dashboard-recruteur.php');
                }
                exit;
            } else {
                $error = 'Email ou mot de passe incorrect.';
                // Petite pause pour limiter le brute-force
                sleep(1);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVMatch IA - Connexion</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .card { background: white; border-radius: 20px; padding: 40px; width: 450px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .logo { text-align: center; font-size: 24px; font-weight: 800; margin-bottom: 30px; }
        .logo span:first-child { color: #3b82f6; }
        .logo span:last-child { color: #1e293b; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #334155; font-size: 14px; }
        input { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-family: inherit; transition: border-color 0.2s; }
        input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .btn { width: 100%; padding: 14px; background: #3b82f6; color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.2s; }
        .btn:hover { background: #2563eb; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .footer { text-align: center; margin-top: 20px; font-size: 14px; color: #64748b; }
        .footer a { color: #3b82f6; text-decoration: none; }
        .message { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .message.error   { background: #fee2e2; color: #dc2626; }
        .message.success { background: #d1fae5; color: #059669; }
        .password-wrapper { position: relative; }
        .toggle-password { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #94a3b8; background: none; border: none; font-size: 16px; }
        .back-link { text-align: center; margin-bottom: 20px; }
        .back-link a { color: #64748b; text-decoration: none; font-size: 14px; }
        .back-link a:hover { color: #3b82f6; }
    </style>
</head>
<body>
<div class="card">
    <div class="back-link"><a href="index.php"><i class="fas fa-arrow-left"></i> Retour à l'accueil</a></div>
    <div class="logo"><span>CV</span><span>Match IA</span></div>

    <?php if ($error): ?>
        <div class="message error"><i class="fas fa-exclamation-circle"></i> <?= clean($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="message success"><i class="fas fa-check-circle"></i> <?= clean($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="connexion.php">
        <?= csrfField() ?>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email"
                   value="<?= clean($_POST['email'] ?? '') ?>"
                   placeholder="votre@email.com" required autocomplete="email">
        </div>

        <div class="form-group">
            <label for="password">Mot de passe</label>
            <div class="password-wrapper">
                <input type="password" id="password" name="password"
                       placeholder="••••••••" required autocomplete="current-password">
                <button type="button" class="toggle-password" onclick="togglePwd()">
                    <i class="fas fa-eye" id="eye-icon"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn">
            <i class="fas fa-sign-in-alt"></i> Se connecter
        </button>
    </form>

    <div class="footer">
        Pas encore de compte ? <a href="inscription.php">S'inscrire</a>
    </div>

    <!-- Comptes de démo -->
    <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8;">
        <strong style="color: #64748b;">Comptes de démonstration :</strong><br>
        Candidat : jean@candidat.ci / password123<br>
        Recruteur : recruteur@cvmatch.ci / password123
    </div>
</div>

<script>
function togglePwd() {
    const input = document.getElementById('password');
    const icon  = document.getElementById('eye-icon');
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
