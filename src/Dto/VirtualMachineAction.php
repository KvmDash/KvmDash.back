<?php

namespace App\Dto;

/**
 * Repräsentiert eine Aktion, die auf einer virtuellen Maschine ausgeführt wurde.
 * Diese DTO-Klasse speichert das Ergebnis einer VM-Operation sowie zugehörige Informationen.
 */
class VirtualMachineAction
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $domain = null,
        public readonly ?string $action = null,
        public readonly ?string $error = null,
        public readonly ?array $details = null
    ) {
    }
}