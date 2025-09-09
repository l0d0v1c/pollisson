<?php
/**
 * Fichier de configuration de référence
 * Copiez ce fichier vers config.php et modifiez les valeurs selon vos besoins
 */

return [
    // Mot de passe administrateur
    'admin_password' => 'admin123',
    
    // Header HTML par défaut pour les sondages
    'default_header' => '<div style="text-align: center; padding: 20px; background: #3498db; color: white; border-radius: 8px; margin-bottom: 30px;">
        <h2 style="margin: 0; font-size: 2em; font-weight: 300;">Sondage</h2>
        <p style="margin: 10px 0 0 0; opacity: 0.9;">Merci de prendre quelques instants pour répondre à ce sondage</p>
    </div>',
    
    // Message de remerciement par défaut
    'default_thank_message' => '<div style="text-align: center; padding: 30px;">
        <h2 style="color: #2ecc71; margin-bottom: 20px;">Merci pour votre participation !</h2>
        <p style="font-size: 1.1em; color: #555;">Votre vote a été enregistré avec succès.</p>
        <p style="margin-top: 20px; color: #777;">Consultez les résultats ci-dessous pour voir les tendances actuelles.</p>
    </div>',
    
    // Configuration de la base de données
    'database' => [
        'path' => 'polls.db',
        'type' => 'sqlite'
    ],
    
    // Options par défaut pour les nouveaux sondages
    'poll_defaults' => [
        'allow_multiple' => false,
        'has_other' => true,
        'show_results_after_vote' => true,
        'one_vote_per_ip' => true
    ],
    
    // Configuration de sécurité
    'security' => [
        'enable_csrf' => true,
        'session_lifetime' => 3600, // 1 heure
        'max_poll_options' => 50,
        'max_comment_length' => 500
    ],
    
    // Configuration d'affichage
    'display' => [
        'items_per_page' => 10,
        'date_format' => 'd/m/Y H:i',
        'timezone' => 'Europe/Paris'
    ],
    
    // Messages système
    'messages' => [
        'already_voted' => 'Vous avez déjà voté pour ce sondage.',
        'poll_not_found' => 'Le sondage demandé n\'existe pas.',
        'poll_expired' => 'Ce sondage a expiré.',
        'invalid_code' => 'Code administrateur incorrect.',
        'poll_created' => 'Le sondage a été créé avec succès.',
        'no_option_selected' => 'Veuillez sélectionner au moins une option.'
    ]
];