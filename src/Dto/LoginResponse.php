<?php

namespace App\Dto;

/**
 * DTO (Data Transfer Object) für die Login-Antwort
 * 
 * Diese Klasse kapselt die Daten, die nach einem erfolgreichen Login
 * an den Client zurückgegeben werden. Sie enthält die Benutzeridentifikation
 * und die zugewiesenen Rollen.
 */
class LoginResponse
{
    /**
     * Erstellt eine neue LoginResponse-Instanz
     * 
     * @param string $user Die Benutzerkennung (typischerweise die E-Mail-Adresse)
     * @param array<int,string> $roles Array von Benutzer-Rollen (z.B. 'ROLE_USER', 'ROLE_ADMIN')
     */
    public function __construct(
        public readonly string $user,
        public readonly array $roles,
    ) {}
}