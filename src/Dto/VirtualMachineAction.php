<?php

namespace App\Dto;

class VirtualMachineAction
{
    public function __construct(
        public bool $success = false,
        public string $domain = '',
        public string $action = '',
        public ?string $error = null
    ) {}
}