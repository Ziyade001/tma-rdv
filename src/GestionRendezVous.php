<?php

declare(strict_types=1);

namespace Tma\Rdv;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Règles de gestion de la prise de rendez-vous au guichet (section 2 du sujet).
 *
 * Le module est pur : aucune base de données, aucun framework, aucune lecture
 * de l'horloge système. L'heure courante est toujours passée en paramètre.
 *
 * Un rendez-vous est un tableau associatif :
 *   [
 *     'id'        => int,
 *     'npi'       => string,                       // 10 chiffres (chaîne : le 0 initial compte)
 *     'service'   => 'PASSEPORT' | 'CNI' | 'PERMIS',
 *     'dateHeure' => string|DateTimeInterface,     // heure du Bénin (GMT+1)
 *     'statut'    => 'confirme' | 'honore' | 'absent' | 'annule',
 *   ]
 */
final class GestionRendezVous
{
    public const SERVICES = ['PASSEPORT', 'CNI', 'PERMIS'];
    public const STATUTS_CLOTURE = ['honore', 'absent'];

    public const CAPACITE_CRENEAU = 3;
    public const DELAI_ANNULATION_SECONDES = 24 * 3600;
    public const PAR_PAGE = 10;

    public const MSG_NPI = 'Le NPI doit comporter exactement 10 chiffres.';
    public const MSG_SERVICE = 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.';
    public const MSG_DATE_ILLISIBLE = 'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).';
    public const MSG_PASSE = 'Impossible de prendre un rendez-vous dans le passé.';
    public const MSG_WEEKEND = 'Le centre est fermé le week-end.';
    public const MSG_GRILLE = 'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.';
    public const MSG_DEJA_A_VENIR = 'Vous avez déjà un rendez-vous à venir.';
    public const MSG_COMPLET = 'Ce créneau est complet.';
    public const MSG_NON_ANNULABLE = 'Ce rendez-vous ne peut plus être annulé.';
    public const MSG_ANNULATION_TARDIVE = 'Annulation impossible moins de 24 h avant le rendez-vous.';
    public const MSG_STATUT = 'Statut invalide : honore ou absent.';
    public const MSG_CLOTURE = 'Ce rendez-vous est déjà clôturé.';

    /** Toutes les heures sont celles du Bénin (GMT+1, pas de changement d'heure). */
    private static function fuseau(): DateTimeZone
    {
        return new DateTimeZone('+01:00');
    }

