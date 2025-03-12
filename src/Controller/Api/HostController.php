<?php

namespace App\Controller\Api;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\ApiResource;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Process\Process;

use App\Dto\HostInfo;
use App\Dto\HostCpuInfo;
use App\Dto\HostMemInfo;
use App\Dto\HostDiskInfo;

use Symfony\Contracts\Translation\TranslatorInterface;

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
            output: HostCpuInfo::class,
            read: false
        ),
        new Get(
            name: 'api_mem_info',
            uriTemplate: '/host/mem',
            controller: self::class.'::getMemInfo',
            output: HostMemInfo::class,
            read: false
        ),
        new Get(
            name: 'api_disk_info',
            uriTemplate: '/host/disk',
            controller: self::class.'::getDiskInfo',
            output: HostDiskInfo::class,
            read: false
        )
    ]
)]



class HostController extends AbstractController
{

    public function __construct(
        private readonly TranslatorInterface $translator
    ) {}

    /**
     * Holt Systeminformationen via hostnamectl
     * Gibt grundlegende Systeminformationen wie Hostname, Betriebssystem und Hardware zurück
     */
    public function getSystemInfo(): JsonResponse
    {
        try {
            // System-Informationen via hostnamectl
            $process = new Process(['hostnamectl', 'status', '--json=pretty']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput());
            }
            
            /** @var array<string,string> $systemInfo */
            $systemInfo = json_decode($process->getOutput(), true) ?? [];
            
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


    /**
     * Liest CPU-Zeitinformationen aus /proc/stat
     * @return array<int,array{
     *   cpu: string,
     *   user: int,
     *   nice: int,
     *   system: int,
     *   idle: int,
     *   iowait: int,
     *   irq: int,
     *   softirq: int
     * }>
     * @throws \RuntimeException wenn /proc/stat nicht lesbar ist
     */
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
                if ($parts !== false && count($parts) >= 8) {
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

    /**
     * Berechnet CPU-Auslastung durch Vergleich zweier Messzeitpunkte
     * Wartet 100ms zwischen den Messungen für aussagekräftige Werte
     */
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
                'error' => $this->translator->trans('error.cpu_info'),
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Liest Speicherinformationen mit dem 'free' Befehl
     * Gibt Gesamtspeicher, verwendeten Speicher, freien Speicher und Cache-Informationen zurück
     */
    public function getMemInfo(): JsonResponse
    {
        try {
            // Benutze -b für Bytes statt human-readable Format
            $process = new Process(['env', 'LANG=C', 'free', '-b']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput());
            }
            
            $output = $process->getOutput();
            
            // Vereinfachtes Pattern für das Standard-Format von free
            if (preg_match('/^Mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $output, $matches)) {
                // Konvertiere Bytes in GB für bessere Lesbarkeit
                $toGB = fn($bytes) => round($bytes / (1024 * 1024 * 1024), 2) . 'G';
                
                return new JsonResponse([
                    'total' => $toGB((int)$matches[1]),
                    'used' => $toGB((int)$matches[2]),
                    'free' => $toGB((int)$matches[3]),
                    'shared' => $toGB((int)$matches[4]),
                    'buff_cache' => $toGB((int)$matches[5]),
                    'available' => $toGB((int)$matches[6])
                ]);
            }
            
            throw new \RuntimeException('Unable to parse memory output');
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to fetch memory information',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ermittelt Festplatteninformationen mit dem 'df' Befehl
     * Zeigt Dateisysteme, Größe, Verwendung und Einhängepunkte an
     * Temporäre Dateisysteme werden ausgeschlossen
     */
    public function getDiskInfo(): JsonResponse
    {
        try {
            // Benutze df ohne temporäre Filesysteme
            $process = new Process(['df', '-h', '-x', 'devtmpfs', '-x', 'tmpfs']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput());
            }
            
            $output = $process->getOutput();
            $lines = explode("\n", trim($output));
            $headers = preg_split('/\s+/', array_shift($lines));
            
            if ($headers === false) {
                throw new \RuntimeException('Failed to parse headers');
            }
            
            $result = [];
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                
                // Split die Zeile, aber behalte den letzten Teil (Mount-Punkt) intakt
                $values = preg_split('/\s+/', $line, count($headers) - 1);
                if ($values === false) {
                    continue;
                }
                
                if (count($values) === count($headers) - 1) {
                    $values[] = substr($line, strrpos($line, ' ') + 1);
                    
                    $result[] = [
                        'Filesystem' => $values[0],
                        'Size' => $values[1],
                        'Used' => $values[2],
                        'Avail' => $values[3],
                        'Use' => $values[4],
                        'Mounted' => $values[5]
                    ];
                }
            }
            
            return new JsonResponse($result);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to fetch disk information',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}