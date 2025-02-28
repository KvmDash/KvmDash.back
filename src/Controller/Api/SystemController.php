<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/system', name: 'api_system_')]
class SystemController extends AbstractController
{
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function getSystemStatus(): JsonResponse
    {
        // CPU-Auslastung
        $cpuProcess = new Process(['top', '-bn1']);
        $cpuProcess->run();
        
        // RAM-Nutzung
        $memProcess = new Process(['free', '-m']);
        $memProcess->run();
        
        // Disk-Nutzung
        $diskProcess = new Process(['df', '-h']);
        $diskProcess->run();
        
        return $this->json([
            'cpu_info' => $cpuProcess->isSuccessful() ? $cpuProcess->getOutput() : 'Error',
            'memory_info' => $memProcess->isSuccessful() ? $memProcess->getOutput() : 'Error',
            'disk_info' => $diskProcess->isSuccessful() ? $diskProcess->getOutput() : 'Error',
            'timestamp' => new \DateTime()
        ]);
    }
    
    #[Route('/execute', name: 'execute', methods: ['POST'])]
    public function executeCommand(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $command = $data['command'] ?? null;
        
        if (!$command) {
            return $this->json(['error' => 'Kein Befehl angegeben'], 400);
        }
        
        // WICHTIG: Hier solltest du unbedingt Sicherheitsmaßnahmen einbauen!
        // Das ist nur eine Demonstration und sollte in der Produktion
        // stark eingeschränkt werden, um Injection-Angriffe zu verhindern
        
        // Beispiel für eine sehr einfache Whitelist (besser noch verfeinern!)
        $allowedCommands = ['uptime', 'hostname', 'date'];
        $commandParts = explode(' ', $command);
        
        if (!in_array($commandParts[0], $allowedCommands)) {
            return $this->json(['error' => 'Unerlaubter Befehl'], 403);
        }
        
        $process = Process::fromShellCommandline($command);
        $process->run();
        
        return $this->json([
            'command' => $command,
            'success' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode()
        ]);
    }
}