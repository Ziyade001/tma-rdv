# Étude de cas TMA — Prise de rendez-vous au guichet

## 1. Présentation du projet

Le projet contient un module indépendant de règles de gestion, écrit en PHP, qui permet de contrôler une prise de rendez-vous, une annulation, un changement de statut ainsi que le tri et la pagination des rendez-vous d’un usager. Le module ne dépend ni d’une base de données ni d’un framework.

**Environnement utilisé** PHP 8.2.12.

## 2. Structure du dépôt

```text
.
├── src/
│   ├── Verdict.php
│   └── GestionRendezVous.php
├── tests/
│   └── run.php
├── requetes.sql
└── README.md
```

- `src/Verdict.php` : représente le résultat d’un contrôle (accepté ou refusé, code HTTP et message).
- `src/GestionRendezVous.php` : applique les règles de gestion de la section 2 du sujet.
- `tests/run.php` : exécute les tests automatisés.
- `requetes.sql` : contient les requêtes d’analyse d’impact sur l’export de la base.


## 4. Lancer les tests automatisés

Depuis la racine du projet, exécuter :

```bash
php tests/run.php
```

Les tests couvrent les sept tickets, y compris le ticket TMA-107 qui décrit un comportement conforme, ainsi que plusieurs cas complémentaires des règles de gestion.

Résultat observé dans l’environnement de développement : **21/21 tests réussis avec PHP 8.2.12**.

## 5. Qualification, diagnostic et correction des tickets

### TMA-101 — Rendez-vous un samedi : anomalie

- **Règle du sujet :** le centre reçoit du lundi au vendredi. Une demande pour un samedi ou un dimanche doit être refusée avec le code HTTP `409` et le message `Le centre est fermé le week-end.`
- **Diagnostic probable :** le contrôle du jour de la semaine était absent ou incorrect. La trace montre que le rendez-vous du samedi 14 novembre 2026 a été accepté avec le code `201`.
- **Correction :** vérifier le jour de la semaine de la date demandée et refuser les jours correspondant au samedi ou au dimanche.
- **Test :** le test TMA-101 vérifie que le samedi 14/11/2026 est refusé. Un test complémentaire vérifie que le dimanche est refusé et que le lundi est accepté.

### TMA-102 — Quatre usagers sur un créneau limité à trois : anomalie

- **Règle du sujet :** un créneau accueille au plus trois usagers. Un rendez-vous annulé libère sa place.
- **Diagnostic probable :** erreur de borne lors du comptage, par exemple un refus déclenché seulement lorsque le nombre de rendez-vous est supérieur à `3`, au lieu de refuser dès que trois places sont déjà occupées. La trace montre que le rendez-vous n° 697 a été accepté alors que trois rendez-vous existaient déjà sur le créneau.
- **Correction :** refuser la demande lorsque le nombre de rendez-vous du créneau qui ne sont pas annulés est déjà supérieur ou égal à `3`.
- **Test :** le test TMA-102 vérifie que le quatrième usager est refusé. Un test complémentaire vérifie que le troisième est accepté et qu’une annulation libère une place.
- **Limite :** le module de règles, indépendant de la base de données, ne protège pas à lui seul contre deux réservations simultanées. Une garantie contre cette situation nécessiterait une transaction ou un verrouillage côté base de données.

### TMA-103 — NPI contenant une lettre : anomalie

- **Règle du sujet :** le NPI doit comporter exactement dix chiffres. Une saisie invalide doit être refusée avec le code HTTP `400` et le message `Le NPI doit comporter exactement 10 chiffres.` Une saisie invalide ne doit jamais provoquer une erreur interne.
- **Diagnostic probable :** le NPI a pu être converti en nombre ou utilisé sans validation préalable, provoquant une erreur interne. Le convertir en entier risquerait également de supprimer un zéro initial.
- **Correction :** conserver le NPI sous forme de chaîne et vérifier qu’il contient exactement dix chiffres avant les autres traitements.
- **Test :** le test TMA-103 vérifie le refus de `06142A3647` avec le code `400`. Les cas complémentaires couvrent notamment les chaînes trop courtes ou trop longues, la valeur vide, un retour à la ligne et une valeur qui n’est pas une chaîne.

### TMA-104 — Annulation refusée 40 heures avant le rendez-vous : anomalie

- **Règle du sujet :** l’usager peut annuler son rendez-vous jusqu’à 24 heures avant l’heure prévue.
- **Diagnostic probable :** le calcul de la différence entre les deux dates a pu utiliser uniquement la composante « heures » d’un intervalle, au lieu de sa durée totale. Le 17/11/2026 à 18 h, le rendez-vous du 19/11/2026 à 10 h était encore à 40 heures.
- **Correction :** calculer la différence totale entre les deux dates et refuser l’annulation uniquement si le délai restant est inférieur à 24 heures.
- **Test :** le test TMA-104 vérifie que l’annulation à 40 heures est acceptée. Les tests complémentaires vérifient qu’une annulation exactement 24 heures avant est acceptée, qu’une annulation à 23 h 59 est refusée et qu’un rendez-vous non confirmé ne peut pas être annulé.

