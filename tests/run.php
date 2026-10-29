<?php

/**
 * Tests automatisés – aucun outil à installer : php tests/run.php
 * Code de sortie 0 si tout passe, 1 sinon.
 *
 * Chaque test TMA-1xx rejoue le cas décrit dans le ticket. Avec le comportement
 * signalé (anomalie), l'assertion échouerait.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Verdict.php';
require __DIR__ . '/../src/GestionRendezVous.php';

use Tma\Rdv\GestionRendezVous as G;
use Tma\Rdv\Verdict;

// ---------------------------------------------------------------- mini-framework

$GLOBALS['total'] = 0;
$GLOBALS['echecs'] = 0;

function test(string $nom, callable $corps): void
{
    $GLOBALS['total']++;
    try {
        $corps();
        echo "  OK     $nom\n";
    } catch (Throwable $e) {
        $GLOBALS['echecs']++;
        echo "  ECHEC  $nom\n         " . $e->getMessage() . "\n";
    }
}

function exiger(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function egal($attendu, $obtenu, string $libelle): void
{
    if ($attendu !== $obtenu) {
        throw new RuntimeException(
            $libelle . ' : attendu ' . var_export($attendu, true) . ', obtenu ' . var_export($obtenu, true)
        );
    }
}

function exigerRefus(Verdict $v, int $code, string $message): void
{
    egal(false, $v->accepte, 'accepte');
    egal($code, $v->codeHttp, 'code HTTP');
    egal($message, $v->message, 'message');
}

function exigerOk(Verdict $v): void
{
    if (!$v->accepte) {
        throw new RuntimeException("devait être accepté, refusé avec {$v->codeHttp} « {$v->message} »");
    }
}

function a(string $dateHeure): DateTimeImmutable
{
    return new DateTimeImmutable($dateHeure, new DateTimeZone('+01:00'));
}

function rdv(int $id, string $npi, string $service, string $dateHeure, string $statut = 'confirme'): array
{
    return ['id' => $id, 'npi' => $npi, 'service' => $service, 'dateHeure' => $dateHeure, 'statut' => $statut];
}

// ---------------------------------------------------------------- tickets

echo "\nTickets\n";

// TMA-101 – ANOMALIE : un samedi était accepté (201).
test('TMA-101 un rendez-vous le samedi 14/11/2026 est refusé (week-end)', function () {
    $v = G::verifierPrise('0614253647', 'PASSEPORT', '2026-11-14T10:00', [], a('2026-11-09T09:00'));
    exigerRefus($v, 409, 'Le centre est fermé le week-end.');
});

test('TMA-101 (complément) le dimanche est refusé, le lundi est accepté', function () {
    exigerRefus(G::verifierPrise('0614253647', 'CNI', '2026-11-15T10:00', [], a('2026-11-09T09:00')), 409, G::MSG_WEEKEND);
    exigerOk(G::verifierPrise('0614253647', 'CNI', '2026-11-16T10:00', [], a('2026-11-09T09:00')));
});

// TMA-102 – ANOMALIE : un 4e usager était accepté sur un créneau de 3 places.
test('TMA-102 le 4e usager du mardi 10/11/2026 à 09:00 est refusé (créneau complet)', function () {
    $existants = [
        rdv(632, '0600000001', 'CNI', '2026-11-10T09:00'),
        rdv(644, '0600000002', 'PASSEPORT', '2026-11-10T09:00'),
        rdv(681, '0600000003', 'PERMIS', '2026-11-10T09:00'),
    ];
    $v = G::verifierPrise('0600000004', 'CNI', '2026-11-10T09:00', $existants, a('2026-11-03T10:00'));
    exigerRefus($v, 409, 'Ce créneau est complet.');
});

test('TMA-102 (complément) le 3e usager est accepté ; un rendez-vous annulé libère sa place', function () {
    $deux = [
        rdv(632, '0600000001', 'CNI', '2026-11-10T09:00'),
        rdv(644, '0600000002', 'PASSEPORT', '2026-11-10T09:00'),
    ];
    exigerOk(G::verifierPrise('0600000004', 'CNI', '2026-11-10T09:00', $deux, a('2026-11-03T10:00')));

    $troisAvecUnAnnule = [
        rdv(632, '0600000001', 'CNI', '2026-11-10T09:00'),
        rdv(644, '0600000002', 'PASSEPORT', '2026-11-10T09:00', 'annule'),
        rdv(681, '0600000003', 'PERMIS', '2026-11-10T09:00'),
    ];
    exigerOk(G::verifierPrise('0600000004', 'CNI', '2026-11-10T09:00', $troisAvecUnAnnule, a('2026-11-03T10:00')));
});

// TMA-103 – ANOMALIE : un NPI avec une lettre provoquait une erreur 500.
test('TMA-103 le NPI « 06142A3647 » est refusé en 400 avec le message prévu', function () {
    $v = G::verifierPrise('06142A3647', 'CNI', '2026-11-23T09:00', [], a('2026-11-20T10:00'));
    exigerRefus($v, 400, 'Le NPI doit comporter exactement 10 chiffres.');
});

test('TMA-103 (complément) NPI trop court, trop long, vide, avec retour à la ligne, non texte', function () {
    foreach (['061425364', '06142536470', '', "0614253647\n", ' 0614253647', '٠٦١٤٢٥٣٦٤٧'] as $npi) {
        exigerRefus(
            G::verifierPrise($npi, 'CNI', '2026-11-23T09:00', [], a('2026-11-20T10:00')),
            400,
            G::MSG_NPI
        );
    }
    exigerRefus(G::verifierPrise(614253647, 'CNI', '2026-11-23T09:00', [], a('2026-11-20T10:00')), 400, G::MSG_NPI);
    exigerRefus(G::verifierPrise(null, 'CNI', '2026-11-23T09:00', [], a('2026-11-20T10:00')), 400, G::MSG_NPI);
});

// TMA-104 – ANOMALIE : annulation refusée alors qu'il restait 40 h.
test('TMA-104 le 17/11/2026 à 18:00, annuler le rendez-vous 709 du 19/11/2026 à 10:00 (40 h avant) est accepté', function () {
    $r = rdv(709, '0700000001', 'CNI', '2026-11-19T10:00');
    exigerOk(G::verifierAnnulation($r, a('2026-11-17T18:00')));
});

test('TMA-104 (complément) frontière : exactement 24 h accepté, 23 h 59 refusé', function () {
    $r = rdv(709, '0700000001', 'CNI', '2026-11-19T10:00');
    exigerOk(G::verifierAnnulation($r, a('2026-11-18T10:00')));
    exigerRefus(G::verifierAnnulation($r, a('2026-11-18T10:01')), 409, 'Annulation impossible moins de 24 h avant le rendez-vous.');
});

test('TMA-104 (complément) un rendez-vous non confirmé ne peut pas être annulé', function () {
    foreach (['annule', 'honore', 'absent'] as $statut) {
        $r = rdv(1, '0700000001', 'CNI', '2026-12-01T10:00', $statut);
        exigerRefus(G::verifierAnnulation($r, a('2026-11-20T10:00')), 409, 'Ce rendez-vous ne peut plus être annulé.');
    }
});

// TMA-105 – ANOMALIE : un rendez-vous annulé passait à « honore ».
test('TMA-105 le rendez-vous 292 « annule » ne peut pas passer à « honore »', function () {
    $r = rdv(292, '0330677555', 'CNI', '2026-11-12T09:00', 'annule');
    exigerRefus(G::verifierChangementStatut($r, 'honore'), 409, 'Ce rendez-vous est déjà clôturé.');
});

test('TMA-105 (complément) confirme -> honore/absent accepté ; honore/absent définitifs ; statut inconnu refusé', function () {
    $confirme = rdv(1, '0330677555', 'CNI', '2026-11-12T09:00');
    exigerOk(G::verifierChangementStatut($confirme, 'honore'));
    exigerOk(G::verifierChangementStatut($confirme, 'absent'));

    $honore = rdv(2, '0330677555', 'CNI', '2026-11-12T09:00', 'honore');
    exigerRefus(G::verifierChangementStatut($honore, 'absent'), 409, G::MSG_CLOTURE);

    foreach (['annule', 'confirme', 'HONORE', '', 'nimporte'] as $inconnu) {
        exigerRefus(G::verifierChangementStatut($confirme, $inconnu), 400, 'Statut invalide : honore ou absent.');
    }
});

// TMA-106 – ANOMALIE : liste non triée et page 1 de 9 éléments au lieu de 10.
test('TMA-106 la page 1 compte 10 rendez-vous, le prochain (02/12/2026) en tête, puis du plus récent au plus ancien', function () {
    $npi = '0203040506';
    // Ordre « de la base » : celui des dates relevées dans le ticket, puis les 4 autres.
    $tous = [
        rdv(1, $npi, 'CNI', '2026-07-28T09:30', 'honore'),
        rdv(2, $npi, 'CNI', '2026-09-25T11:00', 'honore'),
        rdv(3, $npi, 'CNI', '2026-04-21T09:00', 'honore'),
        rdv(4, $npi, 'CNI', '2026-05-19T15:00', 'absent'),
        rdv(5, $npi, 'CNI', '2026-06-16T13:30', 'honore'),
        rdv(6, $npi, 'CNI', '2026-02-12T10:00', 'honore'),
        rdv(7, $npi, 'CNI', '2026-08-11T14:00', 'honore'),
        rdv(8, $npi, 'CNI', '2026-05-08T11:30', 'honore'),
        rdv(9, $npi, 'CNI', '2026-07-07T10:30', 'honore'),
        rdv(10, $npi, 'PASSEPORT', '2026-12-02T09:00', 'confirme'),
        rdv(11, $npi, 'CNI', '2026-10-14T08:30', 'honore'),
        rdv(12, $npi, 'CNI', '2026-03-04T14:30', 'absent'),
        rdv(13, $npi, 'CNI', '2026-01-15T10:00', 'annule'),
        rdv(14, '0999999999', 'CNI', '2026-11-25T10:00'), // autre usager : ne doit pas apparaître
    ];
    $maintenant = a('2026-11-20T18:00');

    $p1 = G::listerRendezVous($tous, $npi, $maintenant, 1);
    egal(1, $p1['page'], 'page');
    egal(10, $p1['parPage'], 'parPage');
    egal(13, $p1['total'], 'total');
    egal(10, count($p1['rendezVous']), 'nombre de rendez-vous en page 1');
    egal('2026-12-02T09:00', $p1['rendezVous'][0]['dateHeure'], 'premier rendez-vous (le prochain)');
    egal(
        [
            '2026-12-02T09:00', '2026-10-14T08:30', '2026-09-25T11:00', '2026-08-11T14:00', '2026-07-28T09:30',
            '2026-07-07T10:30', '2026-06-16T13:30', '2026-05-19T15:00', '2026-05-08T11:30', '2026-04-21T09:00',
        ],
        array_column($p1['rendezVous'], 'dateHeure'),
        'ordre de la page 1'
    );

    $p2 = G::listerRendezVous($tous, $npi, $maintenant, 2);
    egal(
        ['2026-03-04T14:30', '2026-02-12T10:00', '2026-01-15T10:00'],
        array_column($p2['rendezVous'], 'dateHeure'),
        'page 2'
    );

    $ids = array_merge(array_column($p1['rendezVous'], 'id'), array_column($p2['rendezVous'], 'id'));
    egal(13, count(array_unique($ids)), 'aucun rendez-vous perdu ni dupliqué entre les pages');
    egal([], G::listerRendezVous($tous, $npi, $maintenant, 3)['rendezVous'], 'page 3 vide');
});

// TMA-107 – CONFORME : un rendez-vous à venir bloque une nouvelle prise, tous services confondus.
test('TMA-107 (conforme) PERMIS refusé car un rendez-vous PASSEPORT est déjà à venir', function () {
    $existants = [rdv(500, '0102030405', 'PASSEPORT', '2026-11-25T10:00')];
    $v = G::verifierPrise('0102030405', 'PERMIS', '2026-11-27T09:00', $existants, a('2026-11-20T10:00'));
    exigerRefus($v, 409, 'Vous avez déjà un rendez-vous à venir.');
});

test('TMA-107 (complément) ne bloquent pas : rendez-vous annulé, passé, ou d’un autre usager', function () {
    $existants = [
        rdv(500, '0102030405', 'PASSEPORT', '2026-11-25T10:00', 'annule'),
        rdv(501, '0102030405', 'CNI', '2026-10-01T10:00', 'honore'),
        rdv(502, '0102030406', 'CNI', '2026-11-25T10:00'),
    ];
    exigerOk(G::verifierPrise('0102030405', 'PERMIS', '2026-11-27T09:00', $existants, a('2026-11-20T10:00')));
});

// ---------------------------------------------------------------- autres règles de la section 2

echo "\nAutres règles de la section 2\n";

test('service inconnu -> 400', function () {
    foreach (['VISA', 'passeport', '', 'PASSEPORT '] as $service) {
        exigerRefus(G::verifierPrise('0614253647', $service, '2026-11-23T09:00', [], a('2026-11-20T10:00')), 400, G::MSG_SERVICE);
    }
});

test('date et heure illisibles -> 400', function () {
    foreach (['', 'demain', '2026-11-23 09:00', '23/11/2026T09:00', '2026-11-23T9:00', '2026-11-23T09:00:00',
              '2026-02-30T09:00', '2026-11-23T24:00', '2026-13-01T09:00', '2026-11-23T09:60'] as $saisie) {
        exigerRefus(G::verifierPrise('0614253647', 'CNI', $saisie, [], a('2026-11-20T10:00')), 400, G::MSG_DATE_ILLISIBLE);
    }
});

test('date passée -> 409', function () {
    exigerRefus(G::verifierPrise('0614253647', 'CNI', '2026-11-19T09:00', [], a('2026-11-20T10:00')), 409, G::MSG_PASSE);
});

test('grille horaire : 08:00 à 15:30 toutes les 30 min', function () {
    foreach (['08:00', '08:30', '12:00', '15:00', '15:30'] as $h) {
        exigerOk(G::verifierPrise('0614253647', 'CNI', "2026-11-23T$h", [], a('2026-11-20T10:00')));
    }
    foreach (['07:30', '08:15', '09:10', '16:00', '12:45', '00:00', '23:30'] as $h) {
        exigerRefus(G::verifierPrise('0614253647', 'CNI', "2026-11-23T$h", [], a('2026-11-20T10:00')), 409, G::MSG_GRILLE);
    }
});

test('créneau complet : seuls les rendez-vous non annulés comptent, quel que soit leur statut', function () {
    $existants = [
        rdv(1, '0600000001', 'CNI', '2026-11-23T09:00', 'confirme'),
        rdv(2, '0600000002', 'CNI', '2026-11-23T09:00', 'confirme'),
        rdv(3, '0600000003', 'CNI', '2026-11-23T09:00', 'confirme'),
        rdv(4, '0600000009', 'CNI', '2026-11-23T09:30', 'confirme'), // autre créneau
    ];
    exigerRefus(G::verifierPrise('0600000010', 'CNI', '2026-11-23T09:00', $existants, a('2026-11-20T10:00')), 409, G::MSG_COMPLET);
    exigerOk(G::verifierPrise('0600000010', 'CNI', '2026-11-23T10:00', $existants, a('2026-11-20T10:00')));
});

test('prise valide acceptée', function () {
    exigerOk(G::verifierPrise('0614253647', 'PASSEPORT', '2026-11-23T09:00', [], a('2026-11-20T10:00')));
});

test('toutes les heures sont celles du Bénin (GMT+1) : « maintenant » en UTC est converti', function () {
    // 2026-11-23T08:30 UTC = 09:30 à Cotonou : le créneau de 09:00 est déjà passé.
    $maintenantUtc = new DateTimeImmutable('2026-11-23T08:30:00', new DateTimeZone('UTC'));
    exigerRefus(G::verifierPrise('0614253647', 'CNI', '2026-11-23T09:00', [], $maintenantUtc), 409, G::MSG_PASSE);
    // Annulation : 2026-11-21T09:00 (Bénin) = 08:00 UTC ; à 2026-11-20T07:59 UTC il reste 24 h 01.
    $r = rdv(1, '0614253647', 'CNI', '2026-11-21T09:00');
    exigerOk(G::verifierAnnulation($r, new DateTimeImmutable('2026-11-20T07:59:00', new DateTimeZone('UTC'))));
    exigerRefus(G::verifierAnnulation($r, new DateTimeImmutable('2026-11-20T08:01:00', new DateTimeZone('UTC'))), 409, G::MSG_ANNULATION_TARDIVE);
});

// ---------------------------------------------------------------- bilan

echo "\n" . ($GLOBALS['total'] - $GLOBALS['echecs']) . '/' . $GLOBALS['total'] . " tests réussis\n";
exit($GLOBALS['echecs'] === 0 ? 0 : 1);
