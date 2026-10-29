# Étude de cas TMA – prise de rendez-vous au guichet (PHP)

Module de règles de gestion en **PHP ≥ 8.0**, sans base de données ni framework, avec tests automatisés sans dépendance.

```
src/Verdict.php              résultat d'un contrôle (accepté / refusé + code HTTP + message)
src/GestionRendezVous.php    règles de la section 2
tests/run.php                tests automatisés
requetes.sql                 requêtes d'impact sur l'export
```

## Lancer les tests

```bash
php tests/run.php        # aucune installation ; code de sortie 0 si tout passe, 1 sinon
```

Charger l'export et lancer les requêtes :

```bash
sqlite3 rdv.db < base_rendez_vous.sql
sqlite3 rdv.db < requetes.sql
```

## Utilisation du module

Tous les contrôles reçoivent l'heure courante en paramètre (aucune lecture de l'horloge système) et renvoient un `Verdict` (`accepte`, `codeHttp`, `message`).

```php
use Tma\Rdv\GestionRendezVous as G;

G::verifierPrise($npi, $service, '2026-11-23T09:00', $rendezVousExistants, $maintenant);
G::verifierAnnulation($rdv, $maintenant);
G::verifierChangementStatut($rdv, 'honore');
G::listerRendezVous($rendezVous, $npi, $maintenant, $page = 1);   // tri + pagination
```

## Hypothèses d'interprétation