### TMA-105 — Rendez-vous annulé passé au statut « honoré » : anomalie

- **Règle du sujet :** un rendez-vous confirmé peut devenir « honoré », « absent » ou « annulé ». Ces trois statuts sont définitifs. Une demande de changement sur un rendez-vous déjà clôturé doit être refusée avec le code HTTP `409` et le message `Ce rendez-vous est déjà clôturé.`
- **Diagnostic probable :** l’application a pu valider uniquement le statut demandé sans vérifier le statut actuel du rendez-vous.
- **Correction :** n’autoriser le changement vers `honore` ou `absent` que si le rendez-vous est encore au statut `confirme`. Un statut demandé autre que `honore` ou `absent` doit être refusé avec le code HTTP `400` et le message prévu.
- **Test :** le test TMA-105 vérifie que le rendez-vous n° 292, déjà annulé, ne peut pas devenir « honoré ». Les tests complémentaires vérifient les transitions autorisées, le caractère définitif des statuts clôturés et le refus d’un statut inconnu.

### TMA-106 — Liste « Mes rendez-vous » mal triée et mal paginée : anomalie

- **Règle du sujet :** les rendez-vous d’un usager sont listés du plus récent au plus ancien, le prochain rendez-vous en tête, par pages de dix.
- **Diagnostic probable :** la trace montre un ordre incohérent et seulement neuf rendez-vous sur la première page alors que le total est de treize. Cela suggère un tri absent ou incorrect et une erreur de borne dans le découpage en pages.
- **Correction :** trier les rendez-vous selon la règle du sujet, puis calculer le début de page avec `($page - 1) * $parPage` et retourner au maximum dix éléments.
- **Test :** le test TMA-106 vérifie que la première page contient dix rendez-vous et que le rendez-vous du 02/12/2026 est en tête, suivi des autres rendez-vous dans l’ordre attendu.

### TMA-107 — Un passeport à venir bloque une réservation de permis : comportement conforme

- **Règle du sujet :** un usager ne peut avoir qu’un seul rendez-vous à venir, tous services confondus.
- **Qualification :** ce ticket n’est pas une anomalie. Le refus de réserver un permis alors qu’un rendez-vous passeport est déjà à venir est attendu. Le code HTTP `409` et le message `Vous avez déjà un rendez-vous à venir.` sont conformes au sujet.
- **Correction :** aucune correction n’est nécessaire pour ce comportement.
- **Test :** le test TMA-107 vérifie que le second rendez-vous est refusé. Un test complémentaire vérifie qu’un rendez-vous annulé, passé ou appartenant à un autre usager ne bloque pas la réservation.

## 6. Autres règles de gestion couvertes

Les tests vérifient également :
- le refus d’un service inconnu avec le code HTTP `400` et le message prévu ;
- le refus d’une date ou heure illisible avec le code HTTP `400` et le message prévu ;
- le respect de la grille horaire, de `08:00` à `15:30`, par intervalles de 30 minutes.

Le module reçoit l’heure courante en paramètre afin que les tests puissent reproduire les situations à une date et une heure déterminées.

## 7. Analyse d’impact des données — `requetes.sql`

Le sujet indique que l’export de la base correspond au vendredi 20 novembre 2026 à 18 h 00, heure du Bénin.

### Charger l’export

Placer le fichier `base_rendez_vous.sql` à la racine du projet, puis exécuter les commandes suivantes depuis `cmd` ou un terminal compatible avec la redirection `<` :

```bash
sqlite3 rdv.db < base_rendez_vous.sql
sqlite3 rdv.db < requetes.sql
```

Dans PowerShell, si la redirection `<` provoque une erreur, utiliser :

```powershell
cmd /c "sqlite3 rdv.db < base_rendez_vous.sql"
cmd /c "sqlite3 rdv.db < requetes.sql"
```

La première commande charge l’export dans `rdv.db`. La seconde exécute les requêtes d’impact et les éventuelles requêtes complémentaires définies dans `requetes.sql`.

### Requêtes d’impact demandées

Le sujet demande de lister :

1. Les rendez-vous confirmés à venir qui ont été pris un samedi ou un dimanche, afin de prévenir les usagers.
2. Les créneaux comportant plus de trois rendez-vous non annulés.
3. Les rendez-vous passés du statut `annule` à un autre statut.

### Résultats

**Les résultats chiffrés ne sont pas renseignés dans cette version**, car l’export réel `base_rendez_vous.sql` n’était pas disponible lors de la rédaction. Ils devront être ajoutés après exécution des requêtes sur cet export. Aucun nombre de lignes ni résultat ne doit être déduit de la base factice.

## 8. Bonus — Recherche d’anomalies supplémentaires

À compléter après exécution des requêtes B1 à B8 de `requetes.sql` sur l'export (usagers avec plusieurs rendez-vous à venir, hors grille, NPI ou service invalides, annulations tardives acceptées, historique incohérent, rendez-vous pris dans le passé). Les règles correspondantes sont déjà couvertes par le module : grille, NPI, service, passé, un seul rendez-vous à venir, 24 h exactes.




