<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . ($_SESSION['user_role'] === 'candidat' ? 'dashboard-candidat.php' : 'dashboard-recruteur.php'));
    exit;
}

$error   = '';
$success = '';
$tab     = 'candidat'; // onglet actif par défaut

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de sécurité invalide.';
    } else {
        $tab       = in_array($_POST['tab'] ?? '', ['candidat', 'recruteur']) ? $_POST['tab'] : 'candidat';
        $nom       = trim($_POST['nom'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $telephone = trim($_POST['telephone'] ?? '');
        $ville     = trim($_POST['ville'] ?? '');
        $entreprise= trim($_POST['entreprise'] ?? '');
        $role      = ($tab === 'recruteur') ? 'recruteur' : 'candidat';

        // Validations
        if (empty($nom) || empty($email) || empty($password)) {
            $error = 'Veuillez remplir tous les champs obligatoires.';
        } elseif (!validateEmail($email)) {
            $error = 'Adresse email invalide.';
        } elseif (!validatePassword($password)) {
            $error = 'Le mot de passe doit contenir au moins 6 caractères.';
        } else {
            $db = getDB();

            // Vérifier si l'email existe déjà
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Cette adresse email est déjà utilisée.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("
                    INSERT INTO users (nom, email, password_hash, role, telephone, ville, entreprise)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nom, $email, $hash, $role, $telephone, $ville, $entreprise ?: null]);

                flash('success', 'Compte créé avec succès ! Connectez-vous maintenant.');
                header('Location: connexion.php');
                exit;
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
    <title>CVMatch IA - Inscription</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .container { max-width: 500px; width: 100%; }
        .card { background: white; border-radius: 20px; padding: 40px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .logo { text-align: center; font-size: 24px; font-weight: 800; margin-bottom: 30px; }
        .logo span:first-child { color: #3b82f6; }
        .logo span:last-child { color: #1e293b; }
        .tabs { display: flex; gap: 10px; margin-bottom: 30px; }
        .tab { flex: 1; padding: 12px; text-align: center; cursor: pointer; border-radius: 10px; background: #f1f5f9; font-weight: 600; font-size: 14px; transition: all 0.3s; border: none; font-family: inherit; }
        .tab.active { background: #3b82f6; color: white; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #334155; font-size: 14px; }
        input { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-family: inherit; transition: border-color 0.2s; }
        input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .btn { width: 100%; padding: 14px; background: #3b82f6; color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.2s; }
        .btn:hover { background: #2563eb; }
        .footer { text-align: center; margin-top: 20px; font-size: 14px; color: #64748b; }
        .footer a { color: #3b82f6; text-decoration: none; }
        .message { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .message.error   { background: #fee2e2; color: #dc2626; }
        .message.success { background: #d1fae5; color: #059669; }
        .back-link { text-align: center; margin-bottom: 20px; }
        .back-link a { color: #64748b; text-decoration: none; font-size: 14px; }
        .back-link a:hover { color: #3b82f6; }
        .required { color: #ef4444; }
        .form-section { display: none; }
        .form-section.active { display: block; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="back-link"><a href="index.php"><i class="fas fa-arrow-left"></i> Retour à l'accueil</a></div>
        <div class="logo"><span>CV</span><span>Match IA</span></div>

        <!-- Onglets -->
        <div class="tabs">
            <button type="button" class="tab <?= $tab === 'candidat'  ? 'active' : '' ?>" onclick="switchTab('candidat')">👨‍💼 Candidat</button>
            <button type="button" class="tab <?= $tab === 'recruteur' ? 'active' : '' ?>" onclick="switchTab('recruteur')">🏢 Recruteur</button>
        </div>

        <?php if ($error): ?>
            <div class="message error"><i class="fas fa-exclamation-circle"></i> <?= clean($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="inscription.php" id="inscriptionForm">
            <?= csrfField() ?>
            <input type="hidden" name="tab" id="tabInput" value="<?= clean($tab) ?>">

            <!-- Formulaire Candidat -->
            <div class="form-section <?= $tab === 'candidat' ? 'active' : '' ?>" id="section-candidat">
                <div class="form-group">
                    <label>Nom complet <span class="required">*</span></label>
                    <input type="text" name="nom" placeholder="Jean Dupont"
                           value="<?= ($tab === 'candidat') ? clean($_POST['nom'] ?? '') : '' ?>" required <?= $tab !== 'candidat' ? 'disabled' : '' ?>>
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" placeholder="jean@email.ci"
                           value="<?= ($tab === 'candidat') ? clean($_POST['email'] ?? '') : '' ?>" required <?= $tab !== 'candidat' ? 'disabled' : '' ?>>
                </div>
                <div class="form-group">
                    <label>Mot de passe <span class="required">*</span></label>
                    <input type="password" name="password" placeholder="Minimum 6 caractères" minlength="6" required <?= $tab !== 'candidat' ? 'disabled' : '' ?>>
                </div>
                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="tel" name="telephone" placeholder="+225 07 XX XX XX"
                           value="<?= ($tab === 'candidat') ? clean($_POST['telephone'] ?? '') : '' ?>" <?= $tab !== 'candidat' ? 'disabled' : '' ?>>
                </div>
                <div class="form-group">
                    <label>Ville</label>
                    <input type="text" name="ville" placeholder="Abidjan"
                           value="<?= ($tab === 'candidat') ? clean($_POST['ville'] ?? '') : '' ?>" <?= $tab !== 'candidat' ? 'disabled' : '' ?>>
                </div>
            </div>

            <!-- Formulaire Recruteur -->
            <div class="form-section <?= $tab === 'recruteur' ? 'active' : '' ?>" id="section-recruteur">
                <div class="form-group">
                    <label>Nom complet <span class="required">*</span></label>
                    <input type="text" name="nom" placeholder="Marie Martin"
                           value="<?= ($tab === 'recruteur') ? clean($_POST['nom'] ?? '') : '' ?>" required <?= $tab !== 'recruteur' ? 'disabled' : '' ?>>
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" placeholder="marie@entreprise.ci"
                           value="<?= ($tab === 'recruteur') ? clean($_POST['email'] ?? '') : '' ?>" required <?= $tab !== 'recruteur' ? 'disabled' : '' ?>>
                </div>
                <div class="form-group">
                    <label>Mot de passe <span class="required">*</span></label>
                    <input type="password" name="password" placeholder="Minimum 6 caractères" minlength="6" required <?= $tab !== 'recruteur' ? 'disabled' : '' ?>>
                </div>
                <div class="form-group">
                    <label>Nom de l'entreprise</label>
                    <input type="text" name="entreprise" placeholder="TechCorp CI"
                           value="<?= ($tab === 'recruteur') ? clean($_POST['entreprise'] ?? '') : '' ?>" <?= $tab !== 'recruteur' ? 'disabled' : '' ?>>
                </div>
            </div>

            <button type="submit" class="btn">
                <i class="fas fa-user-plus"></i> S'inscrire
            </button>
        </form>

        <div class="footer">Déjà un compte ? <a href="connexion.php">Se connecter</a></div>
    </div>
</div>

<script>
function switchTab(tab) {
    document.getElementById('tabInput').value = tab;
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
    document.querySelector(`[onclick="switchTab('${tab}')"]`).classList.add('active');
    document.getElementById(`section-${tab}`).classList.add('active');

    // Désactiver les champs de la section inactive pour éviter l'envoi de doublons
    document.querySelectorAll('.form-section').forEach(section => {
        const isActive = section.id === `section-${tab}`;
        section.querySelectorAll('input').forEach(input => {
            input.disabled = !isActive;
            if (isActive && ['nom', 'email', 'password'].includes(input.name)) {
                input.required = true;
            } else if (!isActive) {
                input.required = false;
            }
        });
    });
}
</script>
</body>
</html>
