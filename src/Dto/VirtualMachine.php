<?php

namespace App\Dto;

/**
 * Data Transfer Object für eine virtuelle Maschine
 */
class VirtualMachine
{
    /**
     * @param string $id         Eindeutige ID der VM
     * @param string $name       Name der VM
     * @param int    $state      Aktueller Status der VM (0=aus, 1=an)
     * @param int    $memory     Aktuell verwendeter Arbeitsspeicher in MB
     * @param int    $maxMemory  Maximal verfügbarer Arbeitsspeicher in MB
     * @param int    $cpuCount   Anzahl der CPU-Kerne
     * @param string $ip         IP-Adresse der VM
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public int $state = 0,
        public int $memory = 0,
        public int $maxMemory = 0,
        public int $cpuCount = 0,
        public string $ip = ''
    ) {}
}