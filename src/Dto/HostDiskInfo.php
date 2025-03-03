<?php

namespace App\Dto;

class HostDiskInfo
{
    public function __construct(
        public string $Filesystem,
        public string $Size,
        public string $Used,
        public string $Avail,
        public string $Use,
        public string $Mounted
    ) {}
}