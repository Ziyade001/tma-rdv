-- =====================================================================
-- requetes.sql – mesure d'impact des anomalies sur l'export de la base
--
-- Chargement :   sqlite3 rdv.db < base_rendez_vous.sql
--                sqlite3 rdv.db < requetes.sql
-- (PostgreSQL : psql -f base_rendez_vous.sql puis psql -f requetes.sql ;
--  les écarts de syntaxe sont indiqués en commentaire « PG » / « MySQL ».)
--
-- Référence temporelle : l'export a été réalisé le vendredi 20/11/2026 à
-- 18:00 (heure du Bénin). « À venir » = après cet instant.
-- Les heures de date_heure sont déjà en heure du Bénin : aucune conversion.
-- =====================================================================


-- ---------------------------------------------------------------------
-- Requête 1 – TMA-101 : rendez-vous confirmés À VENIR pris un samedi ou
-- un dimanche -> les usagers à prévenir (centre fermé).
-- ---------------------------------------------------------------------
SELECT id, npi, service, date_heure, statut, cree_le
FROM rendez_vous
WHERE statut = 'confirme'
  AND datetime(date_heure) > datetime('2026-11-20 18:00:00')
  AND strftime('%w', date_heure) IN ('0', '6')      -- 0 = dimanche, 6 = samedi
ORDER BY datetime(date_heure), id;
-- PG    : AND EXTRACT(DOW FROM date_heure) IN (0, 6)   et   date_heure > TIMESTAMP '2026-11-20 18:00:00'
-- MySQL : AND DAYOFWEEK(date_heure) IN (1, 7)          et   date_heure > '2026-11-20 18:00:00'


-- ---------------------------------------------------------------------
-- Requête 2 – TMA-102 : créneaux qui comptent plus de 3 rendez-vous NON
-- annulés (un rendez-vous annulé libère sa place).
-- ---------------------------------------------------------------------
SELECT datetime(date_heure)  AS creneau,
       COUNT(*)              AS nb_rendez_vous,
       GROUP_CONCAT(id)      AS ids
FROM rendez_vous
WHERE statut <> 'annule'
GROUP BY datetime(date_heure)
HAVING COUNT(*) > 3
ORDER BY creneau;
-- PG    : STRING_AGG(id::text, ',') au lieu de GROUP_CONCAT(id) ; GROUP BY date_heure
-- MySQL : GROUP BY date_heure


-- ---------------------------------------------------------------------
-- Requête 3 – TMA-105 : rendez-vous passés du statut « annulé » à un
-- autre statut (transition interdite : les statuts sont définitifs).
-- ---------------------------------------------------------------------
SELECT h.id              AS historique_id,
       h.rendez_vous_id,
       r.npi,
       r.service,
       r.date_heure,
       h.ancien_statut,
       h.nouveau_statut,
       h.modifie_le,
       h.modifie_par,
       r.statut          AS statut_actuel
FROM historique_statuts h
JOIN rendez_vous r ON r.id = h.rendez_vous_id
WHERE h.ancien_statut = 'annule'
  AND h.nouveau_statut <> 'annule'
ORDER BY h.modifie_le, h.id;


-- =====================================================================
-- BONUS – requêtes de recherche d'anomalies non signalées
-- (à exécuter sur l'export ; chaque requête doit renvoyer 0 ligne si la
--  base respecte les règles de la section 2)
-- =====================================================================

-- B1. Usagers ayant plus d'un rendez-vous confirmé à venir (règle « un seul à venir »).
SELECT npi, COUNT(*) AS nb_a_venir, GROUP_CONCAT(id) AS ids
FROM rendez_vous
WHERE statut = 'confirme'
  AND datetime(date_heure) > datetime('2026-11-20 18:00:00')
GROUP BY npi
HAVING COUNT(*) > 1;

-- B2. Rendez-vous hors grille horaire (08:00-15:30, toutes les 30 min).
SELECT id, npi, service, date_heure, statut
FROM rendez_vous
WHERE CAST(strftime('%H', date_heure) AS INTEGER) NOT BETWEEN 8 AND 15
   OR CAST(strftime('%M', date_heure) AS INTEGER) NOT IN (0, 30);

-- B3. NPI invalides en base (pas exactement 10 chiffres).
SELECT id, npi, service, date_heure
FROM rendez_vous
WHERE LENGTH(npi) <> 10
   OR npi GLOB '*[^0-9]*';
-- PG / MySQL : WHERE npi !~ '^[0-9]{10}$'   /   WHERE npi NOT REGEXP '^[0-9]{10}$'

-- B4. Services inconnus.
SELECT id, npi, service, date_heure
FROM rendez_vous
WHERE service NOT IN ('PASSEPORT', 'CNI', 'PERMIS');

-- B5. Annulations faites par l'usager à moins de 24 h du rendez-vous
--     (le bug TMA-104 refuse à tort ; vérifie qu'il n'y a pas aussi des annulations tardives acceptées).
SELECT h.rendez_vous_id, r.date_heure, h.modifie_le, h.modifie_par,
       ROUND((julianday(r.date_heure) - julianday(h.modifie_le)) * 24, 1) AS heures_avant
FROM historique_statuts h
JOIN rendez_vous r ON r.id = h.rendez_vous_id
WHERE h.nouveau_statut = 'annule'
  AND h.modifie_par = 'usager'
  AND (julianday(r.date_heure) - julianday(h.modifie_le)) * 24 < 24;

-- B6. Statut actuel incohérent avec le dernier statut de l'historique.
SELECT r.id, r.statut AS statut_actuel, h.nouveau_statut AS dernier_statut_historique, h.modifie_le
FROM rendez_vous r
JOIN historique_statuts h ON h.rendez_vous_id = r.id
WHERE h.id = (SELECT MAX(h2.id) FROM historique_statuts h2 WHERE h2.rendez_vous_id = r.id)
  AND h.nouveau_statut <> r.statut;

-- B7. Rendez-vous pris dans le passé (cree_le postérieur à l'heure du rendez-vous).
SELECT id, npi, date_heure, cree_le, statut
FROM rendez_vous
WHERE datetime(cree_le) > datetime(date_heure);

-- B8. Rendez-vous déjà « honore » ou « absent » alors que l'heure n'est pas encore arrivée à la date de l'export.
SELECT id, npi, date_heure, statut
FROM rendez_vous
WHERE statut IN ('honore', 'absent')
  AND datetime(date_heure) > datetime('2026-11-20 18:00:00');
