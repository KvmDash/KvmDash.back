<?php

namespace App\Dto;

/**
 * Repräsentiert die Antwort nach einem Login-Versuch.
 * Diese DTO-Klasse speichert die Benutzeridentifikation und zugewiesene Rollen.
 */
class LoginResponse
{
    /**
     * Erstellt eine neue LoginResponse-Instanz.
     * 
     * @param string $user Eindeutige Benutzerkennung des eingeloggten Users
     * @param array<int,string> $roles Liste der dem Benutzer zugewiesenen Rollen
     */
    public function __construct(
        public readonly string $user,
        public readonly array $roles,
    ) {}
}