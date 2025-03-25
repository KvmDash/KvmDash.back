<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use ApiPlatform\Metadata\ApiResource;
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
        new GetCollection(
            name: 'get_domain_details',
            uriTemplate: '/virt/domain/{name}/details',
            controller: self::class . '::getDomainDetails',
            read: false
        ),
        // In den ApiResource annotations
        new GetCollection(
            name: 'get_spice_connection',
            uriTemplate: '/virt/domain/{name}/spice',
            controller: self::class . '::getSpiceConnection',
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
        new Post(
            name: 'create_domain',
            uriTemplate: '/virt/domain/create',
            controller: self::class . '::createDomain',
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
    /** 
     * Die libvirt Verbindungsressource
     * @var resource|null 
     */
    private $connection;

    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator, RequestStack $requestStack)

    {
        $this->translator = $translator;

        // check if libvirt extension is loaded
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
        if (!is_resource($this->connection)) {
            // Mit lokalem Hypervisor verbinden
            $this->connection = libvirt_connect('qemu:///system', false, []);
            if (!is_resource($this->connection)) {
                throw new \Exception(
                    $this->translator->trans('error.libvirt_connection_failed') .
                        libvirt_get_last_error()
                );
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
            if (!is_resource($this->connection)) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed'));
            }

            $domains = [];
            $activeDomains = libvirt_list_domains($this->connection);

            foreach ($activeDomains as $domainId) {
                $domain = libvirt_domain_lookup_by_name($this->connection, $domainId);
                if (!is_resource($domain)) {
                    continue;
                }

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
            if (!is_resource($this->connection)) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed'));
            }

            $domain = libvirt_domain_lookup_by_name($this->connection, $name);
            if (!is_resource($domain)) {
                return $this->json([
                    'error' => $this->translator->trans('error.libvirt_domain_not_found')
                ], 404);
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
            if (!is_resource($this->connection)) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed'));
            }

            $domain = libvirt_domain_lookup_by_name($this->connection, $name);
            if (!is_resource($domain)) {
                return $this->json([
                    'error' => $this->translator->trans('error.libvirt_domain_not_found')
                ], 404);
            }

            // JSON-Daten validieren
            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                return $this->json([
                    'error' => $this->translator->trans('error.invalid_json')
                ], 400);
            }

            $force = isset($data['force']) && $data['force'] === true;

            // Domain stoppen
            $result = $force ?
                libvirt_domain_destroy($domain) :
                libvirt_domain_shutdown($domain);

            return $this->json(new VirtualMachineAction(
                success: $result !== false,
                domain: $name,
                action: $force ? 'force_stop' : 'graceful_stop',
                error: $result === false ? libvirt_get_last_error() : null
            ));
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 500);
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
            if (!is_resource($this->connection)) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed'));
            }

            $domains = [];
            $activeDomains = libvirt_list_domains($this->connection);

            foreach ($activeDomains as $domainId) {
                $domain = libvirt_domain_lookup_by_name($this->connection, $domainId);
                if (!is_resource($domain)) {
                    continue;
                }

                $info = libvirt_domain_get_info($domain);
                $xml = libvirt_domain_get_xml_desc($domain, null);

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
            if (!is_resource($this->connection)) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed'));
            }

            $domain = libvirt_domain_lookup_by_name($this->connection, $name);
            if (!is_resource($domain)) {
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
     * MANAGED_SAVE (2):
     * - Löscht gespeicherte VM-Zustände
     * - Vergleichbar mit Hibernate-Dateien
     * 
     * SNAPSHOTS_METADATA (1):
     * - Löscht Snapshot-Informationen
     * - Verhindert verwaiste Snapshot-Daten
     * 
     * @param string $name Name der virtuellen Maschine
     * @param Request $request HTTP-Request mit deleteVhd Option
     * @return JsonResponse Status der Löschoperation
     */
    public function deleteDomain(string $name, Request $request): JsonResponse
    {
        try {
            $this->connect();
            if (!is_resource($this->connection)) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed'));
            }

            $domain = libvirt_domain_lookup_by_name($this->connection, $name);
            if (!is_resource($domain)) {
                return $this->json([
                    'error' => $this->translator->trans('error.libvirt_domain_not_found')
                ], 404);
            }

            // JSON-Daten validieren
            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                return $this->json([
                    'error' => $this->translator->trans('error.invalid_json')
                ], 400);
            }

            $deleteVhd = isset($data['deleteVhd']) && $data['deleteVhd'] === true;


            if ($deleteVhd) {
                // Alle Storage Pools durchsuchen
                $pools = libvirt_list_storagepools($this->connection);
                if (empty($pools)) {
                    error_log($this->translator->trans('error.no_storage_pools'));
                } else {
                    foreach ($pools as $poolName) {
                        if (!is_string($poolName)) {
                            error_log($this->translator->trans('error.invalid_pool_name'));
                            continue;
                        }

                        $pool = libvirt_storagepool_lookup_by_name($this->connection, $poolName);
                        if (is_resource($pool)) {
                            libvirt_storagepool_refresh($pool);
                        } else {
                            error_log("Konnte Pool nicht öffnen: " . libvirt_get_last_error());
                        }
                    }
                }

                // XML für Disk-Pfade mit korrektem Parameter-Typ
                $xml = libvirt_domain_get_xml_desc($domain, null);
                if ($xml) {
                    $pattern = '/<disk[^>]+device=[\'"]disk[\'"][^>]*>.*?<source\s+file=[\'"]([^\'""]+)[\'"].*?>/s';
                    preg_match_all($pattern, $xml, $matches);

                    // Direkte Zuweisung der gefundenen Pfade
                    $diskPaths = [];
                    if (!empty($matches[1])) {
                        $diskPaths = $matches[1];
                    }

                    foreach ($diskPaths as $path) {
                        try {
                            $volume = libvirt_storagevolume_lookup_by_path($this->connection, $path);
                            if (is_resource($volume)) {
                                if (!libvirt_storagevolume_delete($volume, 0)) {
                                    error_log("Fehler beim Löschen des Volumes: " . libvirt_get_last_error());
                                }
                            }
                        } catch (\Exception $e) {
                            error_log("Exception beim Volume-Löschen: " . $e->getMessage());
                        }
                    }
                }
            }

            // Zuerst Domain stoppen falls noch aktiv
            $info = libvirt_domain_get_info($domain);
            if ($info['state'] === 1) {
                libvirt_domain_destroy($domain);
            }

            // Domain undefine mit allen Flags (NVRAM + MANAGED_SAVE + SNAPSHOTS_METADATA)
            $result = libvirt_domain_undefine_flags($domain, 11); // 8 + 2 + 1

            return $this->json(new VirtualMachineAction(
                success: $result !== false,
                domain: $name,
                action: $deleteVhd ? 'delete_with_storage' : 'delete',
                error: $result === false ? libvirt_get_last_error() : null
            ));
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Erstellt eine neue virtuelle Maschine
     * 
     * Diese Methode erstellt eine neue VM mit folgenden Schritten:
     * 1. Erstellen einer QCOW2 Image-Datei als virtuelle Festplatte
     * 2. Verwenden von virt-install zur Erstellung und Registrierung der VM
     * 3. Automatischer Start der VM
     *
     * Erwartetes Request-Format:
     * {
     *   "name": "vm-name",           // Eindeutiger Name der VM
     *   "memory": 2048,              // RAM in MB
     *   "vcpus": 2,                  // Anzahl virtueller CPUs
     *   "disk_size": 20,             // Festplattengröße in GB
     *   "iso_image": "/path/to.iso", // Pfad zum Boot-Image
     *   "network_bridge": "br0"      // Netzwerk-Bridge / NAT-Netzwerk
     * }
     * 
     * Die erstellte VM enthält:
     * - QCOW2 Festplatte
     * - CD-ROM mit Boot-ISO
     * - Netzwerk-Interface
     * - SPICE Konsole für Remote-Zugriff
     * 
     * @param Request $request HTTP-Request mit VM-Konfiguration
     * @return JsonResponse Status der Erstellungsoperation
     * @throws \Exception Bei Fehlern während der Erstellung
     */
    public function createDomain(Request $request): JsonResponse
    {
        try {
            $this->connect();
            if (!is_resource($this->connection)) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed'));
            }

            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                throw new \Exception($this->translator->trans('error.invalid_json'));
            }

            // Validiere erforderliche Felder
            if (!isset($data['name']) || !is_string($data['name'])) {
                throw new \Exception($this->translator->trans('error.invalid_vm_name'));
            }

            if (!isset($data['disk_size']) || !is_numeric($data['disk_size'])) {
                throw new \Exception($this->translator->trans('error.invalid_disk_size'));
            }


            // Default Storage Pool holen
            $pool = libvirt_storagepool_lookup_by_name($this->connection, 'default');
            if (!is_resource($pool)) {
                throw new \Exception($this->translator->trans('error.storage_pool_not_found'));
            }


            // Pool XML parsen für den Basis-Pfad
            $poolXml = libvirt_storagepool_get_xml_desc($pool, null);
            if (!$poolXml) {
                throw new \Exception($this->translator->trans('error.storage_pool_xml_failed'));
            }

            $poolInfo = simplexml_load_string($poolXml);
            if (!$poolInfo) {
                throw new \Exception($this->translator->trans('error.storage_pool_xml_invalid'));
            }

            $poolPath = (string)$poolInfo->target->path;
            if (empty($poolPath)) {
                throw new \Exception($this->translator->trans('error.storage_pool_path_missing'));
            }

            // VHD-Pfad im Pool
            $vhdPath = $poolPath . '/' . $data['name'] . '.qcow2';

            // QCOW2 Image erstellen
            $command = sprintf(
                'qemu-img create -f qcow2 %s %dG',
                escapeshellarg($vhdPath),
                (int)($data['disk_size'])
            );

            exec($command, $output, $returnVar);

            if ($returnVar !== 0) {
                $errorMessage = $this->translator->trans('error.create_disk_failed');
                if (!empty($output)) {
                    $errorMessage .= ': ' . implode(' ', $output);
                }
                throw new \Exception($errorMessage);
            }

            // Prüfe ob OS-Variant angegeben wurde, ansonsten setze Standard
            $osVariant = $data['os_variant'] ?? 'generic';

            // Wähle die richtige Netzwerkkonfiguration
            $networkOption = '';
            if ($data['network_bridge'] === 'default') {
                $networkOption = 'network=default';
            } else {
                $networkOption = 'bridge=' . $data['network_bridge'];
            }

            $virtInstallCmd = sprintf(
                'virt-install --connect qemu:///system --name %s --memory %d --vcpus %d --disk path=%s,format=qcow2 --cdrom %s --network %s --graphics spice --video model=vga --noautoconsole --os-variant %s',
                escapeshellarg($data['name']),
                (int)$data['memory'],
                (int)$data['vcpus'],
                escapeshellarg($vhdPath),
                escapeshellarg($data['iso_image']),
                escapeshellarg($networkOption),
                escapeshellarg($osVariant)
            );



            exec($virtInstallCmd, $virtOutput, $virtReturnVar);

            if ($virtReturnVar !== 0) {
                // Bei Fehler die erstellte Disk löschen
                if (file_exists($vhdPath)) {
                    unlink($vhdPath);
                }
                throw new \Exception($this->translator->trans('error.create_vm_failed') . ': ' . implode("\n", $virtOutput));
            }

            // Warte kurz, damit die VM ordnungsgemäß registriert wird
            sleep(2);

            // Domain nach der Erstellung abrufen
            $domain = libvirt_domain_lookup_by_name($this->connection, $data['name']);

            // Status der Erstellung zurückgeben
            return $this->json(new VirtualMachineAction(
                success: is_resource($domain),
                domain: $data['name'],
                action: 'create',
                error: !is_resource($domain) ? libvirt_get_last_error() : null
            ));
        } catch (\Exception $e) {
            // Aufräumen bei Fehlern
            if (isset($vhdPath) && file_exists($vhdPath)) {
                unlink($vhdPath);
            }
            return $this->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Holt detaillierte Informationen einer VM
     * 
     * Liefert alle verfügbaren Informationen inkl:
     * - Grundkonfiguration (CPU, RAM, Disk)
     * - Aktuelle Auslastung
     * - Netzwerkkonfiguration
     * - SPICE/VNC Zugriffsdaten
     * - Storage Informationen
     * 
     * @param string $name Name der virtuellen Maschine
     * @return JsonResponse Detaillierte VM Informationen
     */
    public function getDomainDetails(string $name): JsonResponse
    {
        try {
            $this->connect();
            if (!is_resource($this->connection)) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed'));
            }

            $domain = libvirt_domain_lookup_by_name($this->connection, $name);
            if (!is_resource($domain)) {
                return $this->json([
                    'error' => $this->translator->trans('error.libvirt_domain_not_found')
                ], 404);
            }


            // Basis-Informationen
            $info = libvirt_domain_get_info($domain);
            $xml = libvirt_domain_get_xml_desc($domain, null);
            $xmlObj = simplexml_load_string($xml);

            if ($xmlObj === false) {
                throw new \Exception($this->translator->trans('error.invalid_domain_xml'));
            }

            // Detaillierte Speicherstatistiken abrufen
            $memoryStats = libvirt_domain_memory_stats($domain);

            // Speicherstatistiken gemäß der virDomainMemoryStatTags:
            // '7' (RSS): Resident Set Size - tatsächlich belegter physischer Speicher
            // '8' (USABLE): Verfügbarer Speicher ohne zu swappen (entspricht "verfügbar" in free)
            // '4' (UNUSED): Vollständig freier Speicher (entspricht "frei" in free)
            // '10' (DISK_CACHES): Disk-Caches, die schnell freigegeben werden können
            // https://libvirt.org/html/libvirt-libvirt-domain.html#virDomainMemoryStatStruct

            // Tatsächlich benutzter Speicher (RSS)
            $actualMemoryUsage = isset($memoryStats['7']) ? (int)$memoryStats['7'] : $info['memory'];

            // Verfügbarer Speicher (USABLE) - entspricht dem "verfügbar"-Wert in free
            $availableMemory = isset($memoryStats['8']) ? (int)$memoryStats['8'] : 0;

            // Fallbacks, wenn die Werte nicht verfügbar sind
            if ($availableMemory <= 0) {
                // Alternative Berechnung, wenn USABLE nicht verfügbar ist
                $freeMemory = isset($memoryStats['4']) ? (int)$memoryStats['4'] : 0;
                $diskCaches = isset($memoryStats['10']) ? (int)$memoryStats['10'] : 0;
                $availableMemory = $freeMemory + $diskCaches;

                // Wenn immer noch 0, dann Differenz aus maxMem und RSS verwenden
                if ($availableMemory <= 0) {
                    $availableMemory = $info['maxMem'] - $actualMemoryUsage;
                    if ($availableMemory < 0) $availableMemory = 0;
                }
            }

            // Storage Informationen extrahieren
            $disks = [];
            foreach ($xmlObj->devices->disk as $disk) {
                if ((string)$disk['device'] === 'disk') {
                    $disks[] = [
                        'device' => (string)$disk->target['dev'],
                        'driver' => (string)$disk->driver['type'],
                        'path' => (string)$disk->source['file'],
                        'bus' => (string)$disk->target['bus']
                    ];
                }
            }

            // Netzwerk Informationen
            $networks = [];
            foreach ($xmlObj->devices->interface as $interface) {
                $networks[] = [
                    'type' => (string)$interface['type'],
                    'mac' => (string)$interface->mac['address'],
                    'model' => (string)$interface->model['type'],
                    'bridge' => (string)$interface->source['bridge']
                ];
            }

            // SPICE/VNC Informationen
            $graphics = [];
            foreach ($xmlObj->devices->graphics as $graphic) {
                $graphics[] = [
                    'type' => (string)$graphic['type'],
                    'port' => (string)$graphic['port'],
                    'listen' => (string)$graphic['listen'],
                    'passwd' => (string)$graphic['passwd']
                ];
            }

            // Performance Metriken mit korrekten Speicherinformationen
            $stats = [
                'cpu_time' => $info['cpuUsed'] ?? 0,
                'memory_usage' => $actualMemoryUsage,
                'available_memory' => $availableMemory,
                'max_memory' => $info['maxMem'] ?? 0,
                'memory_details' => $memoryStats // Detaillierte Speicherstatistiken für Debugging
            ];

            return $this->json([
                'name' => $name,
                'state' => $info['state'] ?? 0,
                'maxMemory' => $info['maxMem'] ?? 0,
                'memory' => $actualMemoryUsage, // Tatsächlich genutzter Speicher (RSS)
                'availableMemory' => $availableMemory, // Verfügbarer Speicher (wert aus key '4')
                'cpuCount' => $info['nrVirtCpu'] ?? 0,
                'cpuTime' => $info['cpuUsed'] ?? 0,
                'disks' => $disks,
                'networks' => $networks,
                'graphics' => $graphics,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }


    /**
     * Erstellt oder findet eine WebSocket-Verbindung für SPICE
     * 
     * @param string $name Name der virtuellen Maschine
     * @return JsonResponse WebSocket-Verbindungsdaten
     */
    public function getSpiceConnection(string $name): JsonResponse
    {
        try {
            $this->connect();
            if (!is_resource($this->connection)) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed'));
            }
    
            $domain = libvirt_domain_lookup_by_name($this->connection, $name);
            if (!is_resource($domain)) {
                return $this->json([
                    'error' => $this->translator->trans('error.libvirt_domain_not_found')
                ], 404);
            }

            // XML parsen für SPICE-Port mit xmllint (genauer als SimpleXML)
            $xml = libvirt_domain_get_xml_desc($domain, NULL);
            $tmpFile = tempnam(sys_get_temp_dir(), 'vm_');
            file_put_contents($tmpFile, $xml);

            $spicePort = (int)shell_exec("xmllint --xpath 'string(//graphics[@type=\"spice\"]/@port)' " . escapeshellarg($tmpFile));
            unlink($tmpFile);
            
            if ($spicePort <= 0) {
                return $this->json([
                    'error' => $this->translator->trans('error.no_spice_port')
                ], 404);
            }

            // WebSocket Port = SPICE Port + 1000 (wie im Shell-Script)
            $wsPort = $spicePort + 1000;

            // Prüfen ob WebSocket bereits läuft
            $checkCmd = "ps aux | grep -v grep | grep 'websockify $wsPort'";
            exec($checkCmd, $output, $returnVar);

            if ($returnVar !== 0) {
                // WebSocket noch nicht aktiv, starten
                $cmd = sprintf(
                    'nohup websockify %d 0.0.0.0:%d  > /dev/null 2>&1 & echo $!',
                    $wsPort,
                    $spicePort
                );
                exec($cmd, $output);

                // Kurz warten und prüfen ob der Prozess läuft
                sleep(1);
                exec($checkCmd, $output, $returnVar);
                if ($returnVar !== 0) {
                    throw new \Exception($this->translator->trans('error.websockify_failed'));
                }
            }

            $serverHost = $_SERVER['SERVER_NAME'] ?? $_SERVER['SERVER_ADDR'] ?? 'localhost';

            return $this->json([
                'spicePort' => $spicePort,
                'wsPort' => $wsPort,
                'host' =>  $serverHost
            ]);
        } catch (\Exception $e) {
            error_log("SPICE Connection Error: " . $e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