- Heures du Bénin = fuseau fixe `+01:00` (pas de changement d'heure). Un « maintenant » fourni dans un autre fuseau est converti.
- « Dans le passé » = strictement avant l'heure courante. « À venir » = rendez-vous `confirme` dont l'heure n'est pas passée.
- Annulation « jusqu'à 24 heures avant » : exactement 24 h avant est encore accepté, 23 h 59 est refusé.
- Capacité : les rendez-vous non annulés d'un créneau comptent (honoré et absent aussi) ; refus quand il y en a déjà 3.
- Ordre des contrôles = ordre du tableau de la section 2.
- Liste : le prochain rendez-vous en tête, puis tous les autres du plus récent au plus ancien.

## Tickets

### TMA-101 – Rendez-vous un samedi · **Anomalie**
- **Règle** : « Le centre reçoit du lundi au vendredi » ; refus 409 « Le centre est fermé le week-end. »
- **Cause probable** : contrôle du jour de la semaine absent (ou mal fait). La trace montre un 201 pour le samedi 14/11/2026.
- **Correction** : `format('N') >= 6` sur la date saisie (heure du Bénin) → refus 409.
- **Test** : `TMA-101 un rendez-vous le samedi 14/11/2026 est refusé`.

### TMA-102 – 4 usagers sur un créneau de 3 · **Anomalie**
- **Règle** : « Un créneau accueille au plus 3 usagers. Un rendez-vous annulé libère sa place. »
- **Cause probable** : erreur de borne sur le comptage, `nb > 3` au lieu de `nb >= 3` (avec 3 déjà pris, le test passe et le 4e est accepté : n° 697). Une condition de concurrence (deux requêtes simultanées entre le comptage et l'insertion) est possible aussi ; la corriger demande en plus une transaction ou un verrou côté base, hors du périmètre du module.
- **Correction** : refus dès que le nombre de rendez-vous non annulés du créneau est `>= 3`.
- **Test** : `TMA-102 le 4e usager … est refusé` (+ complément : le 3e passe, un annulé libère sa place).

### TMA-103 – NPI avec une lettre → erreur 500 · **Anomalie**
- **Règle** : NPI de « exactement 10 chiffres » ; refus 400 « Le NPI doit comporter exactement 10 chiffres. » ; « jamais par une erreur interne ».
- **Cause probable** : le NPI est converti en nombre (ou traité sans validation) avant contrôle, d'où une exception non rattrapée → 500. Piège associé : un NPI converti en entier perd son 0 initial.
- **Correction** : le NPI reste une chaîne, validée par `/\A[0-9]{10}\z/` (`\z` et non `$`, qui accepterait un retour à la ligne final ; `[0-9]` et non `\d` par prudence), refus 400 avant tout autre traitement.
- **Test** : `TMA-103 le NPI « 06142A3647 » est refusé en 400` (+ trop court/long, vide, retour à la ligne, nombre, null).

### TMA-104 – Annulation refusée à 40 h du rendez-vous · **Anomalie**
- **Règle** : « L'usager peut annuler son rendez-vous jusqu'à 24 heures avant l'heure prévue. » Le 17/11 à 18:00 pour le 19/11 à 10:00, il reste 40 h : l'annulation devait être acceptée.
- **Cause probable** : mauvais calcul de l'écart. Par exemple, en PHP, `DateInterval::$h` (composante « heures » 0-23 d'un écart, ici 16 h pour 1 jour 16 h) comparé à 24 : l'annulation est alors refusée presque tout le temps. Autres pistes : écart pris sur l'heure du jour seule, confusion minutes/heures.
- **Correction** : écart total en secondes (`timestamp(rdv) - timestamp(maintenant)`), refus uniquement si `< 86400`.
- **Test** : `TMA-104 … annuler le rendez-vous 709 … (40 h avant) est accepté` (+ frontières 24 h exactes / 23 h 59, statuts non confirmés).

### TMA-105 – Rendez-vous annulé passé à « honoré » · **Anomalie**
- **Règle** : « Ces trois statuts sont définitifs » ; refus 409 « Ce rendez-vous est déjà clôturé. »
- **Cause probable** : le contrôle ne porte que sur la valeur demandée (honore/absent), pas sur le statut actuel : la transition n'est pas vérifiée.
- **Correction** : après validation du statut demandé (400 si différent de `honore`/`absent`), refus 409 si le statut actuel n'est pas `confirme`.
- **Test** : `TMA-105 le rendez-vous 292 « annule » ne peut pas passer à « honore »` (+ honoré/absent définitifs, statut inconnu).

### TMA-106 – Liste « Mes rendez-vous » · **Anomalie**
- **Règle** : « du plus récent au plus ancien, le prochain rendez-vous en tête, par pages de 10 ».
- **Cause probable** : deux défauts. (1) Pas de tri : les dates renvoyées sont dans un ordre quelconque (ordre de la base). (2) Erreur de borne au découpage : la page 1 renvoie **9** rendez-vous alors que le total est 13 (départ à l'indice 1 au lieu de 0 : le premier élément est perdu). Le rendez-vous du 02/12 n'est donc pas en tête, et des rendez-vous disparaissent entre les pages.
- **Correction** : tri (prochain d'abord, puis du plus récent au plus ancien, départage par id) puis `array_slice($liste, ($page - 1) * $parPage, $parPage)`.
- **Test** : `TMA-106 la page 1 compte 10 rendez-vous, le prochain … en tête` (13 rendez-vous, ordre exact des deux pages, aucun perdu ni dupliqué).

### TMA-107 – Impossible de prendre un PERMIS avec un PASSEPORT à venir · **Conforme (pas une anomalie)**
- **Règle** : « Un usager ne peut avoir qu'un seul rendez-vous à venir, tous services confondus. » Le refus 409 « Vous avez déjà un rendez-vous à venir. » est le comportement attendu.
- **Correction** : aucune. Le test vérifie que le refus est maintenu, et qu'un rendez-vous annulé, passé ou d'un autre usager ne bloque pas.
- **Test** : `TMA-107 (conforme) PERMIS refusé car un rendez-vous PASSEPORT est déjà à venir`.

## Impact sur les données (requetes.sql)

Référence : export du vendredi 20/11/2026 à 18:00.

| Requête | Objet | Résultat sur l'export |
|---|---|---|
| 1 | Confirmés à venir pris un samedi ou dimanche (usagers à prévenir) | _à compléter après chargement de l'export_ |
| 2 | Créneaux avec plus de 3 rendez-vous non annulés | _à compléter_ |
| 3 | Rendez-vous passés de « annulé » à un autre statut | _à compléter_ |

## Bonus – anomalies non signalées

À compléter après exécution des requêtes B1 à B8 de `requetes.sql` sur l'export (usagers avec plusieurs rendez-vous à venir, hors grille, NPI ou service invalides, annulations tardives acceptées, historique incohérent, rendez-vous pris dans le passé). Les règles correspondantes sont déjà couvertes par le module : grille, NPI, service, passé, un seul rendez-vous à venir, 24 h exactes.
