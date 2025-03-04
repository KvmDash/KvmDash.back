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
}
