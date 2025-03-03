<?php

namespace App\Controller\Api;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\ApiResource;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Process\Process;

use App\Dto\HostInfo;
use App\Dto\CpuInfo;

#[ApiResource(
    operations: [
        new Get(
            name: 'api_host_info',
            uriTemplate: '/host/info',
            controller: self::class.'::getSystemInfo',
            output: HostInfo::class,
            read: false
        ),
        new Get(
            name: 'api_cpu_info',
            uriTemplate: '/host/cpu',
            controller: self::class.'::getCpuInfo',
            output: CpuInfo::class,
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


    // Host information
    private function getCpuTimes(): array
    {
        $statContent = @file_get_contents('/proc/stat');
        if ($statContent === false) {
            throw new \RuntimeException('Failed to read /proc/stat');
        }

        $lines = explode("\n", $statContent);
        $cpuTimes = [];

        foreach ($lines as $line) {
            if (preg_match('/^cpu/', $line)) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 8) {
                    $cpuTimes[] = [
                        'cpu' => $parts[0],
                        'user' => (int)$parts[1],
                        'nice' => (int)$parts[2],
                        'system' => (int)$parts[3],
                        'idle' => (int)$parts[4],
                        'iowait' => (int)$parts[5],
                        'irq' => (int)$parts[6],
                        'softirq' => (int)$parts[7],
                    ];
                }
            }
        }

        return $cpuTimes;
    }

    // Host CPU information
    public function getCpuInfo(): JsonResponse
    {
        try {
            $cpuTimes1 = $this->getCpuTimes();
            usleep(100000); // 100ms warten
            $cpuTimes2 = $this->getCpuTimes();

            if (empty($cpuTimes1) || empty($cpuTimes2)) {
                throw new \RuntimeException('Failed to retrieve CPU times');
            }

            $cpuData = [];
            foreach ($cpuTimes1 as $index => $cpu1) {
                $cpu2 = $cpuTimes2[$index];
                $total1 = array_sum(array_slice($cpu1, 1));
                $total2 = array_sum(array_slice($cpu2, 1));
                $totalDiff = $total2 - $total1;
                $idleDiff = $cpu2['idle'] - $cpu1['idle'];
                $usage = 100 * ($totalDiff - $idleDiff) / $totalDiff;

                $cpuData[] = [
                    'cpu' => $cpu1['cpu'],
                    'total' => $totalDiff,
                    'idle' => $idleDiff,
                    'used' => $totalDiff - $idleDiff,
                    'usage' => round($usage, 2)
                ];
            }

            return new JsonResponse($cpuData);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to fetch CPU information',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}