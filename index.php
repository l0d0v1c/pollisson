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
            require_signature INTEGER DEFAULT 0,
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
            voter_lastname TEXT,
            voter_firstname TEXT,
            voter_email TEXT,
            signature TEXT,
            voted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Migrations pour les bases de données existantes
        $this->ensureColumn('polls', 'require_signature', 'INTEGER DEFAULT 0');
        $this->ensureColumn('votes', 'voter_lastname', 'TEXT');
        $this->ensureColumn('votes', 'voter_firstname', 'TEXT');
        $this->ensureColumn('votes', 'voter_email', 'TEXT');
        $this->ensureColumn('votes', 'signature', 'TEXT');
    }

    /**
     * Ajoute une colonne à une table si elle n'existe pas déjà (migration douce).
     */
    private function ensureColumn($table, $column, $definition) {
        $stmt = $this->db->query("PRAGMA table_info(" . $table . ")");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array($column, $columns, true)) {
            $this->db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
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
        // Génère l'URL publique courte du sondage (chaque segment encodé)
        return htmlspecialchars(rawurlencode($pollCode));
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
        $stmt = $this->db->prepare("INSERT INTO polls (code, header_html, options, allow_multiple, has_other, require_signature, end_date, thank_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $data['code'],
            $data['header_html'],
            json_encode($data['options']),
            $data['allow_multiple'] ? 1 : 0,
            $data['has_other'] ? 1 : 0,
            !empty($data['require_signature']) ? 1 : 0,
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
        
        $stmt = $this->db->prepare("UPDATE polls SET code = ?, header_html = ?, options = ?, allow_multiple = ?, has_other = ?, require_signature = ?, end_date = ?, thank_message = ? WHERE code = ?");
        return $stmt->execute([
            $data['code'],
            $data['header_html'],
            json_encode($data['options']),
            $data['allow_multiple'] ? 1 : 0,
            $data['has_other'] ? 1 : 0,
            !empty($data['require_signature']) ? 1 : 0,
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
            'require_signature' => $originalPoll['require_signature'] ?? 0,
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
    
    public function submitVote($poll_code, $selected_options, $other_comment = '', $signer = []) {
        try {
            // Obtenir l'adresse IP réelle même derrière un proxy
            $ip = $this->getRealIpAddr();

            // Vérifier que le sondage existe
            $poll = $this->getPoll($poll_code);
            if (!$poll) {
                return false;
            }

            // La limite « 1 vote par IP » ne s'applique PAS aux sondages signés :
            // plusieurs personnes peuvent voter et signer depuis un même poste/IP.
            if (empty($poll['require_signature'])) {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM votes WHERE poll_code = ? AND ip_address = ?");
                $stmt->execute([$poll_code, $ip]);
                if ($stmt->fetchColumn() > 0) {
                    return false;
                }
            }

            // Si la signature est obligatoire, valider les informations du signataire
            $lastname = $firstname = $email = $signature = null;
            if (!empty($poll['require_signature'])) {
                $lastname  = trim($signer['lastname']  ?? '');
                $firstname = trim($signer['firstname'] ?? '');
                $email     = trim($signer['email']     ?? '');
                $signature = $signer['signature']      ?? '';

                if ($lastname === '' || $firstname === '' || $email === '') {
                    return false;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return false;
                }
                // La signature doit être une image PNG en base64 non triviale
                if (strpos($signature, 'data:image/png;base64,') !== 0 || strlen($signature) < 200) {
                    return false;
                }
            }

            // Insérer le vote
            $stmt = $this->db->prepare("INSERT INTO votes (poll_code, selected_options, other_comment, ip_address, voter_lastname, voter_firstname, voter_email, signature) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $result = $stmt->execute([
                $poll_code,
                json_encode($selected_options),
                $other_comment,
                $ip,
                $lastname,
                $firstname,
                $email,
                $signature
            ]);

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
    
    /**
     * Retourne la liste détaillée des votes signés (nom, prénom, email, date, choix, signature).
     */
    public function getSignedVotes($poll_code) {
        $stmt = $this->db->prepare("SELECT voter_lastname, voter_firstname, voter_email, selected_options, other_comment, signature, voted_at FROM votes WHERE poll_code = ? AND signature IS NOT NULL AND signature != '' ORDER BY voted_at ASC");
        $stmt->execute([$poll_code]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $signed = [];
        foreach ($rows as $row) {
            $selected = json_decode($row['selected_options'], true);
            $choices = is_array($selected) ? array_map(function ($o) {
                return trim(str_replace("\r", "", $o));
            }, $selected) : [];
            $signed[] = [
                'lastname'   => $row['voter_lastname'],
                'firstname'  => $row['voter_firstname'],
                'email'      => $row['voter_email'],
                'choices'    => $choices,
                'other'      => $row['other_comment'],
                'signature'  => $row['signature'],
                'voted_at'   => $row['voted_at']
            ];
        }
        return $signed;
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
            'require_signature' => isset($_POST['require_signature']),
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
            'require_signature' => isset($_POST['require_signature']),
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

        $encodedCode = urlencode($poll_code);

        // Valider que des options ont été sélectionnées
        if (empty($selected_options) && empty($other_comment)) {
            header("Location: ?code=$encodedCode&error=no_selection");
            exit;
        }

        // Informations de signature (utilisées uniquement si le sondage l'exige)
        $signer = [
            'lastname'  => $_POST['voter_lastname'] ?? '',
            'firstname' => $_POST['voter_firstname'] ?? '',
            'email'     => $_POST['voter_email'] ?? '',
            'signature' => $_POST['signature'] ?? ''
        ];

        // Si le sondage exige une signature, valider les champs avant l'insertion
        $poll = $pollSystem->getPoll($poll_code);
        if ($poll && !empty($poll['require_signature'])) {
            $emailValid = filter_var(trim($signer['email']), FILTER_VALIDATE_EMAIL);
            $sigValid = strpos($signer['signature'], 'data:image/png;base64,') === 0 && strlen($signer['signature']) >= 200;
            if (trim($signer['lastname']) === '' || trim($signer['firstname']) === '' || !$emailValid || !$sigValid) {
                header("Location: ?code=$encodedCode&error=signature_required");
                exit;
            }
        }

        $voteResult = $pollSystem->submitVote($poll_code, $selected_options, $other_comment, $signer);

        if ($voteResult) {
            header("Location: ?code=$encodedCode&voted=1");
            exit;
        } else {
            // L'utilisateur a déjà voté ou erreur
            header("Location: ?code=$encodedCode&already_voted=1");
            exit;
        }
    }
}

// Rapport de vote imprimable (page autonome, réservé à l'administrateur)
if ($action === 'report' && !empty($poll_code) && $pollSystem->isAdmin()) {
    $data = $pollSystem->getResults($poll_code);
    if (!$data) {
        http_response_code(404);
        echo "Sondage introuvable.";
        exit;
    }
    $signedVotes = $pollSystem->getSignedVotes($poll_code);
    $generatedAt = date('d/m/Y H:i');
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport de vote — <?= htmlspecialchars($data['poll']['code']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #222; margin: 0; padding: 40px; background: #f4f6f8; }
        .report { max-width: 900px; margin: 0 auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        h1 { margin: 0 0 4px; font-size: 1.6em; }
        .meta { color: #666; font-size: .9em; margin-bottom: 24px; }
        h2 { font-size: 1.2em; border-bottom: 2px solid #3498db; padding-bottom: 6px; margin-top: 32px; }
        .bar-row { margin: 10px 0; }
        .bar-label { display: flex; justify-content: space-between; font-size: .9em; margin-bottom: 4px; }
        .bar-track { background: #ecf0f1; border-radius: 4px; height: 18px; overflow: hidden; }
        .bar-fill { background: #3498db; height: 100%; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: .85em; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #f0f3f5; }
        .sig-img { max-width: 180px; max-height: 70px; border: 1px solid #eee; }
        .no-data { color: #999; font-style: italic; }
        .toolbar { max-width: 900px; margin: 0 auto 16px; text-align: right; }
        .btn-print { background: #3498db; color: #fff; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-size: 1em; }
        .btn-print:hover { background: #2980b9; }
        .back { color: #3498db; text-decoration: none; margin-right: 12px; }
        @media print {
            body { background: #fff; padding: 0; }
            .report { box-shadow: none; max-width: none; padding: 0; }
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a class="back" href="?action=admin">← Retour à l'admin</a>
        <button class="btn-print" onclick="window.print()">Imprimer / PDF</button>
    </div>
    <div class="report">
        <h1>Rapport de vote — <?= htmlspecialchars($data['poll']['code']) ?></h1>
        <div class="meta">
            Généré le <?= $generatedAt ?> &middot;
            Total des votes : <strong><?= $data['total_votes'] ?></strong> &middot;
            Votes signés : <strong><?= count($signedVotes) ?></strong>
        </div>

        <h2>Résultats</h2>
        <?php foreach ($data['results'] as $option => $count): ?>
            <?php $pct = $data['total_votes'] > 0 ? ($count / $data['total_votes']) * 100 : 0; ?>
            <div class="bar-row">
                <div class="bar-label">
                    <span><?= htmlspecialchars($option) ?></span>
                    <span><?= $count ?> (<?= round($pct, 1) ?>%)</span>
                </div>
                <div class="bar-track"><div class="bar-fill" style="width: <?= $pct ?>%"></div></div>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($data['poll']['require_signature'])): ?>
            <h2>Registre des votes signés</h2>
            <?php if (empty($signedVotes)): ?>
                <p class="no-data">Aucun vote signé pour le moment.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nom</th>
                            <th>Prénom</th>
                            <th>Email</th>
                            <th>Date</th>
                            <th>Choix</th>
                            <th>Signature</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($signedVotes as $i => $v): ?>
                            <?php
                            $choicesText = implode(', ', $v['choices']);
                            if (!empty($v['other'])) {
                                $choicesText .= ($choicesText !== '' ? ' — ' : '') . 'Autre : ' . $v['other'];
                            }
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($v['lastname']) ?></td>
                                <td><?= htmlspecialchars($v['firstname']) ?></td>
                                <td><?= htmlspecialchars($v['email']) ?></td>
                                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($v['voted_at']))) ?></td>
                                <td><?= htmlspecialchars($choicesText) ?></td>
                                <td>
                                    <?php if (strpos($v['signature'], 'data:image/png;base64,') === 0): ?>
                                        <img class="sig-img" src="<?= htmlspecialchars($v['signature']) ?>" alt="signature">
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($data['other_comments'])): ?>
            <h2>Commentaires « Autre »</h2>
            <ul>
                <?php foreach ($data['other_comments'] as $comment): ?>
                    <li><?= htmlspecialchars($comment) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</body>
</html>
    <?php
    exit;
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
                            <label>
                                <input type="checkbox" name="require_signature" <?= (!empty($formData['require_signature']) ? 'checked' : '') ?>> Obliger la signature du vote (nom, prénom, email, signature manuscrite)
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
                                <a href="?action=results&code=<?= urlencode($poll['code']) ?>" class="btn">Résultats</a>
                                <a href="?action=report&code=<?= urlencode($poll['code']) ?>" class="btn" target="_blank">Rapport</a>
                                <a href="?action=export&code=<?= urlencode($poll['code']) ?>" class="btn">Exporter MD</a>
                                <a href="?action=admin&edit=<?= urlencode($poll['code']) ?>" class="btn btn-primary">Modifier</a>
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
                        
                        <?php if (isset($_GET['error']) && $_GET['error'] === 'signature_required'): ?>
                            <div style="background: #e74c3c; color: white; padding: 12px 15px; border-radius: 5px; margin-bottom: 20px; text-align: center;">
                                Merci de renseigner votre nom, prénom, un email valide et de signer avant de voter.
                            </div>
                        <?php elseif (isset($_GET['error']) && $_GET['error'] === 'no_selection'): ?>
                            <div style="background: #f39c12; color: white; padding: 12px 15px; border-radius: 5px; margin-bottom: 20px; text-align: center;">
                                Veuillez sélectionner au moins une option.
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="poll-form" id="poll-form">
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

                            <?php if (!empty($poll['require_signature'])): ?>
                                <div class="signature-block slide-in">
                                    <h3 class="signature-title">Signature du vote</h3>
                                    <p class="signature-intro">Ce vote doit être signé. Merci de renseigner vos informations et de signer ci-dessous.</p>
                                    <div class="signer-fields">
                                        <div class="signer-field">
                                            <label for="voter_lastname">Nom</label>
                                            <input type="text" id="voter_lastname" name="voter_lastname" required>
                                        </div>
                                        <div class="signer-field">
                                            <label for="voter_firstname">Prénom</label>
                                            <input type="text" id="voter_firstname" name="voter_firstname" required>
                                        </div>
                                        <div class="signer-field">
                                            <label for="voter_email">Email</label>
                                            <input type="email" id="voter_email" name="voter_email" required>
                                        </div>
                                        <div class="signer-field">
                                            <label>Date</label>
                                            <input type="text" value="<?= date('d/m/Y H:i') ?>" disabled>
                                        </div>
                                    </div>
                                    <label class="signature-label">Signez ici :</label>
                                    <div class="signature-pad-wrap">
                                        <canvas id="signature-pad" class="signature-pad" width="600" height="180"></canvas>
                                    </div>
                                    <button type="button" id="clear-signature" class="btn btn-small">Effacer</button>
                                    <input type="hidden" name="signature" id="signature-data">
                                </div>
                            <?php endif; ?>

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

            // Pavé de signature (canvas)
            initSignaturePad();
        });

        // Initialise le pavé de signature s'il est présent sur la page
        function initSignaturePad() {
            const canvas = document.getElementById('signature-pad');
            if (!canvas) return;

            const ctx = canvas.getContext('2d');
            let drawing = false;
            let hasSignature = false;

            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#1a1a2e';

            function pos(e) {
                const rect = canvas.getBoundingClientRect();
                const point = e.touches ? e.touches[0] : e;
                return {
                    x: (point.clientX - rect.left) * (canvas.width / rect.width),
                    y: (point.clientY - rect.top) * (canvas.height / rect.height)
                };
            }

            function start(e) {
                e.preventDefault();
                drawing = true;
                hasSignature = true;
                const p = pos(e);
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
            }

            function move(e) {
                if (!drawing) return;
                e.preventDefault();
                const p = pos(e);
                ctx.lineTo(p.x, p.y);
                ctx.stroke();
            }

            function end(e) {
                drawing = false;
            }

            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            window.addEventListener('mouseup', end);
            canvas.addEventListener('touchstart', start, {passive: false});
            canvas.addEventListener('touchmove', move, {passive: false});
            canvas.addEventListener('touchend', end);

            $('#clear-signature').on('click', function() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                hasSignature = false;
                $('#signature-data').val('');
            });

            // Validation + capture de la signature à la soumission
            $('#poll-form').on('submit', function(e) {
                const lastname = $('#voter_lastname').val().trim();
                const firstname = $('#voter_firstname').val().trim();
                const email = $('#voter_email').val().trim();
                const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

                if (!lastname || !firstname || !emailOk) {
                    alert('Merci de renseigner votre nom, prénom et un email valide.');
                    e.preventDefault();
                    return false;
                }
                if (!hasSignature) {
                    alert('Merci de signer dans le cadre prévu avant de voter.');
                    e.preventDefault();
                    return false;
                }
                $('#signature-data').val(canvas.toDataURL('image/png'));
                return true;
            });
        }
        
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