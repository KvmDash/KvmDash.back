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
        new GetCollection(
            name: 'get_spice_connection',
            uriTemplate: '/virt/domain/{name}/spice',
            controller: self::class . '::getSpiceConnection',
            read: false
        ),
        new GetCollection(
            name: 'get_domain_snapshots',
            uriTemplate: '/virt/domain/{name}/snapshots',
            controller: self::class . '::listDomainSnapshots',
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
        new Post(
            name: 'create_snapshot',
            uriTemplate: '/virt/domain/{name}/snapshot/create',
            controller: self::class . '::createDomainSnapshot',
            read: false,
            output: VirtualMachineAction::class,
            input: null 
        ),

    ]
)]



/**
 * Controller for managing virtual machines via libvirt
 * 
 * This class provides REST API endpoints for:
 * - Listing and status querying of VMs
 * - Start/Stop/Reboot operations
 * - Deleting VMs including storage
 *
 * Technical details:
 * - Uses libvirt PHP extension for QEMU/KVM access
 * - Communicates with local hypervisor (qemu:///system)
 * - Supports UEFI/NVRAM and QEMU Guest Agent
 * - Multilingual error messages (DE/EN)
 *
 * @package App\Controller\Api
 */
class VirtualizationController extends AbstractController
{
    /** 
     * The libvirt connection resource
     * @var resource
     */
    private $connection;

    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)

    {
        $this->translator = $translator;

        // check if libvirt extension is loaded
        if (!extension_loaded('libvirt')) {
            throw new \Exception($this->translator->trans('error.libvirt_not_installed'));
        }
    }

    /**
     * Establishes a connection to the local QEMU/KVM hypervisor
     * 
     * This private method is used by all API endpoints to:
     * - Establish a connection to the hypervisor if none exists
     * - Reuse the connection if it already exists
     * - Use the correct URI for the local QEMU hypervisor
     *
     * Connection details:
     * - URI: 'qemu:///system' for system mode
     * - Authentication: Via system permissions
     * 
     * @throws \Exception if the connection cannot be established
     */
    private function connect(): void
    {
        if (!is_resource($this->connection)) {
            // Connect to local hypervisor
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
     * Lists all available virtual machines
     * 
     * This method returns a list of all defined VMs with basic information:
     * - ID and name of the VM
     * - Current status (running, stopped, etc.)
     * - Assigned and maximum RAM
     * - Number of virtual CPUs
     *
     * The format of the response is optimized for the sidebar:
     * {
     *   "domains": [
     *     {
     *       "id": "vm-1",
     *       "name": "vm-1", 
     *       "state": 1,         // 1=running, 5=stopped
     *       "memory": 4194304,  // Current RAM in KB
     *       "maxMemory": 8388608, // Maximum RAM in KB
     *       "cpuCount": 2
     *     }
     *   ]
     * }
     * 
     * @return JsonResponse List of all VMs with basic information
     * @throws \Exception In case of connection problems to the hypervisor
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
     * Starts a virtual machine
     *
     * This method starts a VM that is in a stopped state.
     * The start is performed without parameters and corresponds to a normal boot process.
     *
     * Important notes:
     * - The VM must be defined and not already running
     * - Sufficient system resources must be available
     * - QEMU Guest Agent starts only after full boot
     *
     * @param string $name Name of the virtual machine
     * @return JsonResponse Status of the start operation
     * @throws \Exception In case of connection problems or if the domain is not found
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
     * Stops a virtual machine
     * 
     * This method supports two stop modes:
     * 1. Graceful Shutdown (default)
     *    - Sends ACPI signal to shut down
     *    - Allows clean shutdown of services
     *    - VM can refuse shutdown
     * 
     * 2. Force Stop (if force=true)
     *    - Immediate stop of the VM
     *    - Comparable to pulling the power plug
     *    - Can lead to data loss
     * 
     * Request body format:
     * {
     *   "force": true|false  // Optional, default false
     * }
     * 
     * @param string $name Name of the virtual machine
     * @param Request $request HTTP request with force option
     * @return JsonResponse Status of the stop operation
     * @throws \Exception In case of connection problems or if the domain is not found
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

            // Validate JSON data
            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                return $this->json([
                    'error' => $this->translator->trans('error.invalid_json')
                ], 400);
            }

            $force = isset($data['force']) && $data['force'] === true;

            // Stop domain
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
     * Retrieves the current status of all virtual machines
     * 
     * This method returns detailed status information for all VMs:
     * - Current state (running, stopped, etc.)
     * - Assigned RAM (balloon)
     * - Number of virtual CPUs
     * - IP address (if available via QEMU Guest Agent)
     *
     * The format of the response is optimized for the frontend:
     * {
     *   "vm-name": {
     *     "state.state": "1",        // 1=running, 5=stopped
     *     "balloon.current": "4096",  // RAM in KB
     *     "vcpu.current": "2",       // Number of vCPUs
     *     "ip": "192.168.1.100"      // IP or empty
     *   }
     * }
     * 
     * @return JsonResponse Status of all VMs as an associative array
     * @throws \Exception In case of connection problems to the hypervisor
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

                // Simple IP extraction
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
     * Reboots the virtual machine
     * 
     * The reboot is initiated via the ACPI signal, which corresponds to a "clean" reboot.
     * This allows the operating system to properly shut down all services.
     * 
     * Important notes:
     * - Requires a functioning ACPI in the VM
     * - The operating system must be able to process ACPI signals
     * - If the reboot fails, the VM remains in its current state
     * 
     * @param string $name Name of the virtual machine
     * @return JsonResponse Status of the reboot operation
     * @throws \Exception In case of connection problems or if the domain is not found
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
     * Deletes a virtual machine along with all associated files
     * 
     * The flags for libvirt_domain_undefine_flags consist of:
     * 
     * NVRAM (8):
     * - Deletes UEFI/NVRAM files
     * - Important for Windows VMs and UEFI boot
     * 
     * MANAGED_SAVE (2):
     * - Deletes saved VM states
     * - Comparable to hibernate files
     * 
     * SNAPSHOTS_METADATA (1):
     * - Deletes snapshot information
     * - Prevents orphaned snapshot data
     * 
     * @param string $name Name of the virtual machine
     * @param Request $request HTTP request with deleteVhd option
     * @return JsonResponse Status of the delete operation
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

            // Validate JSON data
            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                return $this->json([
                    'error' => $this->translator->trans('error.invalid_json')
                ], 400);
            }

            $deleteVhd = isset($data['deleteVhd']) && $data['deleteVhd'] === true;


            if ($deleteVhd) {
                // Search all storage pools
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
                            error_log("Could not open pool: " . libvirt_get_last_error());
                        }
                    }
                }

                // XML for disk paths with correct parameter type
                $xml = libvirt_domain_get_xml_desc($domain, null);
                if ($xml) {
                    $pattern = '/<disk[^>]+device=[\'"]disk[\'"][^>]*>.*?<source\s+file=[\'"]([^\'""]+)[\'"].*?>/s';
                    preg_match_all($pattern, $xml, $matches);

                    // Direct assignment of found paths
                    $diskPaths = [];
                    if (!empty($matches[1])) {
                        $diskPaths = $matches[1];
                    }

                    foreach ($diskPaths as $path) {
                        try {
                            $volume = libvirt_storagevolume_lookup_by_path($this->connection, $path);
                            if (is_resource($volume)) {
                                if (!libvirt_storagevolume_delete($volume, 0)) {
                                    error_log("Error deleting volume: " . libvirt_get_last_error());
                                }
                            }
                        } catch (\Exception $e) {
                            error_log("Exception deleting volume: " . $e->getMessage());
                        }
                    }
                }
            }

            // First stop domain if still active
            $info = libvirt_domain_get_info($domain);
            if ($info['state'] === 1) {
                libvirt_domain_destroy($domain);
            }

            // Undefine domain with all flags (NVRAM + MANAGED_SAVE + SNAPSHOTS_METADATA)
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
     * Creates a new virtual machine
     * 
     * This method creates a new VM with the following steps:
     * 1. Create a QCOW2 image file as a virtual hard disk
     * 2. Use virt-install to create and register the VM
     * 3. Automatically start the VM
     *
     * Expected request format:
     * {
     *   "name": "vm-name",           // Unique name of the VM
     *   "memory": 2048,              // RAM in MB
     *   "vcpus": 2,                  // Number of virtual CPUs
     *   "disk_size": 20,             // Disk size in GB
     *   "iso_image": "/path/to.iso", // Path to boot image
     *   "network_bridge": "br0"      // Network bridge / NAT network
     * }
     * 
     * The created VM includes:
     * - QCOW2 hard disk
     * - CD-ROM with boot ISO
     * - Network interface
     * - SPICE console for remote access
     * 
     * @param Request $request HTTP request with VM configuration
     * @return JsonResponse Status of the creation operation
     * @throws \Exception In case of errors during creation
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

            // Validate required fields
            if (!isset($data['name']) || !is_string($data['name'])) {
                throw new \Exception($this->translator->trans('error.invalid_vm_name'));
            }

            if (!isset($data['disk_size']) || !is_numeric($data['disk_size'])) {
                throw new \Exception($this->translator->trans('error.invalid_disk_size'));
            }


            // Get default storage pool
            $pool = libvirt_storagepool_lookup_by_name($this->connection, 'default');
            if (!is_resource($pool)) {
                throw new \Exception($this->translator->trans('error.storage_pool_not_found'));
            }


            // Parse pool XML for base path
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

            // VHD path in pool
            $vhdPath = $poolPath . '/' . $data['name'] . '.qcow2';

            // Create QCOW2 image
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

            // Check if OS variant is specified, otherwise set default
            $osVariant = $data['os_variant'] ?? 'generic';

            // Choose the correct network configuration
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
                // On error, delete the created disk
                if (file_exists($vhdPath)) {
                    unlink($vhdPath);
                }
                throw new \Exception($this->translator->trans('error.create_vm_failed') . ': ' . implode("\n", $virtOutput));
            }

            // Wait briefly to allow the VM to be properly registered
            sleep(2);

            // Retrieve domain after creation
            $domain = libvirt_domain_lookup_by_name($this->connection, $data['name']);

            // Return status of the creation
            return $this->json(new VirtualMachineAction(
                success: is_resource($domain),
                domain: $data['name'],
                action: 'create',
                error: !is_resource($domain) ? libvirt_get_last_error() : null
            ));
        } catch (\Exception $e) {
            // Clean up on errors
            if (isset($vhdPath) && file_exists($vhdPath)) {
                unlink($vhdPath);
            }
            return $this->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retrieves detailed information of a VM
     * 
     * Provides all available information including:
     * - Basic configuration (CPU, RAM, disk)
     * - Current usage
     * - Network configuration
     * - SPICE/VNC access data
     * - Storage information
     * 
     * @param string $name Name of the virtual machine
     * @return JsonResponse Detailed VM information
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


            // Basic information
            $info = libvirt_domain_get_info($domain);
            $xml = libvirt_domain_get_xml_desc($domain, null);
            $xmlObj = simplexml_load_string($xml);

            if ($xmlObj === false) {
                throw new \Exception($this->translator->trans('error.invalid_domain_xml'));
            }

            // Retrieve detailed memory statistics
            $memoryStats = libvirt_domain_memory_stats($domain);

            // Memory statistics according to virDomainMemoryStatTags:
            // '7' (RSS): Resident Set Size - actually used physical memory
            // '8' (USABLE): Usable memory without swapping (equivalent to "available" in free)
            // '4' (UNUSED): Completely free memory (equivalent to "free" in free)
            // '10' (DISK_CACHES): Disk caches that can be quickly freed
            // https://libvirt.org/html/libvirt-libvirt-domain.html#virDomainMemoryStatStruct

            // Actually used memory (RSS)
            $actualMemoryUsage = isset($memoryStats['7']) ? (int)$memoryStats['7'] : $info['memory'];

            // Usable memory (USABLE) - equivalent to the "available" value in free
            $availableMemory = isset($memoryStats['8']) ? (int)$memoryStats['8'] : 0;

            // Fallbacks if values are not available
            if ($availableMemory <= 0) {
                // Alternative calculation if USABLE is not available
                $freeMemory = isset($memoryStats['4']) ? (int)$memoryStats['4'] : 0;
                $diskCaches = isset($memoryStats['10']) ? (int)$memoryStats['10'] : 0;
                $availableMemory = $freeMemory + $diskCaches;

                // If still 0, use the difference between maxMem and RSS
                if ($availableMemory <= 0) {
                    $availableMemory = $info['maxMem'] - $actualMemoryUsage;
                    if ($availableMemory < 0) $availableMemory = 0;
                }
            }

            // Extract storage information
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

            // Network information
            $networks = [];
            foreach ($xmlObj->devices->interface as $interface) {
                $networks[] = [
                    'type' => (string)$interface['type'],
                    'mac' => (string)$interface->mac['address'],
                    'model' => (string)$interface->model['type'],
                    'bridge' => (string)$interface->source['bridge']
                ];
            }

            // SPICE/VNC information
            $graphics = [];
            foreach ($xmlObj->devices->graphics as $graphic) {
                $graphics[] = [
                    'type' => (string)$graphic['type'],
                    'port' => (string)$graphic['port'],
                    'listen' => (string)$graphic['listen'],
                    'passwd' => (string)$graphic['passwd']
                ];
            }

            // Performance metrics with correct memory information
            $stats = [
                'cpu_time' => $info['cpuUsed'] ?? 0,
                'memory_usage' => $actualMemoryUsage,
                'available_memory' => $availableMemory,
                'max_memory' => $info['maxMem'] ?? 0,
                'memory_details' => $memoryStats // Detailed memory statistics for debugging
            ];

            return $this->json([
                'name' => $name,
                'state' => $info['state'] ?? 0,
                'maxMemory' => $info['maxMem'] ?? 0,
                'memory' => $actualMemoryUsage, // Actually used memory (RSS)
                'availableMemory' => $availableMemory, // Usable memory (value from key '4')
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
     * Creates or finds a WebSocket connection for SPICE
     * 
     * @param string $name Name of the virtual machine
     * @return JsonResponse WebSocket connection data
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

            // Parse XML for SPICE port with xmllint (more accurate than SimpleXML)
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

            // WebSocket Port = SPICE Port + 1000 (as in the shell script)
            $wsPort = $spicePort + 1000;

            // Check if WebSocket is already running
            $checkCmd = "ps aux | grep -v grep | grep 'websockify $wsPort'";
            exec($checkCmd, $output, $returnVar);

            if ($returnVar !== 0) {
                // WebSocket not yet active, start it
                $cmd = sprintf(
                    'nohup websockify %d 0.0.0.0:%d  > /dev/null 2>&1 & echo $!',
                    $wsPort,
                    $spicePort
                );
                exec($cmd, $output);

                // Wait briefly and check if the process is running
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



    /**
     * Lists all snapshots of a virtual machine
     * 
     * This method retrieves all snapshots for the specified VM and returns
     * detailed information about each snapshot:
     * - Name of the snapshot
     * - Creation timestamp
     * - VM state at snapshot time
     * - Description (if available)
     * - Parent snapshot (for hierarchical snapshots)
     *
     * The response format is structured as:
     * {
     *   "vm": "vm-name",
     *   "snapshots": [
     *     {
     *       "name": "snapshot1",
     *       "creationTime": "1234567890",
     *       "state": "running",
     *       "description": "Snapshot description",
     *       "parent": "parent-snapshot-name"
     *     }
     *   ]
     * }
     * 
     * @param string $name Name of the virtual machine
     * @return JsonResponse List of all snapshots with their details
     * @throws \Exception In case of connection problems or if the domain is not found
     */
    public function listDomainSnapshots(string $name): JsonResponse
    {
        try {
            $this->connect();

            $domain = libvirt_domain_lookup_by_name($this->connection, $name);
            if (!is_resource($domain)) {
                return $this->json([
                    'error' => $this->translator->trans('error.libvirt_domain_not_found')
                ], 404);
            }

            // Retrieve snapshots of the domain
            $snapshots = libvirt_list_domain_snapshots($domain);  // <-- Use this function instead
            if (empty($snapshots)) {
                return $this->json([
                    'vm' => $name,
                    'snapshots' => []
                ]);
            }

            $snapshotList = [];
            foreach ($snapshots as $snapshotName) {
                $snapshot = libvirt_domain_snapshot_lookup_by_name($domain, $snapshotName, 0);
                if (!is_resource($snapshot)) {
                    continue;
                }

                $xml = libvirt_domain_snapshot_get_xml($snapshot, 0);
                $xmlObj = simplexml_load_string($xml);
                if ($xmlObj === false) {
                    error_log("Failed to parse snapshot XML for: " . $snapshotName);
                    continue;
                }

                $snapshotList[] = [
                    'name' => $snapshotName,
                    'creationTime' => (string)$xmlObj->creationTime,
                    'state' => (string)$xmlObj->state,
                    'description' => (string)$xmlObj->description,
                    'parent' => isset($xmlObj->parent) ? (string)$xmlObj->parent->name : null
                ];
            }

            return $this->json([
                'vm' => $name,
                'snapshots' => $snapshotList
            ]);
        } catch (\Exception $e) {
            error_log("Snapshot list error: " . $e->getMessage());
            return $this->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Creates a new snapshot of a virtual machine
     *
     * Expected request format:
     * {
     *   "name": "snapshot-name",         // Name for the new snapshot
     *   "description": "My snapshot"     // Optional description
     * }
     * 
     * @param string $name Name of the virtual machine
     * @param Request $request HTTP request with snapshot configuration
     * @return JsonResponse Status of the snapshot creation
     */
    public function createDomainSnapshot(string $name, Request $request): JsonResponse
    {
        try {
            error_log("=== Starting snapshot creation for VM: $name ===");
            $this->connect();
    
            $domain = libvirt_domain_lookup_by_name($this->connection, $name);
            error_log("Domain lookup result: " . ($domain ? "success" : "failed"));
            
            if (!is_resource($domain)) {
                return $this->json([
                    'error' => $this->translator->trans('error.libvirt_domain_not_found')
                ], 404);
            }
    
            // Parse request data
            $content = $request->getContent();
            error_log("Request content: " . $content);
            
            $data = json_decode($content, true);
            error_log("Parsed data: " . print_r($data, true));
    
            if (!is_array($data) || empty($data['name'])) {
                return $this->json([
                    'error' => $this->translator->trans('error.invalid_snapshot_name')
                ], 400);
            }
    
    
            // Create snapshot
            $result = libvirt_domain_snapshot_create($domain, 0);
            error_log("Snapshot creation result: " . ($result ? "success" : "failed"));
            if (!$result) {
                error_log("Libvirt error: " . libvirt_get_last_error());
            }
    
            return $this->json(new VirtualMachineAction(
                success: $result !== false,
                domain: $name,
                action: 'create_snapshot',
                error: $result === false ? libvirt_get_last_error() : null
            ));
    
        } catch (\Exception $e) {
            error_log("CRITICAL Snapshot creation error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return $this->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
