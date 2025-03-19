<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Process\Process;

use App\Dto\SystemStatus;
use App\Dto\CommandExecution;


#[ApiResource(
    operations: [
        new Get(
            name: 'api_system_status',
            uriTemplate: '/system/status',
            controller: self::class.'::getSystemStatus',
            output: SystemStatus::class,
            read: false
        ),
        new Post(
            name: 'api_system_execute',
            uriTemplate: '/system/execute',
            controller: self::class.'::executeCommand',
            output: CommandExecution::class,
            read: false
        )
    ]
)]


class SystemController extends AbstractController
{

    public function __construct(
        private readonly TranslatorInterface $translator
    ) {}


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
        
        $status = new SystemStatus(
            cpuInfo: $cpuProcess->isSuccessful() ? $cpuProcess->getOutput() : 'Error',
            memoryInfo: $memProcess->isSuccessful() ? $memProcess->getOutput() : 'Error',
            diskInfo: $diskProcess->isSuccessful() ? $diskProcess->getOutput() : 'Error',
            timestamp: new \DateTime()
        );

        return $this->json($status);
        
    }
    


    public function executeCommand(Request $request): JsonResponse
    {
        // JSON-Daten dekodieren und validieren
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => $this->translator->trans('error.invalid_json')], 400);
        }
    
        if (!isset($data['command']) || !is_string($data['command'])) {
            return $this->json(['error' => $this->translator->trans('error.no_command_specified')], 400);
        }
    
        $command = $data['command'];
    
        // Whitelist-Prüfung für erlaubte Befehle
        $allowedCommands = ['uptime', 'hostname', 'date'];
        $commandParts = explode(' ', $command);
        
        if (!in_array($commandParts[0], $allowedCommands)) {
            return $this->json(['error' => $this->translator->trans('error.command_not_allowed')], 403);
        }
        
        $process = Process::fromShellCommandline($command);
        $process->run();
        
        $exitCode = $process->getExitCode();
        if ($exitCode === null) {
            $exitCode = -1; // Fallback wenn kein Exit-Code verfügbar
        }
        
        return $this->json(new CommandExecution(
            command: $command,
            success: $process->isSuccessful(),
            output: $process->getOutput(),
            error: $process->getErrorOutput(),
            exitCode: $exitCode
        ));
    }
}