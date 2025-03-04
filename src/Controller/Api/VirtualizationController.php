<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RequestStack;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;

use App\Dto\VirtualMachine;
use App\Dto\VirtualMachineAction;


#[ApiResource(
    operations: [
        new GetCollection(
            name: 'get_domains',
            uriTemplate: '/virt/domains',
            controller: self::class . '::listDomains',
            read: false,
            output: VirtualMachine::class,
        ),
        new GetCollection(
            name: 'get_domain_status',
            uriTemplate: '/virt/domains/status',
            controller: self::class . '::getDomainStatus',
            read: false
        ),
        new Post(
            name: 'start_domain',
            uriTemplate: '/virt/domain/{name}/start',
            controller: self::class . '::startDomain',
            read: false,
            output: VirtualMachineAction::class,
        ),
        new Post(
            name: 'stop_domain',
            uriTemplate: '/virt/domain/{name}/stop',
            controller: self::class . '::stopDomain',
            read: false,
            output: VirtualMachineAction::class,
        ),
        new Post(
            name: 'reboot_domain',
            uriTemplate: '/virt/domain/{name}/reboot',
            controller: self::class . '::rebootDomain',
            read: false,
            output: VirtualMachineAction::class,
        ),
        new Post(
            name: 'delete_domain',
            uriTemplate: '/virt/domain/{name}/delete',
            controller: self::class . '::deleteDomain',
            read: false,
            output: VirtualMachineAction::class,
        ),

    ]
)]




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
            throw new \Exception($this->translator->trans('error.libvirt_not_installed'));
        }
    }


    private function connect(): void
    {
        if (!$this->connection) {

            // Mit lokalem Hypervisor verbinden
            $this->connection = libvirt_connect('qemu:///system', false);
            if (!$this->connection) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed') . libvirt_get_last_error());
            }
        }
    }



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
                    $domains[] = new VirtualMachine(
                        id: $domainId,
                        name: $domainId,
                        state: $info['state'] ?? 0,
                        memory: $info['memory'] ?? 0,
                        maxMemory: $info['maxMem'] ?? 0,
                        cpuCount: $info['nrVirtCpu'] ?? 0
                    );
                }
            }


            return $this->json(['domains' => $domains]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function startDomain(string $name): JsonResponse
    {
        try {
            $this->connect();
            $domain = libvirt_domain_lookup_by_name($this->connection, $name);

            if (!$domain) {
                return $this->json(['error' => $this->translator->trans('error.libvirt_domain_not_found')], 404);
            }

            $result = libvirt_domain_create($domain);

            return $this->json(new VirtualMachineAction(
                success: $result !== false,
                domain: $name,
                action: 'start',
                error: $result === false ? libvirt_get_last_error() : null
            ));
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }


    public function stopDomain(string $name, Request $request): JsonResponse
    {
        try {
            $this->connect();
            $domain = libvirt_domain_lookup_by_name($this->connection, $name);

            if (!$domain) {
                return $this->json(['error' => $this->translator->trans('error.libvirt_domain_not_found')], 404);
            }

            $data = json_decode($request->getContent(), true);
            $force = isset($data['force']) && $data['force'] === true;

            $result = false;
            if ($force) {
                $result = libvirt_domain_destroy($domain);
            } else {
                $result = libvirt_domain_shutdown($domain);
            }

            return $this->json(new VirtualMachineAction(
                success: $result !== false,
                domain: $name,
                action: $force ? $this->translator->trans('libvirt_domain_force_stop') :  $this->translator->trans('libvirt_domain_graceful_stop'),
                error: $result === false ? libvirt_get_last_error() : null
            ));
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }



    public function getDomainStatus(): JsonResponse
    {
        try {
            $this->connect();
            $domains = [];
            $activeDomains = libvirt_list_domains($this->connection);

            foreach ($activeDomains as $domainId) {
                $domain = libvirt_domain_lookup_by_name($this->connection, $domainId);
                if ($domain) {
                    $info = libvirt_domain_get_info($domain);
                    $xml = libvirt_domain_get_xml_desc($domain, 0);

                    // Einfache IP-Adressextraktion
                    $ip = '';
                    if ($xml) {
                        preg_match('/<ip address=\'([^\']+)\'/', $xml, $matches);
                        $ip = $matches[1] ?? '';
                    }

                    $domains[$domainId] = [
                        'state.state' => (string)($info['state'] ?? 0),
                        'balloon.current' => (string)($info['memory'] ?? 0),
                        'vcpu.current' => (string)($info['nrVirtCpu'] ?? 0),
                        'ip' => $ip
                    ];
                }
            }

            return $this->json($domains);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }


    public function rebootDomain(string $name): JsonResponse
    {
        try {
            $this->connect();
            $domain = libvirt_domain_lookup_by_name($this->connection, $name);

            if (!$domain) {
                return $this->json([
                    'error' => $this->translator->trans('error.libvirt_domain_not_found')
                ], 404);
            }

            $result = libvirt_domain_reboot($domain);

            return $this->json(new VirtualMachineAction(
                success: $result !== false,
                domain: $name,
                action: 'reboot',
                error: $result === false ? libvirt_get_last_error() : null
            ));
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }


    public function deleteDomain(string $name, Request $request): JsonResponse
    {
        try {
            $this->connect();
            $domain = libvirt_domain_lookup_by_name($this->connection, $name);

            if (!$domain) {
                return $this->json([
                    'error' => $this->translator->trans('error.libvirt_domain_not_found')
                ], 404);
            }

            // Prüfe ob VHD-Dateien auch gelöscht werden sollen
            $data = json_decode($request->getContent(), true);
            $deleteVhd = isset($data['deleteVhd']) && $data['deleteVhd'] === true;

            // XML für Disk-Pfade und NVRAM-Check
            $xml = libvirt_domain_get_xml_desc($domain, 0);
            $diskPaths = [];

            if ($deleteVhd && $xml) {
                preg_match_all('/<source file=\'([^\']+)\'/', $xml, $matches);
                $diskPaths = $matches[1] ?? [];
            }

            // Zuerst Domain stoppen falls noch aktiv
            $info = libvirt_domain_get_info($domain);
            if ($info['state'] === 1) {
                libvirt_domain_destroy($domain);
            }

            // Domain undefine mit NVRAM flags
            // 2 = VIR_DOMAIN_UNDEFINE_MANAGED_SAVE (1) | VIR_DOMAIN_UNDEFINE_SNAPSHOTS_METADATA (1)
            // 8 = VIR_DOMAIN_UNDEFINE_NVRAM
            $result = libvirt_domain_undefine_flags($domain, 10); // 2 + 8

            // VHD-Dateien löschen wenn gewünscht
            if ($deleteVhd && $result !== false) {
                foreach ($diskPaths as $path) {
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }
            }

            return $this->json(new VirtualMachineAction(
                success: $result !== false,
                domain: $name,
                action: $deleteVhd ? 'delete_with_storage' : 'delete',
                error: $result === false ? libvirt_get_last_error() : null
            ));
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
