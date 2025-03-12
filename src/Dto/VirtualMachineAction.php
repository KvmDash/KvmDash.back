<?php

namespace App\Dto;

/**
 * Repräsentiert eine Aktion, die auf einer virtuellen Maschine ausgeführt wurde.
 * Diese DTO-Klasse speichert das Ergebnis einer VM-Operation sowie zugehörige Informationen.
 */
class VirtualMachineAction
{
    /**
     * Erstellt eine neue VirtualMachineAction-Instanz.
     * 
     * @param bool $success Gibt an, ob die Aktion erfolgreich ausgeführt wurde
     * @param string $domain Name oder Identifier der virtuellen Maschine
     * @param string $action Art der ausgeführten Aktion (z.B. start, stop, restart)
     * @param string|null $error Fehlermeldung im Falle eines Fehlers, sonst null
     */
    public function __construct(
        public bool $success = false,
        public string $domain = '',
        public string $action = '',
        public ?string $error = null
    ) {}
}