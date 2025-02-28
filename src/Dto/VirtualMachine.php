<?php

namespace App\Dto;

class VirtualMachine
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public int $state = 0,
        public int $memory = 0,
        public int $maxMemory = 0,
        public int $cpuCount = 0
    ) {}
}