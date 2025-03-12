<?php

namespace App\Dto;

/**
 * Repräsentiert Informationen über die CPU-Nutzung des Host-Systems.
 * Diese DTO-Klasse speichert detaillierte CPU-Auslastungsdaten des Servers.
 */
class HostCpuInfo
{
    /**
     * Erstellt eine neue HostCpuInfo-Instanz.
     * 
     * @param string $cpu CPU-Bezeichner (z.B. cpu0, cpu1)
     * @param int $total Gesamte CPU-Zeit in Ticks
     * @param int $idle CPU-Zeit im Leerlauf
     * @param int $used Aktiv genutzte CPU-Zeit
     * @param float $usage Prozentuale CPU-Auslastung
     */
    public function __construct(
        public string $cpu,
        public int $total,
        public int $idle,
        public int $used,
        public float $usage
    ) {}
}