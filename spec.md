Une application php/css3/jquery/sqlite. Un seul fichier php et une seule base sqlite et un seul fichier css3. C'est un système de vote. Lorsqu'on arrive sur la page racine, on peut entre un code pour l'interface admin. L'adminstrateur peut entrer un nouveau vote. La fiche du nouveau vote comprend:
-un html qui sera affiché en header
-un code unique désignant le sondage (CU)
-des cases à cocher
-un champ 'Autre' avec la possibilité de laisser un court commentaire
-un date de fin de validité
-un message de remerciement modifiable (html)

L'administrateur peut choisir si un seul choix est possible ou plusieurs.

Le .htaccess vérifie qu'on ne peut pas télécharger la base sqlite et renvoie sur le sondage quand on appelle l'URL ...../CU

L'utilisateur voie les questions et vote. Il doit y avoir des animations. Après le vote l'utilisateur voie le résulats et les statistiques de votes précédents (Autres n'affiche pas les commentaires)

Les résultats sont visibles par l'administrateur et exportable en marckdown.