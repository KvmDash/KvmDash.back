<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RequestStack;

#[Route('/api/virtualization', name: 'api_virt_')]
class VirtualizationController extends AbstractController
{
    private $connection;
    private $translator;
    
    public function __construct(TranslatorInterface $translator, RequestStack $requestStack)

    {
        $this->translator = $translator;
    
        $request = $requestStack->getCurrentRequest();
        if ($request) {
            $locale = $request->getPreferredLanguage(['en', 'de']) ?? 'de';
            $request->setLocale($locale);
        }


        if (!extension_loaded('libvirt')) {
            throw new \Exception($this->translator->trans('libvirt_not_installed'));
        }
        
    }


    
    private function connect(): void
    {
        if (!$this->connection) {

            // Mit lokalem Hypervisor verbinden
            $this->connection = libvirt_connect('qemu:///system', false);
            if (!$this->connection) {
                throw new \Exception($this->translator->trans('libvirt_connection_failed') . libvirt_get_last_error());
            }
        }
    }



    #[Route('/domains', name: 'domains_list', methods: ['GET'])]
    public function listDomains(): JsonResponse
    {
        try {
            $this->connect();

            $domains = [];
            $activeDomains = libvirt_list_domains($this->connection);

            foreach ($activeDomains as $domainId) {
                // Da die Domain-IDs Strings sind, nutzen wir lookup_by_name statt lookup_by_id
                $domain = libvirt_domain_lookup_by_name($this->connection, $domainId);

                if ($domain) {
                    $info = libvirt_domain_get_info($domain);
                    $domains[] = [
                        'id' => $domainId,
                        'name' => $domainId, // Da Name = ID
                        'state' => $info['state'] ?? 'unknown',
                        'memory' => $info['memory'] ?? 0,
                        'max_memory' => $info['maxMem'] ?? 0,
                        'cpu_count' => $info['nrVirtCpu'] ?? 0
                    ];
                }
            }

            // Der Rest des Codes bleibt unverändert

            return $this->json(['domains' => $domains]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    
    #[Route('/domain/{name}/start', name: 'domain_start', methods: ['POST'])]
    public function startDomain(string $name): JsonResponse
    {
        try {
            $this->connect();
            $domain = libvirt_domain_lookup_by_name($this->connection, $name);

            if (!$domain) {
                return $this->json(['error' => $this->translator->trans('libvirt_domain_not_found')], 404);
            }

            $result = libvirt_domain_create($domain);

            return $this->json([
                'success' => $result !== false,
                'domain' => $name,
                'action' => 'start',
                'error' => $result === false ? libvirt_get_last_error() : null
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    
    
    #[Route('/domain/{name}/stop', name: 'domain_stop', methods: ['POST'])]
    public function stopDomain(string $name, Request $request): JsonResponse
    {
        try {
            $this->connect();
            $domain = libvirt_domain_lookup_by_name($this->connection, $name);

            if (!$domain) {
                return $this->json(['error' => $this->translator->trans('libvirt_domain_not_found')], 404);
            }

            $data = json_decode($request->getContent(), true);
            $force = isset($data['force']) && $data['force'] === true;

            $result = false;
            if ($force) {
                $result = libvirt_domain_destroy($domain);
            } else {
                $result = libvirt_domain_shutdown($domain);
            }

            return $this->json([
                'success' => $result !== false,
                'domain' => $name,
                'action' => $force ? $this->translator->trans('libvirt_domain_force_stop') :  $this->translator->trans('libvirt_domain_graceful_stop'),
                'error' => $result === false ? libvirt_get_last_error() : null
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
