<?php

namespace App\Dto;

/**
 * Repräsentiert Informationen über die Festplatten des Host-Systems.
 * Diese DTO-Klasse speichert detaillierte Festplattennutzungsdaten des Servers.
 */
class HostDiskInfo
{
    /**
     * Erstellt eine neue HostDiskInfo-Instanz.
     * 
     * @param string $filesystem Name/Pfad des Dateisystems
     * @param string $size Gesamtgröße der Partition
     * @param string $used Belegter Speicherplatz
     * @param string $available Verfügbarer Speicherplatz
     * @param string $usePercentage Prozentuale Nutzung
     * @param string $mountPoint Einhängepunkt des Dateisystems
     */
    public function __construct(
        public string $filesystem,
        public string $size,
        public string $used,
        public string $available,
        public string $usePercentage,
        public string $mountPoint
    ) {}
}