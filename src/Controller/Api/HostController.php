<?php

namespace App\Controller\Api;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\ApiResource;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Process\Process;

use App\Dto\HostInfo; 

#[ApiResource(
    operations: [
        new Get(
            name: 'api_host_info',
            uriTemplate: '/host/info',
            controller: self::class.'::getSystemInfo',
            output: HostInfo::class,
            read: false
        )
    ]
)]
class HostController extends AbstractController
{
    public function getSystemInfo(): JsonResponse
    {
        try {
            // System-Informationen via hostnamectl
            $process = new Process(['hostnamectl', 'status', '--json=pretty']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput());
            }
            
            $systemInfo = json_decode($process->getOutput(), true);
            
            return new JsonResponse([
                'Hostname' => $systemInfo['StaticHostname'] ?? 'Unknown',
                'OperatingSystemPrettyName' => $systemInfo['OperatingSystemPrettyName'] ?? 'Unknown',
                'KernelName' => $systemInfo['KernelName'] ?? 'Unknown',
                'KernelRelease' => $systemInfo['KernelRelease'] ?? 'Unknown',
                'HardwareVendor' => $systemInfo['HardwareVendor'] ?? 'Unknown',
                'HardwareModel' => $systemInfo['HardwareModel'] ?? 'Unknown'
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to fetch system information',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}