    /**
     * Lit une date saisie par l'usager. Format strict AAAA-MM-JJTHH:MM.
     * Renvoie null si la saisie est illisible ou si la date n'existe pas
     * (30 février, 24:00, 9:00 sans zéro, secondes, etc.).
     */
    public static function lireDateHeureSaisie(string $saisie): ?DateTimeImmutable
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $saisie) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $saisie, self::fuseau());
        if ($date === false || $date->format('Y-m-d\TH:i') !== $saisie) {
            return null; // ex. 2026-02-30 ou 2026-11-10T24:00 : PHP « corrige » au lieu de refuser
        }
        return $date;
    }

    /** Convertit une date déjà stockée (chaîne ou objet) en DateTimeImmutable, heure du Bénin. */
    private static function enDate($valeur): DateTimeImmutable
    {
        if ($valeur instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($valeur)->setTimezone(self::fuseau());
        }
        return (new DateTimeImmutable((string) $valeur, self::fuseau()))->setTimezone(self::fuseau());
    }

    /** Le créneau est sur la grille : 08:00 à 15:30, toutes les 30 minutes. */
    private static function estSurGrille(DateTimeImmutable $creneau): bool
    {
        $heure = (int) $creneau->format('G');
        $minute = (int) $creneau->format('i');
        return $heure >= 8 && $heure <= 15 && ($minute === 0 || $minute === 30);
    }

    /**
     * Contrôle une prise de rendez-vous.
     *
     * @param mixed                $npi
     * @param mixed                $service
     * @param mixed                $dateHeure  AAAA-MM-JJTHH:MM, heure du Bénin
     * @param array<int, array>    $rendezVousExistants tous les rendez-vous connus (au moins ceux de
     *                                                 l'usager et ceux du créneau demandé)
     * @param DateTimeInterface    $maintenant heure courante (injectée pour pouvoir tester)
     *
     * Ordre des contrôles = ordre du tableau de la section 2.
     */
    public static function verifierPrise(
        $npi,
        $service,
        $dateHeure,
        array $rendezVousExistants,
        DateTimeInterface $maintenant
    ): Verdict {
        // 1. NPI : exactement 10 chiffres. \z (et non $) : "0614253647\n" doit être refusé.
        if (!is_string($npi) || preg_match('/\A[0-9]{10}\z/', $npi) !== 1) {
            return Verdict::refus(400, self::MSG_NPI);
        }

        // 2. Service
        if (!is_string($service) || !in_array($service, self::SERVICES, true)) {
            return Verdict::refus(400, self::MSG_SERVICE);
        }

        // 3. Date et heure lisibles
        $creneau = is_string($dateHeure) ? self::lireDateHeureSaisie($dateHeure) : null;
        if ($creneau === null) {
            return Verdict::refus(400, self::MSG_DATE_ILLISIBLE);
        }

        $maintenantTs = self::enDate($maintenant)->getTimestamp();
        $creneauTs = $creneau->getTimestamp();

        // 4. Date passée (strictement avant l'heure courante)
        if ($creneauTs < $maintenantTs) {
            return Verdict::refus(409, self::MSG_PASSE);
        }

        // 5. Samedi (6) ou dimanche (7)
        if ((int) $creneau->format('N') >= 6) {
            return Verdict::refus(409, self::MSG_WEEKEND);
        }

        // 6. Grille horaire
        if (!self::estSurGrille($creneau)) {
            return Verdict::refus(409, self::MSG_GRILLE);
        }

        // 7. Un seul rendez-vous à venir par usager, tous services confondus
        foreach ($rendezVousExistants as $rdv) {
            if (($rdv['npi'] ?? null) === $npi && self::estAVenir($rdv, $maintenantTs)) {
                return Verdict::refus(409, self::MSG_DEJA_A_VENIR);
            }
        }

        // 8. Capacité du créneau : 3 usagers au plus. Un rendez-vous annulé libère sa place.
        $occupes = 0;
        foreach ($rendezVousExistants as $rdv) {
            if (($rdv['statut'] ?? null) !== 'annule' && self::enDate($rdv['dateHeure'])->getTimestamp() === $creneauTs) {
                $occupes++;
            }
        }
        if ($occupes >= self::CAPACITE_CRENEAU) { // >= : avec 3 places déjà prises, la 4e est refusée
            return Verdict::refus(409, self::MSG_COMPLET);
        }

        return Verdict::ok();
    }

    /**
     * Contrôle l'annulation d'un rendez-vous par l'usager.
     * Autorisée tant que le rendez-vous est « confirme » et jusqu'à 24 h avant l'heure prévue
     * (exactement 24 h avant : encore autorisée).
     */
    public static function verifierAnnulation(array $rdv, DateTimeInterface $maintenant): Verdict
    {
        if (($rdv['statut'] ?? null) !== 'confirme') {
            return Verdict::refus(409, self::MSG_NON_ANNULABLE);
        }

        // Écart total en secondes entre deux instants. (Ne pas utiliser DateInterval::$h,
        // qui ne donne que la composante « heures » 0-23 d'un écart, pas le total.)
        $ecart = self::enDate($rdv['dateHeure'])->getTimestamp() - self::enDate($maintenant)->getTimestamp();
        if ($ecart < self::DELAI_ANNULATION_SECONDES) {
            return Verdict::refus(409, self::MSG_ANNULATION_TARDIVE);
        }

        return Verdict::ok();
    }

    /**
     * Contrôle le changement de statut fait par un agent : seul « confirme » peut devenir
     * « honore » ou « absent ». Les statuts honore, absent et annule sont définitifs.
     *
     * @param mixed $statutDemande
     */
    public static function verifierChangementStatut(array $rdv, $statutDemande): Verdict
    {
        if (!is_string($statutDemande) || !in_array($statutDemande, self::STATUTS_CLOTURE, true)) {
            return Verdict::refus(400, self::MSG_STATUT);
        }

        if (($rdv['statut'] ?? null) !== 'confirme') {
            return Verdict::refus(409, self::MSG_CLOTURE);
        }

        return Verdict::ok();
    }

    /**
     * Liste paginée des rendez-vous d'un usager : le prochain rendez-vous en tête,
     * puis tous les autres du plus récent au plus ancien. Pages de 10 par défaut.
     *
     * @param array<int, array> $rendezVous tous les rendez-vous (filtrés ici sur le NPI)
     * @return array{page:int, parPage:int, total:int, rendezVous:array<int, array>}
     */
    public static function listerRendezVous(
        array $rendezVous,
        string $npi,
        DateTimeInterface $maintenant,
        int $page = 1,
        int $parPage = self::PAR_PAGE
    ): array {
        $page = max(1, $page);
        $parPage = max(1, $parPage);
        $maintenantTs = self::enDate($maintenant)->getTimestamp();

        $liste = array_values(array_filter(
            $rendezVous,
            static function (array $rdv) use ($npi): bool {
                return ($rdv['npi'] ?? null) === $npi;
            }
        ));

        usort($liste, static function (array $a, array $b) use ($maintenantTs): int {
            $aVenir = self::estAVenir($a, $maintenantTs);
            $bVenir = self::estAVenir($b, $maintenantTs);
            if ($aVenir !== $bVenir) {
                return $aVenir ? -1 : 1; // le prochain rendez-vous d'abord
            }
            $ta = self::enDate($a['dateHeure'])->getTimestamp();
            $tb = self::enDate($b['dateHeure'])->getTimestamp();
            if ($ta !== $tb) {
                // à venir : le plus proche d'abord ; sinon du plus récent au plus ancien
                return $aVenir ? $ta <=> $tb : $tb <=> $ta;
            }
            return ($b['id'] ?? 0) <=> ($a['id'] ?? 0); // départage stable
        });

        return [
            'page' => $page,
            'parPage' => $parPage,
            'total' => count($liste),
            'rendezVous' => array_slice($liste, ($page - 1) * $parPage, $parPage), // départ à 0 : (page-1)*taille
        ];
    }

    /** Rendez-vous « à venir » : confirmé et pas encore passé. */
    private static function estAVenir(array $rdv, int $maintenantTs): bool
    {
        return ($rdv['statut'] ?? null) === 'confirme'
            && self::enDate($rdv['dateHeure'])->getTimestamp() >= $maintenantTs;
    }
}
