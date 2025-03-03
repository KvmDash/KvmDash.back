<?php

namespace App\Dto;

final class HostInfo
{
    public function __construct(
        public string $Hostname,
        public string $OperatingSystemPrettyName,
        public string $KernelName,
        public string $KernelRelease,
        public string $HardwareVendor,
        public string $HardwareModel
    ) {}
}