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



/**
 * Controller für die Verwaltung virtueller Maschinen über libvirt
 * 
 * Diese Klasse stellt REST-API Endpunkte bereit für:
 * - Auflisten und Status-Abfrage von VMs
 * - Start/Stop/Reboot Operationen
 * - Löschen von VMs inkl. Storage
 *
 * Technische Details:
 * - Nutzt libvirt PHP Extension für QEMU/KVM Zugriff
 * - Kommuniziert mit lokalem Hypervisor (qemu:///system)
 * - Unterstützt UEFI/NVRAM und QEMU Guest Agent
 * - Mehrsprachige Fehlermeldungen (DE/EN)
 *
 * @package App\Controller\Api
 */
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

    /**
     * Stellt eine Verbindung zum lokalen QEMU/KVM Hypervisor her
     * 
     * Diese private Methode wird von allen API-Endpunkten verwendet, um:
     * - Eine Verbindung zum Hypervisor herzustellen falls noch keine existiert
     * - Die Verbindung wiederzuverwenden wenn sie bereits besteht
     * - Die korrekte URI für den lokalen QEMU-Hypervisor zu verwenden
     *
     * Verbindungsdetails:Im Virt
     * - URI: 'qemu:///system' für den System-Mode
     * - Authentifizierung: Über System-Berechtigungen
     * 
     * @throws \Exception wenn die Verbindung nicht hergestellt werden kann
     */
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

    /**
     * Listet alle verfügbaren virtuellen Maschinen auf
     * 
     * Diese Methode liefert eine Liste aller definierten VMs mit Basis-Informationen:
     * - ID und Name der VM
     * - Aktueller Status (running, stopped, etc.)
     * - Zugewiesener und maximaler RAM
     * - Anzahl virtueller CPUs
     *
     * Das Format der Rückgabe ist für die Sidebar optimiert:
     * {
     *   "domains": [
     *     {
     *       "id": "vm-1",
     *       "name": "vm-1", 
     *       "state": 1,         // 1=running, 5=stopped
     *       "memory": 4194304,  // Aktueller RAM in KB
     *       "maxMemory": 8388608, // Maximaler RAM in KB
     *       "cpuCount": 2
     *     }
     *   ]
     * }
     * 
     * @return JsonResponse Liste aller VMs mit Basis-Informationen
     * @throws \Exception Bei Verbindungsproblemen zum Hypervisor
     */
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

    /**
     * Startet eine virtuelle Maschine
     *
     * Diese Methode startet eine VM, die sich im gestoppten Zustand befindet.
     * Der Start erfolgt ohne Parameter und entspricht einem normalen Bootvorgang.
     *
     * Wichtige Hinweise:
     * - Die VM muss definiert und nicht bereits laufend sein
     * - Ausreichend Systemressourcen müssen verfügbar sein
     * - QEMU Guest Agent startet erst nach vollständigem Boot
     *
     * @param string $name Name der virtuellen Maschine
     * @return JsonResponse Status der Start-Operation
     * @throws \Exception Bei Verbindungsproblemen oder wenn die Domain nicht gefunden wird
     */

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

    /**
     * Stoppt eine virtuelle Maschine
     * 
     * Diese Methode unterstützt zwei Stop-Modi:
     * 1. Graceful Shutdown (Standard)
     *    - Sendet ACPI-Signal zum Herunterfahren
     *    - Erlaubt sauberes Beenden von Diensten
     *    - VM kann Shutdown verweigern
     * 
     * 2. Force Stop (wenn force=true)
     *    - Sofortiges Beenden der VM
     *    - Vergleichbar mit Stromkabel ziehen
     *    - Kann zu Datenverlust führen
     * 
     * Request-Body Format:
     * {
     *   "force": true|false  // Optional, default false
     * }
     * 
     * @param string $name Name der virtuellen Maschine
     * @param Request $request HTTP-Request mit force-Option
     * @return JsonResponse Status der Stop-Operation
     * @throws \Exception Bei Verbindungsproblemen oder wenn die Domain nicht gefunden wird
     */
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


    /**
     * Holt den aktuellen Status aller virtuellen Maschinen
     * 
     * Diese Methode liefert detaillierte Statusinformationen für alle VMs:
     * - Aktueller Zustand (running, stopped, etc.)
     * - Zugewiesener RAM (balloon)
     * - Anzahl virtueller CPUs
     * - IP-Adresse (falls verfügbar via QEMU Guest Agent)
     *
     * Das Format der Rückgabe ist für das Frontend optimiert:
     * {
     *   "vm-name": {
     *     "state.state": "1",        // 1=running, 5=stopped
     *     "balloon.current": "4096",  // RAM in KB
     *     "vcpu.current": "2",       // Anzahl vCPUs
     *     "ip": "192.168.1.100"      // IP oder leer
     *   }
     * }
     * 
     * @return JsonResponse Status aller VMs als assoziatives Array
     * @throws \Exception Bei Verbindungsproblemen zum Hypervisor
     */
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

    /**
     * Führt einen Neustart der virtuellen Maschine durch
     * 
     * Der Reboot wird über das ACPI-Signal eingeleitet, was einem "sauberen" Neustart entspricht.
     * Dies ermöglicht dem Betriebssystem, alle Dienste ordnungsgemäß herunterzufahren.
     * 
     * Wichtige Hinweise:
     * - Benötigt ein funktionierendes ACPI in der VM
     * - Das Betriebssystem muss ACPI-Signale verarbeiten können
     * - Bei fehlgeschlagenem Reboot bleibt die VM im aktuellen Zustand
     * 
     * @param string $name Name der virtuellen Maschine
     * @return JsonResponse Status der Reboot-Operation
     * @throws \Exception Bei Verbindungsproblemen oder wenn die Domain nicht gefunden wird
     */
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


    /**
     * Löscht eine virtuelle Maschine mit allen zugehörigen Dateien
     * 
     * Die Flags für libvirt_domain_undefine_flags setzen sich zusammen aus:
     * 
     * NVRAM (8):
     * - Löscht UEFI/NVRAM Dateien
     * - Wichtig für Windows VMs und UEFI-Boot
     * 
     * MANAGED_SAVE (1):
     * - Löscht gespeicherte VM-Zustände
     * - Vergleichbar mit Hibernate-Dateien
     * 
     * SNAPSHOTS_METADATA (1):
     * - Löscht Snapshot-Informationen
     * - Verhindert verwaiste Snapshot-Daten
     * 
     * Kombinierter Flag-Wert:
     * 8 (NVRAM) + 2 (MANAGED_SAVE + SNAPSHOTS_METADATA) = 10
     */
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
