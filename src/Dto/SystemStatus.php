<?php

namespace App\Dto;

/**
 * Repräsentiert den aktuellen Systemstatus des Servers.
 * Diese DTO-Klasse speichert Informationen über CPU, Speicher und Festplattenauslastung.
 */
class SystemStatus
{
    /**
     * Erstellt eine neue SystemStatus-Instanz.
     * 
     * @param string|null $cpuInfo Details zur CPU-Auslastung und -Status
     * @param string|null $memoryInfo Details zur Speichernutzung und -verfügbarkeit
     * @param string|null $diskInfo Details zur Festplattennutzung und -verfügbarkeit
     * @param DateTime|null $timestamp Zeitpunkt der Statuserfassung
     */
    public function __construct(
        public ?string $cpuInfo = null,
        public ?string $memoryInfo = null,
        public ?string $diskInfo = null,
        public ?\DateTime $timestamp = null
    ) {}
}