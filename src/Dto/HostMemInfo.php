<?php

namespace App\Dto;

class HostMemInfo
{
    public function __construct(
        public string $total,
        public string $used,
        public string $free,
        public string $shared,
        public string $buff_cache,
        public string $available
    ) {}
}