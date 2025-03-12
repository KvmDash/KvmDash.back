<?php

namespace App\Dto;

/**
 * Repräsentiert Informationen über den Arbeitsspeicher des Host-Systems.
 * Diese DTO-Klasse speichert detaillierte Speichernutzungsdaten des Servers.
 */
class HostMemInfo
{
    /**
     * Erstellt eine neue HostMemInfo-Instanz.
     * 
     * @param string $total Gesamter verfügbarer Arbeitsspeicher
     * @param string $used Aktuell genutzter Arbeitsspeicher
     * @param string $free Freier Arbeitsspeicher
     * @param string $shared Gemeinsam genutzter Arbeitsspeicher
     * @param string $buff_cache Vom Buffer/Cache verwendeter Speicher
     * @param string $available Tatsächlich verfügbarer Speicher
     */
    public function __construct(
        public string $total,
        public string $used,
        public string $free,
        public string $shared,
        public string $buff_cache,
        public string $available
    ) {}
}