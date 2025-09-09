<?php
// Configuration des sessions pour Apache
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Configuration pour la production
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Configuration pour SQLite sur serveur
ini_set('sqlite3.defensive', 0);

// Charger la configuration
$config = file_exists('config.php') ? require('config.php') : require('config.ref.php');

class PollSystem {
    private $db;
    private $config;
    
    public function __construct($config) {
        $this->config = $config;
        $this->initDatabase();
    }
    
    private function initDatabase() {
        $dbPath = $this->config['database']['path'] ?? 'polls.db';
        
        // S'assurer que le chemin est absolu (compatible PHP < 8)
        if (substr($dbPath, 0, 1) !== '/') {
            $dbPath = __DIR__ . '/' . $dbPath;
        }
        
        // Créer le répertoire si nécessaire
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        
        // Vérifier les permissions avant de créer la base
        if (!is_writable($dbDir)) {
            throw new Exception("Le répertoire de la base de données n'est pas accessible en écriture: $dbDir");
        }
        
        $this->db = new PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Définir les permissions sur le fichier de base de données
        if (file_exists($dbPath)) {
            chmod($dbPath, 0644);
        }
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS polls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            header_html TEXT,
            options TEXT,
            allow_multiple INTEGER DEFAULT 0,
            has_other INTEGER DEFAULT 1,
            end_date TEXT,
            thank_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS votes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_code TEXT,
            selected_options TEXT,
            other_comment TEXT,
            ip_address TEXT,
            voted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }
    
    public function checkAdminPassword($password) {
        $adminPassword = $this->config['admin_password'] ?? 'admin123';
        return $password === $adminPassword;
    }
    
    public function isAdmin() {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
    }
    
    public function getConfig() {
        return $this->config;
    }
    
    public function getPollUrl($pollCode) {
        // Génère l'URL publique courte du sondage
        return htmlspecialchars($pollCode);
    }
    
    public function generateUniqueCode() {
        do {
            // Génère un code de type POLL-YYYYMMDD-XXXX
            $code = 'POLL-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 4));
        } while ($this->codeExists($code));
        return $code;
    }
    
