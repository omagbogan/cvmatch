<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$user = isLoggedIn() ? getCurrentUser() : null;
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVMatch IA - Accueil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; color: #1e293b; }
        :root { --primary: #3b82f6; --primary-dark: #2563eb; --secondary: #8b5cf6; --success: #10b981; --gray-50: #f8fafc; --gray-100: #f1f5f9; --gray-200: #e2e8f0; --gray-300: #cbd5e1; --gray-400: #94a3b8; --gray-500: #64748b; --gray-600: #475569; --gray-700: #334155; --gray-800: #1e293b; --radius: 16px; --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 24px; }
        .navbar { background: white; box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 100; }
        .nav-container { max-width: 1200px; margin: 0 auto; padding: 1rem 24px; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 1.5rem; font-weight: 800; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); -webkit-background-clip: text; background-clip: text; color: transparent; text-decoration: none; }
        .logo span { background: none; color: var(--gray-800); }
        .nav-links { display: flex; gap: 2rem; align-items: center; }
        .nav-links a { text-decoration: none; color: var(--gray-600); font-weight: 500; transition: color 0.2s; }
        .nav-links a:hover { color: var(--primary); }
        .btn { padding: 0.5rem 1.25rem; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; border: none; font-size: 0.875rem; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-outline { background: transparent; border: 1px solid var(--gray-300); color: var(--gray-700); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .hero { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 4rem 0; color: white; margin-bottom: 2rem; }
        .hero h1 { font-size: 3rem; font-weight: 800; margin-bottom: 1rem; }
        .hero p { font-size: 1.125rem; opacity: 0.9; max-width: 600px; }
        .card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 1.5rem; text-align: center; transition: all 0.3s; }
        .card:hover { box-shadow: var(--shadow-md); transform: translateY(-5px); }
        .card h3 { margin: 1rem 0 0.5rem; }
        .user-avatar { width: 36px; height: 36px; background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 0.8rem; }
        .user-info { display: flex; align-items: center; gap: 0.75rem; }
        .footer { background: var(--gray-800); color: white; padding: 3rem 0 2rem; margin-top: 3rem; }
        .footer-bottom { text-align: center; padding-top: 2rem; margin-top: 2rem; border-top: 1px solid var(--gray-700); font-size: 0.75rem; color: var(--gray-400); }
        .alert { padding: 1rem; border-radius: 10px; margin: 1rem 0; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error   { background: #fee2e2; color: #dc2626; }
        @media (max-width: 768px) { .hero h1 { font-size: 2rem; } .nav-links { gap: 1rem; } }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="nav-container">
        <a href="index.php" class="logo">CV<span>Match</span> IA</a>
        <div class="nav-links">
            <a href="index.php">Accueil</a>
            <?php if ($user): ?>
                <?php if ($user['role'] === 'candidat'): ?>
                    <a href="dashboard-candidat.php">Mon Espace</a>
                <?php else: ?>
                    <a href="dashboard-recruteur.php">Dashboard</a>
                <?php endif; ?>
                <div class="user-info">
                    <div class="user-avatar"><?= getInitiales($user['nom']) ?></div>
                    <span style="font-weight:500;font-size:.875rem;"><?= clean($user['nom']) ?></span>
                    <a href="logout.php" class="btn btn-outline">Déconnexion</a>
                </div>
            <?php else: ?>
                <a href="connexion.php">Connexion</a>
                <a href="inscription.php" class="btn btn-primary">S'inscrire</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<?php if ($flash): ?>
<div class="container">
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
        <?= clean($flash['message']) ?>
    </div>
</div>
<?php endif; ?>

<div class="hero">
    <div class="container">
        <h1>Trouvez les talents parfaits<br>grâce à l'intelligence artificielle</h1>
        <p>CVMatch IA analyse automatiquement les CV et match les profils avec vos besoins en langage naturel.</p>
        <div style="margin-top: 2rem;">
            <?php if ($user): ?>
                <?php if ($user['role'] === 'candidat'): ?>
                    <a href="dashboard-candidat.php" class="btn btn-primary" style="margin-right: 1rem;">Mon Espace Candidat</a>
                <?php else: ?>
                    <a href="dashboard-recruteur.php" class="btn btn-primary" style="margin-right: 1rem;">Dashboard Recruteur</a>
                <?php endif; ?>
            <?php else: ?>
                <a href="inscription.php" class="btn btn-primary" style="margin-right: 1rem;">Commencer maintenant</a>
                <a href="connexion.php" class="btn btn-outline" style="background: rgba(255,255,255,0.2); color: white; border-color: white;">Se connecter</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="container">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; margin: 3rem 0;">
        <div class="card">
            <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: var(--primary);"></i>
            <h3>Soumettez votre CV</h3>
            <p style="color: var(--gray-500); font-size: 0.9rem;">PDF, Word ou photo. L'IA extrait automatiquement vos compétences.</p>
        </div>
        <div class="card">
            <i class="fas fa-brain" style="font-size: 2rem; color: var(--primary);"></i>
            <h3>Matching intelligent</h3>
            <p style="color: var(--gray-500); font-size: 0.9rem;">Notre IA analyse et score chaque profil selon votre recherche en français.</p>
        </div>
        <div class="card">
            <i class="fas fa-robot" style="font-size: 2rem; color: var(--primary);"></i>
            <h3>Assistant IA</h3>
            <p style="color: var(--gray-500); font-size: 0.9rem;">Posez des questions supplémentaires pour affiner votre recherche.</p>
        </div>
    </div>
</div>

<footer class="footer">
    <div class="container">
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> CVMatch IA - Tous droits réservés</p>
        </div>
    </div>
</footer>

</body>
</html>
