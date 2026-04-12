<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de sécurité invalide.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !validateEmail($email)) {
            $error = 'Veuillez entrer une adresse email valide.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, nom FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
                $db->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)")
                   ->execute([$email, $token, $expires]);

                $resetLink = env('APP_URL') . '/reinitialiser-mot-de-passe.php?token=' . $token;

                require_once __DIR__ . '/vendor/autoload.php';

                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = env('MAIL_HOST', 'smtp.gmail.com');
                    $mail->SMTPAuth   = true;
                    $mail->Username   = env('MAIL_USERNAME');
                    $mail->Password   = env('MAIL_PASSWORD');
                    $mail->SMTPSecure = 'tls';
                    $mail->Port       = 587;
                    $mail->CharSet    = 'UTF-8';

                    $mail->setFrom(env('MAIL_FROM'), env('MAIL_FROM_NAME', 'CVMatch'));
                    $mail->addAddress($email, $user['nom']);
                    $mail->Subject = 'Réinitialisation de votre mot de passe CVMatch';
                    $mail->isHTML(true);
                    $mail->Body = '
                    <div style="font-family:Inter,sans-serif;max-width:500px;margin:auto;padding:30px;background:#f8fafc;border-radius:16px;">
                        <h2 style="color:#3b82f6;">CVMatch IA</h2>
                        <p>Bonjour <strong>' . htmlspecialchars($user['nom']) . '</strong>,</p>
                        <p>Vous avez demandé la réinitialisation de votre mot de passe.</p>
                        <p>Cliquez sur le bouton ci-dessous (valable 1 heure) :</p>
                        <a href="' . $resetLink . '" style="display:inline-block;padding:14px 28px;background:#3b82f6;color:white;border-radius:10px;text-decoration:none;font-weight:600;margin:20px 0;">
                            Réinitialiser mon mot de passe
                        </a>
                        <p style="color:#94a3b8;font-size:12px;">Si vous n\'avez pas demandé cette réinitialisation, ignorez cet email.</p>
                    </div>';

                    $mail->send();
                    $success = 'Un email de réinitialisation a été envoyé à ' . htmlspecialchars($email) . '.';
                } catch (Exception $e) {
                    $error = 'Erreur lors de l\'envoi de l\'email. Veuillez réessayer.';
                }
            } else {
                $success = 'Si cet email existe, un lien de réinitialisation a été envoyé.';
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
    <title>CVMatch IA - Mot de passe oublié</title>
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
    </style>
</head>
<body>
<div class="card">
    <div class="back-link"><a href="connexion.php"><i class="fas fa-arrow-left"></i> Retour à la connexion</a></div>
    <div class="logo"><span>CV</span><span>Match IA</span></div>
    <p class="subtitle">Entrez votre email pour recevoir un lien de réinitialisation.</p>

    <?php if ($error): ?>
        <div class="message error"><i class="fas fa-exclamation-circle"></i> <?= clean($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="message success"><i class="fas fa-check-circle"></i> <?= clean($success) ?></div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST">
        <?= csrfField() ?>
        <div class="form-group">
            <label for="email">Adresse email</label>
            <input type="email" id="email" name="email" placeholder="votre@email.com" required autocomplete="email">
        </div>
        <button type="submit" class="btn"><i class="fas fa-paper-plane"></i> Envoyer le lien</button>
    </form>
    <?php endif; ?>

    <div class="footer">
        <a href="connexion.php">Se connecter</a> · <a href="inscription.php">S'inscrire</a>
    </div>
</div>
</body>
</html>