<?php

namespace App\Dto;

class CommandExecution
{
    public function __construct(
        public string $command = '',
        public bool $success = false,
        public ?string $output = null,
        public ?string $error = null,
        public int $exitCode = 0
    ) {}
}