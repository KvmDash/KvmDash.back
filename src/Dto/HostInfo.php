<?php

namespace App\Dto;

/**
 * Repräsentiert Systeminformationen des Host-Systems.
 * Diese DTO-Klasse speichert grundlegende Hardware- und Betriebssystemdetails.
 */
final class HostInfo
{
    /**
     * Erstellt eine neue HostInfo-Instanz.
     * 
     * @param string $Hostname Name des Host-Systems
     * @param string $OperatingSystemPrettyName Lesbare Bezeichnung des Betriebssystems
     * @param string $KernelName Name des verwendeten Kernels
     * @param string $KernelRelease Version des Kernels
     * @param string $HardwareVendor Hersteller der Hardware
     * @param string $HardwareModel Modellbezeichnung der Hardware
     */
    public function __construct(
        public string $Hostname,
        public string $OperatingSystemPrettyName,
        public string $KernelName,
        public string $KernelRelease,
        public string $HardwareVendor,
        public string $HardwareModel
    ) {}
}