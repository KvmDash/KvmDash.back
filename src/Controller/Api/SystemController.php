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


#[ApiResource(
    operations: [
        new Get(
            name: 'api_system_status',
            uriTemplate: '/system/status',
            controller: SystemController::class.'::getSystemStatus',
            read: false
        ),
        new Post(
            name: 'api_system_execute',
            uriTemplate: '/system/execute',
            controller: SystemController::class.'::executeCommand',
            read: false
        )
    ]
)]


class SystemController extends AbstractController
{

    private $translator;

    public function __construct(TranslatorInterface $translator, RequestStack $requestStack)

    {
        $this->translator = $translator;
    
        $request = $requestStack->getCurrentRequest();
        if ($request) {
            $locale = $request->getPreferredLanguage(['en', 'de']) ?? 'de';
            $request->setLocale($locale);
        }
    }


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
    


    public function executeCommand(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $command = $data['command'] ?? null;
        
        if (!$command) {
            return $this->json(['error' =>  $this->translator->trans('error.no_command_specified')], 400);
        }
        
        // WICHTIG: Hier solltest du unbedingt Sicherheitsmaßnahmen einbauen!
        // Das ist nur eine Demonstration und sollte in der Produktion
        // stark eingeschränkt werden, um Injection-Angriffe zu verhindern
        
        // Beispiel für eine sehr einfache Whitelist (besser noch verfeinern!)
        $allowedCommands = ['uptime', 'hostname', 'date'];
        $commandParts = explode(' ', $command);
        
        if (!in_array($commandParts[0], $allowedCommands)) {
            return $this->json(['error' => $this->translator->trans('error.command_not_allowed')], 403);
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