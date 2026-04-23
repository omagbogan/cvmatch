<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

requireRole('candidat');
$user  = getCurrentUser();
$db    = getDB();
$flash = getFlash();

// Récupérer le profil complet
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$profil = $stmt->fetch();

// Récupérer tous les CVs
$stmt = $db->prepare("SELECT * FROM cvs WHERE user_id = ? ORDER BY uploaded_at DESC");
$stmt->execute([$user['id']]);
$cvs = $stmt->fetchAll();
$cv = $cvs[0] ?? null;

// Calcul de l'âge
$age = null;
if (!empty($profil['date_naissance'])) {
    $dob = new DateTime($profil['date_naissance']);
    $now = new DateTime();
    $age = (int)$now->diff($dob)->y;
}

// Label genre
function genreLabel(string $genre): string {
    return match($genre) {
        'homme' => '👨 Homme',
        'femme' => '👩 Femme',
        default => 'Non renseigné',
    };
}

// Traitement de la mise à jour du profil (POST)
$updateSuccess = '';
$updateError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $updateError = 'Token de sécurité invalide.';
    } elseif ($_POST['action'] === 'update_profil') {
        $nom            = trim($_POST['nom'] ?? '');
        $telephone      = trim($_POST['telephone'] ?? '');
        $ville          = trim($_POST['ville'] ?? '');
        $date_naissance = trim($_POST['date_naissance'] ?? '');
        $genre          = trim($_POST['genre'] ?? '');

        if (empty($nom)) {
            $updateError = 'Le nom est obligatoire.';
        } else {
            // Validation date
            $dn = null;
            if (!empty($date_naissance)) {
                $d = DateTime::createFromFormat('Y-m-d', $date_naissance);
                if ($d && $d->format('Y-m-d') === $date_naissance && $d < new DateTime()) {
                    $dn = $date_naissance;
                }
            }

            // Validation genre
            $gen = in_array($genre, ['homme', 'femme']) ? $genre : null;

            $stmt = $db->prepare(
                "UPDATE users SET nom = ?, telephone = ?, ville = ?, date_naissance = ?, genre = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$nom, $telephone, $ville, $dn, $gen, $user['id']]);
            $_SESSION['user_nom'] = $nom;
            $updateSuccess = 'Profil mis à jour avec succès !';

            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $profil = $stmt->fetch();

            // Recalcul âge
            $age = null;
            if (!empty($profil['date_naissance'])) {
                $dob = new DateTime($profil['date_naissance']);
                $age = (int)(new DateTime())->diff($dob)->y;
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
    <title>CVMatch IA - Espace Candidat</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; color: #1e293b; }
        :root {
            --primary: #3b82f6; --primary-dark: #2563eb; --secondary: #8b5cf6;
            --success: #10b981; --warning: #f59e0b;
            --gray-50: #f8fafc; --gray-100: #f1f5f9;
            --gray-200: #e2e8f0; --gray-300: #cbd5e1; --gray-500: #64748b;
            --gray-600: #475569; --gray-700: #334155; --gray-800: #1e293b;
            --radius: 16px; --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
        .navbar { background: white; box-shadow: var(--shadow-sm); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 1.5rem; font-weight: 800; background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; background-clip: text; color: transparent; text-decoration: none; }
        .logo span { background: none; color: var(--gray-800); }
        .user-info { display: flex; align-items: center; gap: 1rem; }
        .user-avatar { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 0.85rem; }
        .container { max-width: 1000px; margin: 0 auto; padding: 2rem; }
        .card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 1.5rem; margin-bottom: 1.5rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--gray-200); }
        .card-header h2 { font-size: 1.125rem; font-weight: 700; display: flex; align-items: center; flex-wrap: wrap; gap: .5rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.875rem; color: var(--gray-700); }
        input[type="text"], input[type="email"], input[type="tel"], input[type="date"] {
            width: 100%; padding: 0.75rem; border: 1px solid var(--gray-200); border-radius: 10px;
            font-size: 0.875rem; font-family: inherit; transition: border-color 0.2s;
        }
        select {
            width: 100%; padding: 0.75rem; border: 1px solid var(--gray-200); border-radius: 10px;
            font-size: 0.875rem; font-family: inherit; background: var(--gray-50);
            color: var(--gray-600); cursor: default; transition: border-color 0.2s;
            -webkit-appearance: none; appearance: none;
        }
        select.editable { background: white; color: var(--gray-800); cursor: pointer; }
        select.editable:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        input:read-only { background: var(--gray-50); cursor: default; color: var(--gray-600); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .btn { padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; font-family: inherit; font-size: 0.875rem; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: .4rem; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: transparent; border: 1px solid var(--gray-300); color: var(--gray-700); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }

        /* Badges profil */
        .age-badge { display: inline-flex; align-items: center; gap: 0.4rem; background: #fef3c7; color: #92400e; border-radius: 20px; padding: 0.25rem 0.75rem; font-size: 0.8rem; font-weight: 600; }
        .genre-badge { display: inline-flex; align-items: center; gap: 0.4rem; border-radius: 20px; padding: 0.25rem 0.75rem; font-size: 0.8rem; font-weight: 600; }
        .genre-badge.homme { background: #dbeafe; color: #1e40af; }
        .genre-badge.femme { background: #fce7f3; color: #9d174d; }

        /* Section IA */
        .ia-section { background: linear-gradient(135deg, #eff6ff 0%, #f5f3ff 100%); border: 1px solid #ddd6fe; border-radius: var(--radius); padding: 1.25rem; margin-top: 1.25rem; }
        .ia-section-header { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; }
        .ia-section-header h3 { font-size: 0.9rem; font-weight: 700; color: var(--secondary); }
        .ia-badge { background: var(--secondary); color: white; font-size: 0.65rem; font-weight: 700; padding: 0.2rem 0.5rem; border-radius: 20px; letter-spacing: 0.05em; text-transform: uppercase; }
        .ia-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .ia-field label { color: var(--gray-600); font-size: 0.8rem; }
        .ia-field .ia-value { background: white; border: 1px solid #ddd6fe; border-radius: 10px; padding: 0.75rem; font-size: 0.875rem; color: var(--gray-800); min-height: 44px; display: flex; align-items: center; flex-wrap: wrap; gap: 0.35rem; }
        .ia-field .ia-value.empty { color: var(--gray-400); font-style: italic; }
        .competence-tag { background: #ede9fe; color: #5b21b6; border-radius: 20px; padding: 0.2rem 0.65rem; font-size: 0.75rem; font-weight: 500; white-space: nowrap; }
        .exp-value { font-size: 1.25rem; font-weight: 800; color: var(--primary); }
        .exp-label { font-size: 0.75rem; color: var(--gray-500); margin-left: 0.25rem; }
        .ia-empty-notice { text-align: center; padding: 1rem; color: var(--gray-500); font-size: 0.85rem; }
        .ia-empty-notice i { display: block; font-size: 1.5rem; margin-bottom: 0.5rem; opacity: 0.4; }

        /* Upload */
        .upload-zone { border: 2px dashed var(--gray-300); border-radius: var(--radius); padding: 2.5rem; text-align: center; cursor: pointer; background: var(--gray-50); margin-bottom: 1rem; transition: all 0.3s; }
        .upload-zone:hover, .upload-zone.dragover { border-color: var(--primary); background: #eff6ff; }
        .upload-zone i { font-size: 2rem; color: var(--primary); margin-bottom: 0.75rem; display: block; }
        .upload-zone p { font-weight: 500; color: var(--gray-700); margin-bottom: 0.25rem; }
        .upload-zone small { color: var(--gray-500); font-size: 0.75rem; }
        .alert { padding: 0.875rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error   { background: #fee2e2; color: #dc2626; }
        .cv-actuel { background: var(--gray-50); border-radius: 10px; padding: 1rem; display: flex; align-items: center; gap: 1rem; margin-bottom: 0.5rem; }
        .cv-actuel i.cv-icon { font-size: 1.5rem; }
        .cv-info { flex: 1; }
        .cv-info strong { display: block; font-size: 0.875rem; }
        .cv-info span { font-size: 0.75rem; color: var(--gray-500); }
        .progress-bar { width: 100%; background: var(--gray-200); border-radius: 10px; height: 8px; margin-top: 0.5rem; display: none; }
        .progress-bar-fill { height: 100%; background: var(--primary); border-radius: 10px; transition: width 0.3s; }
        @media (max-width: 768px) { .form-row, .ia-fields { grid-template-columns: 1fr; } .container { padding: 1rem; } }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="index.php" class="logo">CV<span>Match</span> IA</a>
    <div class="user-info">
        <div class="user-avatar"><?= getInitiales($profil['nom']) ?></div>
        <span style="font-weight:500;font-size:.875rem;"><?= clean($profil['nom']) ?></span>
        <a href="logout.php" class="btn btn-outline">Déconnexion</a>
    </div>
</nav>

<div class="container">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
            <i class="fas fa-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= clean($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($updateSuccess): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= clean($updateSuccess) ?></div>
    <?php endif; ?>
    <?php if ($updateError): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= clean($updateError) ?></div>
    <?php endif; ?>

    <!-- ===================== CARTE PROFIL ===================== -->
    <div class="card">
        <div class="card-header">
            <h2>
                <i class="fas fa-user" style="color:var(--primary);"></i>
                Mon Profil
                <?php if ($age !== null): ?>
                    <span class="age-badge"><i class="fas fa-birthday-cake"></i> <?= $age ?> ans</span>
                <?php endif; ?>
                <?php if (!empty($profil['genre'])): ?>
                    <span class="genre-badge <?= clean($profil['genre']) ?>">
                        <?= $profil['genre'] === 'homme' ? '👨 Homme' : '👩 Femme' ?>
                    </span>
                <?php endif; ?>
            </h2>
            <button class="btn btn-outline" id="editBtn" onclick="toggleEdit()">
                <i class="fas fa-edit"></i> Modifier
            </button>
        </div>

        <form method="POST" action="dashboard-candidat.php" id="profilForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_profil">

            <div class="form-row">
                <div class="form-group">
                    <label>Nom complet</label>
                    <input type="text" name="nom" id="inputNom" value="<?= clean($profil['nom']) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="inputEmail" value="<?= clean($profil['email']) ?>" readonly>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="tel" name="telephone" id="inputTel"
                           value="<?= clean($profil['telephone'] ?? '') ?>" readonly placeholder="Non renseigné">
                </div>
                <div class="form-group">
                    <label>Ville</label>
                    <input type="text" name="ville" id="inputVille"
                           value="<?= clean($profil['ville'] ?? '') ?>" readonly placeholder="Non renseignée">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Date de naissance</label>
                    <input type="date" name="date_naissance" id="inputDob"
                           value="<?= clean($profil['date_naissance'] ?? '') ?>"
                           max="<?= date('Y-m-d', strtotime('-16 years')) ?>"
                           readonly>
                </div>
                <div class="form-group">
                    <label>Âge</label>
                    <input type="text" id="inputAge"
                           value="<?= $age !== null ? $age . ' ans' : 'Non renseigné' ?>" readonly>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Genre</label>
                    <select name="genre" id="inputGenre" disabled>
                        <option value="">-- Non renseigné --</option>
                        <option value="homme" <?= ($profil['genre'] ?? '') === 'homme' ? 'selected' : '' ?>>👨 Homme</option>
                        <option value="femme" <?= ($profil['genre'] ?? '') === 'femme' ? 'selected' : '' ?>>👩 Femme</option>
                    </select>
                </div>
                <div class="form-group">
                    <!-- Espace réservé pour équilibrer la grille -->
                </div>
            </div>

            <div id="saveBtn" style="display:none; text-align:right;">
                <button type="button" class="btn btn-outline" onclick="cancelEdit()" style="margin-right:.5rem;">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>

        <!-- Section IA -->
        <div class="ia-section">
            <div class="ia-section-header">
                <i class="fas fa-robot" style="color:var(--secondary);"></i>
                <h3>Analyse IA de votre CV</h3>
                <span class="ia-badge">DeepSeek</span>
            </div>

            <?php if ($cv && (!empty($cv['competences_extraites']) || $cv['annees_experience'] > 0)): ?>
                <div class="ia-fields">
                    <div class="ia-field" style="grid-column: 1 / -1;">
                        <label><i class="fas fa-tools" style="margin-right:.35rem;color:var(--secondary);"></i>Compétences détectées</label>
                        <div class="ia-value">
                            <?php
                            $competences = trim($cv['competences_extraites'] ?? '');
                            if ($competences):
                                foreach (array_filter(array_map('trim', explode(',', $competences))) as $tag):
                                    echo '<span class="competence-tag">' . clean($tag) . '</span>';
                                endforeach;
                            else: ?>
                                <span class="empty">Aucune compétence détectée</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ia-field">
                        <label><i class="fas fa-briefcase" style="margin-right:.35rem;color:var(--primary);"></i>Années d'expérience</label>
                        <div class="ia-value">
                            <?php if ($cv['annees_experience'] > 0): ?>
                                <span class="exp-value"><?= (int)$cv['annees_experience'] ?></span>
                                <span class="exp-label">an<?= $cv['annees_experience'] > 1 ? 's' : '' ?> d'expérience</span>
                            <?php else: ?>
                                <span class="empty">Non déterminé</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($cv['formation'])): ?>
                    <div class="ia-field">
                        <label><i class="fas fa-graduation-cap" style="margin-right:.35rem;color:var(--success);"></i>Formation</label>
                        <div class="ia-value"><?= clean($cv['formation']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <p style="font-size:.75rem;color:var(--gray-500);margin-top:.75rem;text-align:right;">
                    <i class="fas fa-info-circle"></i>
                    Extrait automatiquement depuis votre CV le <?= date('d/m/Y', strtotime($cv['uploaded_at'])) ?>
                </p>

            <?php elseif ($cv): ?>
                <div class="ia-empty-notice">
                    <i class="fas fa-hourglass-half"></i>
                    <p>CV uploadé mais analyse IA non encore effectuée.</p>
                    <small>Supprimez et re-uploadez votre CV pour lancer l'analyse.</small>
                </div>
            <?php else: ?>
                <div class="ia-empty-notice">
                    <i class="fas fa-file-upload"></i>
                    <p>Uploadez votre CV pour voir vos compétences et années d'expérience apparaître ici.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===================== CARTE CV ===================== -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-file-alt" style="color:var(--primary);margin-right:.5rem;"></i> Mes CVs</h2>
            <?php if (!empty($cvs)): ?>
                <span style="font-size:.8rem;color:var(--gray-500);background:var(--gray-100);padding:.25rem .75rem;border-radius:20px;">
                    <?= count($cvs) ?> CV(s) uploadé(s)
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($cvs)): ?>
            <?php foreach ($cvs as $index => $cvItem): ?>
            <div class="cv-actuel">
                <i class="fas fa-file-pdf cv-icon" style="color:#ef4444;"></i>
                <div class="cv-info">
                    <strong><?= clean($cvItem['fichier_original']) ?></strong>
                    <span>
                        <?= formatTaille($cvItem['taille_fichier']) ?> · Uploadé le <?= date('d/m/Y à H:i', strtotime($cvItem['uploaded_at'])) ?>
                        <?php if (!empty($cvItem['competences_extraites']) || $cvItem['annees_experience'] > 0): ?>
                            · <span style="color:var(--success);"><i class="fas fa-check-circle"></i> Analysé par IA</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($index === 0): ?>
                <span style="background:#d1fae5;color:#065f46;padding:.25rem .75rem;border-radius:20px;font-size:.75rem;font-weight:600;white-space:nowrap;">
                    <i class="fas fa-check"></i> Actif
                </span>
                <?php endif; ?>
                <a href="uploads/cvs/<?= clean($cvItem['fichier_stocke']) ?>" target="_blank"
                   class="btn btn-outline" style="padding:.25rem .75rem;font-size:.75rem;">
                    <i class="fas fa-eye"></i>
                </a>
                <form method="POST" action="supprimer-cv.php" style="display:inline;"
                      onsubmit="return confirm('Supprimer ce CV ?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="cv_id" value="<?= $cvItem['id'] ?>">
                    <button type="submit" class="btn btn-danger" style="padding:.25rem .75rem;font-size:.75rem;">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="POST" action="upload-cv.php" enctype="multipart/form-data" id="uploadForm" style="margin-top:1rem;">
            <?= csrfField() ?>
            <div class="upload-zone" id="uploadZone" onclick="document.getElementById('cvFile').click()">
                <i class="fas fa-cloud-upload-alt"></i>
                <p><?= !empty($cvs) ? 'Ajouter un autre CV' : 'Cliquez ou glissez votre CV ici' ?></p>
                <small>PDF, DOCX, JPG, PNG · Maximum 5 Mo · Analyse IA automatique</small>
                <input type="file" id="cvFile" name="cv_file" style="display:none"
                       accept=".pdf,.docx,.jpg,.jpeg,.png" onchange="previewFile(this)">
            </div>

            <div id="filePreview" style="display:none; background:var(--gray-50); border-radius:10px; padding:1rem; margin-bottom:1rem; gap:.75rem; align-items:center;">
                <i class="fas fa-file" style="font-size:1.5rem; color:var(--primary);"></i>
                <div style="flex:1;">
                    <strong id="fileName" style="font-size:.875rem;display:block;"></strong>
                    <span id="fileSize" style="font-size:.75rem; color:var(--gray-500);"></span>
                </div>
                <button type="button" onclick="clearFile()" class="btn btn-outline" style="padding:.25rem .75rem;">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="progress-bar" id="progressBar">
                <div class="progress-bar-fill" id="progressFill" style="width:0%"></div>
            </div>

            <button type="submit" class="btn btn-primary" id="uploadBtn" style="width:100%;" disabled>
                <i class="fas fa-upload"></i> Uploader le CV &amp; lancer l'analyse IA
            </button>
        </form>
    </div>

    <!-- ===================== STATISTIQUES ===================== -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-chart-bar" style="color:var(--primary);margin-right:.5rem;"></i> Activité</h2>
        </div>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr)); gap:1rem;">
            <div style="text-align:center; padding:1rem; background:var(--gray-50); border-radius:12px;">
                <div style="font-size:1.75rem; font-weight:800; color:var(--primary);"><?= count($cvs) ?></div>
                <div style="font-size:.8rem; color:var(--gray-500); margin-top:.25rem;">CV soumis</div>
            </div>
            <div style="text-align:center; padding:1rem; background:var(--gray-50); border-radius:12px;">
                <?php
                $stmtC = $db->prepare("SELECT COUNT(*) as total FROM contacts WHERE candidat_id = ?");
                $stmtC->execute([$user['id']]);
                $contactCount = $stmtC->fetch()['total'];
                ?>
                <div style="font-size:1.75rem; font-weight:800; color:var(--secondary);"><?= $contactCount ?></div>
                <div style="font-size:.8rem; color:var(--gray-500); margin-top:.25rem;">Contacts reçus</div>
            </div>
            <div style="text-align:center; padding:1rem; background:var(--gray-50); border-radius:12px;">
                <div style="font-size:1.75rem; font-weight:800; color:var(--success);">
                    <?= !empty($cvs) ? '✓' : '—' ?>
                </div>
                <div style="font-size:.8rem; color:var(--gray-500); margin-top:.25rem;">Profil visible</div>
            </div>
            <?php if ($cv && $cv['annees_experience'] > 0): ?>
            <div style="text-align:center; padding:1rem; background:var(--gray-50); border-radius:12px;">
                <div style="font-size:1.75rem; font-weight:800; color:var(--warning);"><?= (int)$cv['annees_experience'] ?></div>
                <div style="font-size:.8rem; color:var(--gray-500); margin-top:.25rem;">Ans d'exp. (IA)</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
let editMode = false;

function toggleEdit() {
    editMode = !editMode;

    // Champs input
    ['inputNom', 'inputTel', 'inputVille', 'inputDob'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (editMode) { el.removeAttribute('readonly'); el.style.background = 'white'; }
        else          { el.setAttribute('readonly', ''); el.style.background = ''; }
    });

    // Select genre
    const genreEl = document.getElementById('inputGenre');
    if (editMode) {
        genreEl.disabled = false;
        genreEl.classList.add('editable');
    } else {
        genreEl.disabled = true;
        genreEl.classList.remove('editable');
    }

    document.getElementById('editBtn').innerHTML = editMode
        ? '<i class="fas fa-times"></i> Annuler'
        : '<i class="fas fa-edit"></i> Modifier';
    document.getElementById('saveBtn').style.display = editMode ? 'block' : 'none';

    if (editMode) {
        document.getElementById('inputDob').addEventListener('change', updateAge);
    }
}

function updateAge() {
    const dob = document.getElementById('inputDob').value;
    const ageField = document.getElementById('inputAge');
    if (dob) {
        const birth = new Date(dob);
        const now   = new Date();
        let age = now.getFullYear() - birth.getFullYear();
        const m = now.getMonth() - birth.getMonth();
        if (m < 0 || (m === 0 && now.getDate() < birth.getDate())) age--;
        ageField.value = age + ' ans';
    } else {
        ageField.value = 'Non renseigné';
    }
}

function cancelEdit() {
    editMode = false;
    ['inputNom', 'inputTel', 'inputVille', 'inputDob'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.setAttribute('readonly', ''); el.style.background = ''; }
    });
    const genreEl = document.getElementById('inputGenre');
    genreEl.disabled = true;
    genreEl.classList.remove('editable');
    document.getElementById('editBtn').innerHTML = '<i class="fas fa-edit"></i> Modifier';
    document.getElementById('saveBtn').style.display = 'none';
    document.getElementById('profilForm').reset();
}

function previewFile(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        document.getElementById('fileName').textContent = file.name;
        document.getElementById('fileSize').textContent = formatBytes(file.size);
        document.getElementById('filePreview').style.display = 'flex';
        document.getElementById('uploadBtn').disabled = false;
    }
}

function clearFile() {
    document.getElementById('cvFile').value = '';
    document.getElementById('filePreview').style.display = 'none';
    document.getElementById('uploadBtn').disabled = true;
}

function formatBytes(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' Mo';
    if (bytes >= 1024)    return (bytes / 1024).toFixed(1) + ' Ko';
    return bytes + ' octets';
}

const zone = document.getElementById('uploadZone');
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragover');
    const dt = e.dataTransfer;
    if (dt.files.length) {
        document.getElementById('cvFile').files = dt.files;
        previewFile(document.getElementById('cvFile'));
    }
});
</script>
</body>
</html>