    public function codeExists($code) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM polls WHERE code = ?");
        $stmt->execute([$code]);
        return $stmt->fetchColumn() > 0;
    }
    
    public function createPoll($data) {
        $stmt = $this->db->prepare("INSERT INTO polls (code, header_html, options, allow_multiple, has_other, end_date, thank_message) VALUES (?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $data['code'],
            $data['header_html'],
            json_encode($data['options']),
            $data['allow_multiple'] ? 1 : 0,
            $data['has_other'] ? 1 : 0,
            $data['end_date'],
            $data['thank_message']
        ]);
    }
    
    public function getPoll($code) {
        $stmt = $this->db->prepare("SELECT * FROM polls WHERE code = ?");
        $stmt->execute([$code]);
        $poll = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($poll) {
            $poll['options'] = json_decode($poll['options'], true);
        }
        return $poll;
    }
    
    public function getAllPolls() {
        $stmt = $this->db->query("SELECT * FROM polls ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function updatePoll($originalCode, $data) {
        // Si le code a changé, mettre à jour aussi les votes associés
        if ($originalCode !== $data['code']) {
            $stmt = $this->db->prepare("UPDATE votes SET poll_code = ? WHERE poll_code = ?");
            $stmt->execute([$data['code'], $originalCode]);
        }
        
        $stmt = $this->db->prepare("UPDATE polls SET code = ?, header_html = ?, options = ?, allow_multiple = ?, has_other = ?, end_date = ?, thank_message = ? WHERE code = ?");
        return $stmt->execute([
            $data['code'],
            $data['header_html'],
            json_encode($data['options']),
            $data['allow_multiple'] ? 1 : 0,
            $data['has_other'] ? 1 : 0,
            $data['end_date'],
            $data['thank_message'],
            $originalCode
        ]);
    }
    
    public function duplicatePoll($originalCode, $newCode) {
        $originalPoll = $this->getPoll($originalCode);
        if (!$originalPoll) return false;
        
        // Créer le nouveau sondage avec un nouveau code
        return $this->createPoll([
            'code' => $newCode,
            'header_html' => $originalPoll['header_html'],
            'options' => $originalPoll['options'],
            'allow_multiple' => $originalPoll['allow_multiple'],
            'has_other' => $originalPoll['has_other'],
            'end_date' => date('Y-m-d\TH:i', strtotime('+15 days')), // Nouvelle date par défaut
            'thank_message' => $originalPoll['thank_message']
        ]);
    }
    
    public function deletePoll($code) {
        // Supprimer d'abord les votes associés
        $stmt = $this->db->prepare("DELETE FROM votes WHERE poll_code = ?");
        $stmt->execute([$code]);
        
        // Puis supprimer le sondage
        $stmt = $this->db->prepare("DELETE FROM polls WHERE code = ?");
        return $stmt->execute([$code]);
    }
    
    public function submitVote($poll_code, $selected_options, $other_comment = '') {
        try {
            // Obtenir l'adresse IP réelle même derrière un proxy
            $ip = $this->getRealIpAddr();
            
            // Vérifier si cette IP a déjà voté
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM votes WHERE poll_code = ? AND ip_address = ?");
            $stmt->execute([$poll_code, $ip]);
            if ($stmt->fetchColumn() > 0) {
                return false;
            }
            
            // Vérifier que le sondage existe
            if (!$this->getPoll($poll_code)) {
                return false;
            }
            
            // Insérer le vote
            $stmt = $this->db->prepare("INSERT INTO votes (poll_code, selected_options, other_comment, ip_address) VALUES (?, ?, ?, ?)");
            $result = $stmt->execute([$poll_code, json_encode($selected_options), $other_comment, $ip]);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Erreur lors du vote: " . $e->getMessage());
            return false;
        }
    }
    
    private function getRealIpAddr() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }
    
    public function getResults($poll_code) {
        $poll = $this->getPoll($poll_code);
        if (!$poll) return null;
        
        $stmt = $this->db->prepare("SELECT * FROM votes WHERE poll_code = ?");
        $stmt->execute([$poll_code]);
        $votes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [];
        foreach ($poll['options'] as $option) {
            $results[$option] = 0;
        }
        
        $other_comments = [];
        foreach ($votes as $vote) {
            $selected = json_decode($vote['selected_options'], true);
            if (is_array($selected)) {
                foreach ($selected as $option) {
                    // Nettoyer l'option du vote pour la comparaison
                    $cleanOption = trim(str_replace("\r", "", $option));
                    
                    if (isset($results[$cleanOption])) {
                        $results[$cleanOption]++;
                    } else {
                        // Si pas de correspondance exacte, chercher par similarité
                        foreach (array_keys($results) as $availableOption) {
                            if (trim($availableOption) === $cleanOption) {
                                $results[$availableOption]++;
                                break;
                            }
                        }
                    }
                }
            }
            if (!empty($vote['other_comment'])) {
                $other_comments[] = $vote['other_comment'];
            }
        }
        
        return [
            'poll' => $poll,
            'results' => $results,
            'other_comments' => $other_comments,
            'total_votes' => count($votes)
        ];
    }
    
    public function exportToMarkdown($poll_code) {
        $data = $this->getResults($poll_code);
        if (!$data) return '';
        
        $md = "# Résultats du sondage: " . $data['poll']['code'] . "\n\n";
        $md .= "**Total des votes:** " . $data['total_votes'] . "\n\n";
        $md .= "## Résultats\n\n";
        
        foreach ($data['results'] as $option => $count) {
            $percentage = $data['total_votes'] > 0 ? round(($count / $data['total_votes']) * 100, 2) : 0;
            $md .= "- **$option**: $count votes ($percentage%)\n";
        }
        
        if (!empty($data['other_comments'])) {
            $md .= "\n## Commentaires \"Autre\"\n\n";
            foreach ($data['other_comments'] as $comment) {
                $md .= "- $comment\n";
            }
        }
        
        return $md;
    }
}

$pollSystem = new PollSystem($config);

$action = $_GET['action'] ?? '';
$poll_code = $_GET['code'] ?? '';

// Endpoint AJAX pour vérifier l'existence d'un code
if ($action === 'check_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $code = $_POST['code'] ?? '';
    echo json_encode(['exists' => $pollSystem->codeExists($code)]);
    exit;
}

