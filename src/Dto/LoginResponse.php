<?php

namespace App\Dto;

class LoginResponse
{
    public function __construct(
        public readonly string $user,
        public readonly array $roles,
    ) {}
}