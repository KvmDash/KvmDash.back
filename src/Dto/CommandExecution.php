<?php

namespace App\Dto;

/**
 * Repräsentiert das Ergebnis einer Kommandozeilenausführung.
 * Diese DTO-Klasse speichert Details über die Ausführung eines Shell-Befehls.
 */
class CommandExecution
{
    /**
     * Erstellt eine neue CommandExecution-Instanz.
     * 
     * @param string $command Ausgeführter Shell-Befehl
     * @param bool $success Gibt an, ob der Befehl erfolgreich ausgeführt wurde
     * @param string|null $output Ausgabe des Befehls (stdout)
     * @param string|null $error Fehlermeldungen des Befehls (stderr)
     * @param int $exitCode Exit-Code der Befehlsausführung
     */
    public function __construct(
        public string $command = '',
        public bool $success = false,
        public ?string $output = null,
        public ?string $error = null,
        public int $exitCode = 0
    ) {}
}