// Gérer aussi l'URL de type /index.php/CODE si mod_rewrite n'est pas disponible
if (empty($poll_code) && !empty($_SERVER['PATH_INFO'])) {
    $path_parts = explode('/', trim($_SERVER['PATH_INFO'], '/'));
    if (!empty($path_parts[0])) {
        $poll_code = $path_parts[0];
    }
}

// Si l'utilisateur est déjà admin et accède à la racine, rediriger vers admin
if (empty($action) && empty($poll_code) && $pollSystem->isAdmin()) {
    header('Location: ?action=admin');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['admin_code'])) {
        if ($pollSystem->checkAdminPassword($_POST['admin_code'])) {
            $_SESSION['is_admin'] = true;
            header('Location: ?action=admin');
            exit;
        } else {
            $error = 'Code administrateur incorrect';
        }
    } elseif (isset($_POST['logout'])) {
        unset($_SESSION['is_admin']);
        header('Location: /');
        exit;
    } elseif (isset($_POST['delete_poll']) && $pollSystem->isAdmin()) {
        $pollSystem->deletePoll($_POST['poll_code']);
        $_SESSION['success'] = 'Sondage supprimé avec succès.';
        header('Location: ?action=admin');
        exit;
    } elseif (isset($_POST['duplicate_poll']) && $pollSystem->isAdmin()) {
        $originalCode = $_POST['poll_code'];
        $newCode = $pollSystem->generateUniqueCode();
        if ($pollSystem->duplicatePoll($originalCode, $newCode)) {
            $_SESSION['success'] = "Sondage dupliqué avec le code '$newCode'.";
        } else {
            $_SESSION['error'] = 'Erreur lors de la duplication du sondage.';
        }
        header('Location: ?action=admin');
        exit;
    } elseif (isset($_POST['update_poll']) && $pollSystem->isAdmin()) {
        $originalCode = $_POST['original_code'];
        $newCode = trim($_POST['poll_code']);
        
        // Vérifier si le code a changé et s'il existe déjà
        if ($originalCode !== $newCode && $pollSystem->codeExists($newCode)) {
            $_SESSION['error'] = "Le code '$newCode' existe déjà. Veuillez en choisir un autre.";
            $_SESSION['form_data'] = $_POST;
            $_SESSION['editing_poll'] = $originalCode;
            header('Location: ?action=admin');
            exit;
        }
        
        // Nettoyer les options en supprimant les retours à la ligne
        $options = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $_POST['options']))));
        $poll_data = [
            'code' => $newCode,
            'header_html' => !empty($_POST['header_html']) ? $_POST['header_html'] : $config['default_header'],
            'options' => $options,
            'allow_multiple' => isset($_POST['allow_multiple']),
            'has_other' => isset($_POST['has_other']),
            'end_date' => $_POST['end_date'],
            'thank_message' => !empty($_POST['thank_message']) ? $_POST['thank_message'] : $config['default_thank_message']
        ];
        
        if ($pollSystem->updatePoll($originalCode, $poll_data)) {
            $_SESSION['success'] = "Le sondage a été mis à jour avec succès.";
        } else {
            $_SESSION['error'] = 'Erreur lors de la mise à jour du sondage.';
        }
        header('Location: ?action=admin');
        exit;
    } elseif (isset($_POST['create_poll']) && $pollSystem->isAdmin()) {
        $poll_code = trim($_POST['poll_code']);
        
        // Vérifier si le code existe déjà
        if ($pollSystem->codeExists($poll_code)) {
            $_SESSION['error'] = "Le code '$poll_code' existe déjà. Veuillez en choisir un autre.";
            $_SESSION['form_data'] = $_POST; // Sauvegarder les données du formulaire
            header('Location: ?action=admin');
            exit;
        }
        
        // Nettoyer les options en supprimant les retours à la ligne
        $options = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $_POST['options']))));
        $poll_data = [
            'code' => $poll_code,
            'header_html' => !empty($_POST['header_html']) ? $_POST['header_html'] : $config['default_header'],
            'options' => $options,
            'allow_multiple' => isset($_POST['allow_multiple']),
            'has_other' => isset($_POST['has_other']),
            'end_date' => $_POST['end_date'],
            'thank_message' => !empty($_POST['thank_message']) ? $_POST['thank_message'] : $config['default_thank_message']
        ];
        
        if ($pollSystem->createPoll($poll_data)) {
            $_SESSION['success'] = "Le sondage '$poll_code' a été créé avec succès.";
        }
        header('Location: ?action=admin');
        exit;
    } elseif (isset($_POST['vote']) && !empty($poll_code)) {
        $selected_options = $_POST['options'] ?? [];
        // S'assurer que c'est toujours un tableau
        if (!is_array($selected_options)) {
            $selected_options = !empty($selected_options) ? [$selected_options] : [];
        }
        
        // Nettoyer les options sélectionnées (supprimer \r\n)
        $selected_options = array_map(function($option) {
            return trim(str_replace("\r", "", $option));
        }, $selected_options);
        $other_comment = $_POST['other_comment'] ?? '';
        
        // Valider que des options ont été sélectionnées
        if (empty($selected_options) && empty($other_comment)) {
            header("Location: ?code=$poll_code&error=no_selection");
            exit;
        }
        
        $voteResult = $pollSystem->submitVote($poll_code, $selected_options, $other_comment);
        
        if ($voteResult) {
            header("Location: ?code=$poll_code&voted=1");
            exit;
        } else {
            // L'utilisateur a déjà voté ou erreur
            header("Location: ?code=$poll_code&already_voted=1");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Système de Sondage</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Quill Editor -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
</head>
<body>
    <div class="container">
        <?php if ($action === 'admin' && $pollSystem->isAdmin()): ?>
            <div class="admin-panel fade-in">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h1 style="margin: 0;">Interface Administrateur</h1>
                    <form method="POST" style="margin: 0;">
                        <button type="submit" name="logout" class="btn">Déconnexion</button>
                    </form>
                </div>
                
                <?php 
                // Gérer les messages d'erreur et de succès
                if (isset($_SESSION['error'])): ?>
                    <div style="background: #e74c3c; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                        <?= htmlspecialchars($_SESSION['error']) ?>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div style="background: #2ecc71; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                        <?= htmlspecialchars($_SESSION['success']) ?>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                
                <?php
                // Gérer le mode édition
                $editingPoll = null;
                $isEditing = false;
                if (isset($_SESSION['editing_poll'])) {
                    $editingPoll = $pollSystem->getPoll($_SESSION['editing_poll']);
                    $isEditing = true;
                    unset($_SESSION['editing_poll']);
                } elseif (isset($_GET['edit'])) {
                    $editingPoll = $pollSystem->getPoll($_GET['edit']);
                    $isEditing = true;
                }
                
                $defaultCode = $pollSystem->generateUniqueCode();
                $formData = $_SESSION['form_data'] ?? [];
                unset($_SESSION['form_data']);
                
                // Utiliser les données du sondage en édition ou les données du formulaire
                if ($isEditing && $editingPoll) {
                    $formData = array_merge([
                        'poll_code' => $editingPoll['code'],
                        'header_html' => $editingPoll['header_html'],
                        'options' => implode("\n", $editingPoll['options']),
                        'allow_multiple' => $editingPoll['allow_multiple'],
                        'has_other' => $editingPoll['has_other'],
                        'end_date' => $editingPoll['end_date'],
                        'thank_message' => $editingPoll['thank_message']
                    ], $formData);
                }
                ?>
                
                <div class="create-poll-form">
                    <h2><?= $isEditing ? 'Modifier le sondage' : 'Créer un nouveau sondage' ?></h2>
                    <form method="POST" onsubmit="return validateForm()">
                        <?php if ($isEditing): ?>
                            <input type="hidden" name="original_code" value="<?= htmlspecialchars($editingPoll['code']) ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="poll_code">Code unique du sondage:</label>
                            <input type="text" id="poll_code" name="poll_code" value="<?= htmlspecialchars($formData['poll_code'] ?? $defaultCode) ?>" required>
                            <small style="color: #666;"><?= $isEditing ? 'Vous pouvez modifier le code du sondage' : 'Code généré automatiquement, vous pouvez le modifier' ?></small>
                        </div>
                        
                        <div class="form-group">
                            <label for="header_html">HTML d'en-tête (laisser vide pour utiliser le header par défaut):</label>
                            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                <button type="button" id="btn_editor_header" class="btn btn-small">Editeur visuel</button>
                                <button type="button" id="btn_preview_header" class="btn btn-small">Prévisualiser</button>
                            </div>
                            <div id="header_html_editor" style="display: none; height: 150px; margin-bottom: 10px;"></div>
                            <textarea id="header_html" name="header_html" rows="3"><?= htmlspecialchars($formData['header_html'] ?? '') ?></textarea>
                            <div id="header_html_preview" style="display: none; margin-top: 10px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background: white;"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="options">Options (une par ligne):</label>
                            <textarea id="options" name="options" rows="5" required><?= htmlspecialchars($formData['options'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="allow_multiple" <?= (!empty($formData['allow_multiple']) ? 'checked' : '') ?>> Choix multiples autorisés
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="has_other" <?= (isset($formData['has_other']) ? ($formData['has_other'] ? 'checked' : '') : 'checked') ?>> Inclure l'option "Autre"
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date">Date de fin:</label>
                            <?php 
                            $defaultEndDate = $formData['end_date'] ?? date('Y-m-d\TH:i', strtotime('+15 days'));
                            ?>
                            <input type="datetime-local" id="end_date" name="end_date" value="<?= $defaultEndDate ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="thank_message">Message de remerciement (HTML - laisser vide pour utiliser le message par défaut):</label>
                            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                <button type="button" id="btn_editor_thank" class="btn btn-small">Editeur visuel</button>
                                <button type="button" id="btn_preview_thank" class="btn btn-small">Prévisualiser</button>
                            </div>
                            <div id="thank_message_editor" style="display: none; height: 150px; margin-bottom: 10px;"></div>
                            <textarea id="thank_message" name="thank_message" rows="3"><?= htmlspecialchars($formData['thank_message'] ?? '') ?></textarea>
                            <div id="thank_message_preview" style="display: none; margin-top: 10px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background: white;"></div>
                        </div>
                        
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" name="<?= $isEditing ? 'update_poll' : 'create_poll' ?>" class="btn btn-primary">
                                <?= $isEditing ? 'Mettre à jour' : 'Créer le sondage' ?>
                            </button>
                            <?php if ($isEditing): ?>
                                <a href="?action=admin" class="btn">Annuler</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <div class="polls-list">
                    <h2>Sondages existants</h2>
                    <?php foreach ($pollSystem->getAllPolls() as $poll): ?>
                        <div class="poll-item slide-in">
                            <h3><?= htmlspecialchars($poll['code']) ?></h3>
                            <p>Créé le: <?= $poll['created_at'] ?></p>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                <a href="<?= $pollSystem->getPollUrl($poll['code']) ?>" class="btn" target="_blank">Voir le sondage</a>
                                <a href="?action=results&code=<?= $poll['code'] ?>" class="btn">Résultats</a>
                                <a href="?action=export&code=<?= $poll['code'] ?>" class="btn">Exporter MD</a>
                                <a href="?action=admin&edit=<?= $poll['code'] ?>" class="btn btn-primary">Modifier</a>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="poll_code" value="<?= htmlspecialchars($poll['code']) ?>">
                                    <button type="submit" name="duplicate_poll" class="btn" title="Dupliquer ce sondage">Dupliquer</button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce sondage et tous ses votes ?');">
                                    <input type="hidden" name="poll_code" value="<?= htmlspecialchars($poll['code']) ?>">
                                    <button type="submit" name="delete_poll" class="btn btn-danger">Supprimer</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
        <?php elseif ($action === 'results' && !empty($poll_code) && $pollSystem->isAdmin()): ?>
            <?php $results = $pollSystem->getResults($poll_code); ?>
            <?php if ($results): ?>
                <div class="results-panel fade-in">
                    <h1>Résultats: <?= htmlspecialchars($results['poll']['code']) ?></h1>
                    <p><strong>Total des votes:</strong> <?= $results['total_votes'] ?></p>
                    
                    <div class="results-chart">
                        <?php foreach ($results['results'] as $option => $count): ?>
                            <?php $percentage = $results['total_votes'] > 0 ? ($count / $results['total_votes']) * 100 : 0; ?>
                            <div class="result-item">
                                <div class="result-label"><?= htmlspecialchars($option) ?></div>
                                <div class="result-bar">
                                    <div class="result-fill animate-bar" style="--width: <?= $percentage ?>%"></div>
                                    <span class="result-count"><?= $count ?> (<?= round($percentage, 1) ?>%)</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (!empty($results['other_comments'])): ?>
                        <div class="other-comments">
                            <h3>Commentaires "Autre"</h3>
                            <?php foreach ($results['other_comments'] as $comment): ?>
                                <div class="comment"><?= htmlspecialchars($comment) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <a href="?action=admin" class="btn">Retour à l'admin</a>
                </div>
            <?php else: ?>
                <div class="error fade-in">
                    <h2>Accès refusé</h2>
                    <p>Vous devez être connecté en tant qu'administrateur pour voir cette page.</p>
                    <a href="/" class="btn">Retour</a>
                </div>
            <?php endif; ?>
            
        <?php elseif ($action === 'export' && !empty($poll_code) && $pollSystem->isAdmin()): ?>
            <?php 
            $markdown = $pollSystem->exportToMarkdown($poll_code);
            header('Content-Type: text/markdown');
            header('Content-Disposition: attachment; filename="sondage_' . $poll_code . '.md"');
            echo $markdown;
            exit;
            ?>
            
        <?php elseif (!empty($poll_code)): ?>
            <?php $poll = $pollSystem->getPoll($poll_code); ?>
            <?php if ($poll): ?>
                <?php if (isset($_GET['voted']) || isset($_GET['already_voted'])): ?>
                    <div class="thank-you fade-in">
                        <?php 
                        if (isset($_GET['already_voted'])) {
                            echo '<div style="background: #f39c12; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center;">
                                    <strong>Vous avez déjà voté pour ce sondage.</strong>
                                  </div>';
                        } else {
                            $thankMessage = !empty($poll['thank_message']) ? $poll['thank_message'] : $config['default_thank_message'];
                            echo $thankMessage;
                        }
                        ?>
                        <div class="results-preview">
                            <h3>Résultats actuels:</h3>
                            <?php $results = $pollSystem->getResults($poll_code); ?>
                            <div class="mini-results">
                                <?php foreach ($results['results'] as $option => $count): ?>
                                    <?php $percentage = $results['total_votes'] > 0 ? ($count / $results['total_votes']) * 100 : 0; ?>
                                    <div class="mini-result">
                                        <span><?= htmlspecialchars($option) ?>:</span>
                                        <div class="mini-bar">
                                            <div class="mini-fill animate-bar" style="--width: <?= $percentage ?>%"></div>
                                        </div>
                                        <span><?= $count ?> votes</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p><strong>Total:</strong> <?= $results['total_votes'] ?> votes</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="poll-container fade-in">
                        <?php 
                        $headerToDisplay = !empty($poll['header_html']) ? $poll['header_html'] : $config['default_header'];
                        if (!empty($headerToDisplay)): 
                        ?>
                            <div class="poll-header"><?= $headerToDisplay ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" class="poll-form">
                            <div class="options-container">
                                <?php foreach ($poll['options'] as $option): ?>
                                    <label class="option-label slide-in">
                                        <input type="<?= $poll['allow_multiple'] ? 'checkbox' : 'radio' ?>" 
                                               name="options<?= $poll['allow_multiple'] ? '[]' : '' ?>" 
                                               value="<?= htmlspecialchars($option) ?>">
                                        <span class="option-text"><?= htmlspecialchars($option) ?></span>
                                    </label>
                                <?php endforeach; ?>
                                
                                <?php if ($poll['has_other']): ?>
                                    <div class="other-option slide-in">
                                        <label class="option-label">
                                            <input type="<?= $poll['allow_multiple'] ? 'checkbox' : 'radio' ?>" 
                                                   name="options<?= $poll['allow_multiple'] ? '[]' : '' ?>" 
                                                   value="Autre" id="other-checkbox">
                                            <span class="option-text">Autre:</span>
                                        </label>
                                        <input type="text" name="other_comment" placeholder="Précisez..." class="other-input">
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <button type="submit" name="vote" class="btn btn-primary pulse">Voter</button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="error fade-in">
                    <h2>Sondage introuvable</h2>
                    <p>Le code de sondage spécifié n'existe pas.</p>
                </div>
            <?php endif; ?>
            
        <?php elseif ($action === 'admin' && !$pollSystem->isAdmin()): ?>
            <div class="error fade-in">
                <h2>Accès refusé</h2>
                <p>Vous devez être connecté en tant qu'administrateur.</p>
                <a href="/" class="btn">Se connecter</a>
            </div>
        <?php else: ?>
            <div class="login-form fade-in">
                <h1>Système de Sondage</h1>
                <?php if (isset($error)): ?>
                    <div style="background: #e74c3c; color: white; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <label for="admin_code">Code administrateur:</label>
                        <input type="password" id="admin_code" name="admin_code" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary">Accéder</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Stockage des éditeurs Quill actifs
        let quillEditors = {};
        
        $(document).ready(function() {
            // Animations des barres de résultats
            $('.animate-bar').each(function() {
                const width = $(this).css('--width') || '0%';
                $(this).animate({'width': width}, 1000);
            });
            
            // Gestion de l'option "Autre"
            $('#other-checkbox').change(function() {
                if ($(this).is(':checked')) {
                    $('.other-input').focus();
                }
            });
            
            $('.other-input').on('input', function() {
                if ($(this).val().length > 0) {
                    $('#other-checkbox').prop('checked', true);
                }
            });
            
            // Boutons de prévisualisation
            $('#btn_preview_header').click(function() {
                togglePreview('header_html');
            });
            
            $('#btn_preview_thank').click(function() {
                togglePreview('thank_message');
            });
            
            // Boutons d'éditeur WYSIWYG
            $('#btn_editor_header').click(function() {
                toggleQuillEditor('header_html', $(this));
            });
            
            $('#btn_editor_thank').click(function() {
                toggleQuillEditor('thank_message', $(this));
            });
        });
        
        // Validation du formulaire
        function validateForm() {
            // Synchroniser les éditeurs Quill avec les textareas avant la soumission
            for (let fieldId in quillEditors) {
                if (quillEditors[fieldId]) {
                    $('#' + fieldId).val(quillEditors[fieldId].root.innerHTML);
                }
            }
            
            const code = $('#poll_code').val().trim();
            if (!code) {
                alert('Le code du sondage est requis');
                return false;
            }
            return true;
        }
        
        // Fonction pour basculer la prévisualisation
        function togglePreview(fieldId) {
            const textarea = $('#' + fieldId);
            const preview = $('#' + fieldId + '_preview');
            const editor = $('#' + fieldId + '_editor');
            
            // Si l'éditeur Quill est actif, prendre son contenu
            let content = '';
            if (quillEditors[fieldId] && editor.is(':visible')) {
                content = quillEditors[fieldId].root.innerHTML;
            } else {
                content = textarea.val().trim();
            }
            
            if (preview.is(':visible')) {
                preview.hide();
            } else {
                if (!content) {
                    // Utiliser la valeur par défaut
                    if (fieldId === 'header_html') {
                        content = <?= json_encode($config['default_header']) ?>;
                    } else {
                        content = <?= json_encode($config['default_thank_message']) ?>;
                    }
                }
                preview.html(content).show();
            }
        }
        
        // Fonction pour basculer l'éditeur Quill
        function toggleQuillEditor(fieldId, button) {
            const textarea = $('#' + fieldId);
            const editorDiv = $('#' + fieldId + '_editor');
            
            if (quillEditors[fieldId]) {
                // Désactiver l'éditeur - copier le contenu dans le textarea
                textarea.val(quillEditors[fieldId].root.innerHTML);
                editorDiv.hide();
                textarea.show();
                button.text('Editeur visuel');
                // Ne pas détruire l'éditeur, juste le cacher
            } else {
                // Créer et activer l'éditeur
                textarea.hide();
                editorDiv.show();
                
                // Configuration de Quill
                const options = {
                    theme: 'snow',
                    modules: {
                        toolbar: [
                            [{ 'header': [1, 2, 3, false] }],
                            ['bold', 'italic', 'underline'],
                            [{ 'color': [] }, { 'background': [] }],
                            [{ 'align': [] }],
                            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                            ['link'],
                            ['clean']
                        ]
                    }
                };
                
                quillEditors[fieldId] = new Quill('#' + fieldId + '_editor', options);
                
                // Charger le contenu existant
                const existingContent = textarea.val();
                if (existingContent) {
                    quillEditors[fieldId].root.innerHTML = existingContent;
                }
                
                // Synchroniser avec le textarea lors des changements
                quillEditors[fieldId].on('text-change', function() {
                    textarea.val(quillEditors[fieldId].root.innerHTML);
                });
                
                button.text('Mode HTML');
            }
        }
    </script>
</body>
</html>