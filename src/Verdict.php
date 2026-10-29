<?php

declare(strict_types=1);

namespace Tma\Rdv;

/**
 * Résultat d'un contrôle de règle de gestion.
 *
 * - accepte = true  : l'action est autorisée (codeHttp = 200, message vide)
 * - accepte = false : l'action est refusée, avec le code HTTP et le message prévus
 *                     par la section 2 du sujet.
 */
final class Verdict
{
    public bool $accepte;
    public int $codeHttp;
    public string $message;

    private function __construct(bool $accepte, int $codeHttp, string $message)
    {
        $this->accepte = $accepte;
        $this->codeHttp = $codeHttp;
        $this->message = $message;
    }

    public static function ok(): self
    {
        return new self(true, 200, '');
    }

    public static function refus(int $codeHttp, string $message): self
    {
        return new self(false, $codeHttp, $message);
    }
}
