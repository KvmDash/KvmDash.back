<?php

namespace App\Dto;

class HostCpuInfo
{
    public function __construct(
        public string $cpu,     // CPU Bezeichner (cpu0, cpu1, etc)
        public int $total,      // Gesamte CPU-Zeit
        public int $idle,       // Idle-Zeit
        public int $used,       // Genutzte Zeit
        public float $usage     // Prozentuale Auslastung
    ) {}
}