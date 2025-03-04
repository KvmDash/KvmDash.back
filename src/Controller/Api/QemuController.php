<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Symfony\Contracts\Translation\TranslatorInterface;


#[ApiResource(
    operations: [
        new GetCollection(
            name: 'get_networks',
            uriTemplate: '/qemu/networks',
            controller: self::class . '::listNetworks',
            read: false
        ),
        new GetCollection(
            name: 'get_osinfo',
            uriTemplate: '/qemu/osinfo',
            controller: self::class . '::getOsInfo',
            read: false
        ),
        new GetCollection(
            name: 'get_boot_images',
            uriTemplate: '/qemu/images',
            controller: self::class . '::listBootImages',
            read: false
        )
    ]
)]

/**
 * Controller für QEMU/KVM spezifische Funktionen
 * 
 * Diese Klasse stellt REST-API Endpunkte bereit für:
 * - Netzwerk-Konfigurationen (Bridges, NAT)
 * - OS-Template Informationen
 * - QEMU-spezifische Systemeinstellungen
 *
 * Technische Details:
 * - Nutzt virt-install für OS-Informationen
 * - Kommuniziert mit libvirt für Netzwerke
 * - Liest Systeminformationen für Bridges
 *
 * @package App\Controller\Api
 */
class QemuController extends AbstractController
{
    private $connection;
    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;

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
     * @throws \Exception wenn die Verbindung nicht hergestellt werden kann
     */
    private function connect(): void
    {
        if (!$this->connection) {
            $this->connection = libvirt_connect('qemu:///system', false);
            if (!$this->connection) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed') . \libvirt_get_last_error());
            }
        }
    }

    /**
     * Listet verfügbare QEMU/Libvirt Netzwerke auf
     * 
     * Gibt eine Liste aller konfigurierten Netzwerke zurück:
     * - Bridge und NAT-Netzwerke
     * - Status (aktiv/inaktiv)
     * - Anzeigename und technischer Name
     *
     * @return JsonResponse Liste der verfügbaren Netzwerke
     * @throws \Exception Bei Verbindungsproblemen zum Hypervisor
     */
    public function listNetworks(): JsonResponse
    {
        try {
            $this->connect();
            $networks = [];

            // Libvirt Netzwerke abfragen
            $virtNetworks = libvirt_list_networks($this->connection);
            if ($virtNetworks) {
                foreach ($virtNetworks as $network) {
                    $netResource = libvirt_network_get($this->connection, $network);
                    if ($netResource) {
                        $networks[] = [
                            'name' => $network === 'default' ? 'NAT (default)' : $network,
                            'type' => $network === 'default' ? 'nat' : 'bridge',
                            'value' => $network,
                            'active' => libvirt_network_get_active($netResource)
                        ];
                    }
                }
            }

            return $this->json([
                'status' => 'success',
                'data' => $networks
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => $this->translator->trans(
                    'error.network_list_failed',
                    ['%error%' => $e->getMessage()]
                )
            ], 500);
        }
    }


    /**
     * Liefert eine Liste verfügbarer Betriebssystem-Templates
     * 
     * Nutzt virt-install --osinfo list um alle unterstützten 
     * Betriebssysteme abzufragen. Dies wird benötigt für:
     * - Optimale VM-Konfiguration
     * - Treiber-Auswahl
     * - Ressourcen-Empfehlungen
     *
     * @return JsonResponse Liste aller verfügbaren OS-Templates
     */
    public function getOsInfo(): JsonResponse
    {
        try {
            $command = ['virt-install', '--osinfo', 'list'];
            $output = [];
            $returnVar = 0;

            exec(implode(' ', $command), $output, $returnVar);

            if ($returnVar !== 0) {
                throw new \Exception($this->translator->trans('error.osinfo_command_failed'));
            }

            // Filtern und Formatieren der Ausgabe
            $oslist = array_filter($output, function ($line) {
                $line = trim($line);
                // Ignoriere leere Zeilen und die Hinweiszeile
                return !empty($line) && !str_starts_with($line, 'You can see');
            });

            return $this->json([
                'status' => 'success',
                'data' => array_values($oslist) // Array neu indizieren
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => $this->translator->trans(
                    'error.osinfo_list_failed',
                    ['%error%' => $e->getMessage()]
                )
            ], 500);
        }
    }

    /**
     * Listet verfügbare Boot-Images auf
     * 
     * Durchsucht den konfigurierten Image-Ordner nach:
     * - ISO-Dateien (*.iso)
     * - Raw-Images (*.img)
     * - QCOW2-Images (*.qcow2)
     *
     * Format der Rückgabe:
     * {
     *   "status": "success",
     *   "data": [
     *     {
     *       "name": "ubuntu-22.04-desktop-amd64.iso",
     *       "type": "iso",
     *       "size": 3276800000,
     *       "path": "/var/lib/libvirt/images/ubuntu-22.04-desktop-amd64.iso",
     *       "modified": "2024-03-04T15:30:00+01:00"
     *     }
     *   ]
     * }
     *
     * @return JsonResponse Liste der verfügbaren Boot-Images
     */
    /**
     * Listet verfügbare Boot/Installation ISOs auf
     * 
     * Durchsucht alle Storage Pools nach:
     * - ISO-Dateien für Betriebssystem-Installation
     * - CD/DVD Image Dateien
     *
     * Format der Rückgabe:
     * {
     *   "status": "success",
     *   "data": [
     *     {
     *       "name": "ubuntu-22.04-desktop-amd64.iso",
     *       "size": 3276800000,
     *       "path": "/var/lib/libvirt/images/ubuntu-22.04-desktop-amd64.iso",
     *       "modified": "2024-03-04T15:30:00+01:00",
     *       "pool": "CDImages"
     *     }
     *   ]
     * }
     */
    public function listBootImages(): JsonResponse
    {
        try {
            $this->connect();
            $images = [];

            $pools = libvirt_list_storagepools($this->connection);
            if (!$pools) {
                throw new \Exception('Keine Storage Pools gefunden');
            }

            foreach ($pools as $poolName) {
                try {
                    $pool = libvirt_storagepool_lookup_by_name($this->connection, $poolName);
                    if (!$pool) {
                        continue;
                    }

                    libvirt_storagepool_refresh($pool);

                    $volumes = libvirt_storagepool_list_volumes($pool);
                    if ($volumes) {
                        foreach ($volumes as $volumeName) {
                            if (!str_ends_with(strtolower($volumeName), '.iso')) {
                                continue;
                            }

                            $volume = libvirt_storagevolume_lookup_by_name($pool, $volumeName);
                            if (!$volume) {
                                continue;
                            }

                            $xml = libvirt_storagevolume_get_xml_desc($volume, 0);
                            $volumeXml = simplexml_load_string($xml);

                            if ($volumeXml) {
                                $images[] = [
                                    'name' => $volumeName,
                                    'size' => (int)$volumeXml->capacity,
                                    'path' => (string)$volumeXml->target->path,
                                    'pool' => $poolName
                                ];
                            }
                        }
                    }
                } catch (\Exception $pe) {
                    continue;
                }
            }

            return $this->json([
                'status' => 'success',
                'data' => $images
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => $this->translator->trans('error.image_list_failed')
            ], 500);
        }
    }
}
