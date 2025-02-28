<?php

namespace App\Dto;

class SystemStatus
{
    public function __construct(
        public ?string $cpuInfo = null,
        public ?string $memoryInfo = null,
        public ?string $diskInfo = null,
        public ?\DateTime $timestamp = null
    ) {}
}