<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
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
        ),
        new Post(
            name: 'upload_iso',
            uriTemplate: '/qemu/iso/upload',
            controller: self::class . '::uploadIso'
        ),
        new Get(
            name: 'iso_status',
            uriTemplate: '/qemu/iso/status',
            controller: self::class . '::getIsoStatus'
        ),
        new Post(
            name: 'delete_iso',
            uriTemplate: '/qemu/iso/delete',
            controller: self::class . '::deleteIso'
        ),
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
    /** 
     * Die libvirt Verbindungsressource
     * @var resource|null 
     */

    private $connection = null;
    private TranslatorInterface $translator;


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
        if ($this->connection === null) {
            $result = libvirt_connect('qemu:///system', false, []);
            if (!is_resource($result)) {
                throw new \Exception($this->translator->trans('error.libvirt_connection_failed') . \libvirt_get_last_error());
            }
            $this->connection = $result;
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
            if (!is_resource($this->connection)) {
                throw new \Exception('Invalid libvirt connection');
            }

            // Libvirt Netzwerke abfragen
            $networks = [];
            $virtNetworks = libvirt_list_networks($this->connection);
            if ($virtNetworks) {
                foreach ($virtNetworks as $network) {
                    $netResource = libvirt_network_get($this->connection, $network);
                    if (is_resource($netResource)) {
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
            if (!is_resource($this->connection)) {
                throw new \Exception('Invalid libvirt connection');
            }
            $images = [];

            $pools = libvirt_list_storagepools($this->connection);
            if (empty($pools)) {
                throw new \Exception('Keine Storage Pools gefunden');
            }

            foreach ($pools as $poolName) {
                try {
                    $pool = libvirt_storagepool_lookup_by_name($this->connection, $poolName);
                    if (!is_resource($pool)) {
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
                            if (!is_resource($volume)) {
                                continue;
                            }

                            $xml = libvirt_storagevolume_get_xml_desc($volume, NULL);
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


    /**
     * Startet den Download einer ISO-Datei von einer URL
     * 
     * Diese Methode ermöglicht das Herunterladen von ISO-Dateien in den libvirt Storage Pool.
     * Der Download wird asynchron im Hintergrund ausgeführt und der Fortschritt kann über
     * den Status-Endpoint überwacht werden.
     *
     * Anforderungen:
     * - POST Request mit JSON Body
     * - URL muss auf .iso Datei zeigen
     * - Ziel Storage Pool muss existieren und beschreibbar sein
     * - ISO darf noch nicht existieren
     *
     * Request Body Format:
     * {
     *    "url": "https://example.com/path/to/file.iso"
     * }
     *
     * Erfolgreiche Antwort:
     * {
     *    "status": "success",
     *    "message": "Download started",
     *    "data": {
     *        "pid": 12345,
     *        "log_file": "/tmp/example_download.log"
     *    }
     * }
     *
     * Fehler Antwort:
     * {
     *    "status": "error",
     *    "message": "Fehlerbeschreibung"
     * }
     *
     * Technische Details:
     * - Nutzt curl für den Download im Hintergrund
     * - Erstellt Status-, Log- und PID-Dateien für Monitoring
     * - Speichert in den konfigurierten libvirt Storage Pool
     * - Automatisches Cleanup bei Fehlern
     *
     * @Route("/api/qemu/iso/upload", methods={"POST"})
     * @param Request $request Symfony HTTP Request Objekt
     * @return JsonResponse Status des Download-Starts
     * @throws \Exception Bei ungültiger URL oder Dateisystem-Problemen
     */
    public function uploadIso(Request $request): JsonResponse
    {
        try {

            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                throw new \Exception('Invalid JSON data');
            }

            if (!isset($data['url']) || !is_string($data['url'])) {
                throw new \Exception('URL is required');
            }

            $url = $data['url'];

            // URL Validierung
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \Exception('Invalid URL format');
            }

            // Prüfe auf .iso Endung
            if (!str_ends_with(strtolower($url), '.iso')) {
                throw new \Exception('URL must end with .iso');
            }

            // Hole Storage Pool Info
            $this->connect();
            if (!is_resource($this->connection)) {
                throw new \Exception('Invalid libvirt connection');
            }

            $pool = libvirt_storagepool_lookup_by_name($this->connection, 'default');
            if (!is_resource($pool)) {
                throw new \Exception('Default storage pool not found');
            }

            $poolXml = simplexml_load_string(libvirt_storagepool_get_xml_desc($pool, null));
            if ($poolXml === false) {
                throw new \Exception('Could not parse storage pool XML');
            }

            $targetDir = (string)$poolXml->target->path;

            $filename = basename($url);
            $fileBase = pathinfo($filename, PATHINFO_FILENAME);
            $targetPath = rtrim($targetDir, '/') . '/' . $filename;

            if (file_exists($targetPath)) {
                throw new \Exception('ISO file already exists');
            }

            // Log-Datei und PID-Datei Setup
            $logFile = sys_get_temp_dir() . '/' . $fileBase . '_download.log';
            $pidFile = sys_get_temp_dir() . '/' . $fileBase . '_download.pid';
            $statusFile = sys_get_temp_dir() . '/' . $fileBase . '_download_status.json';




            // Starte Download im Hintergrund mit verbessertem Logging
            $cmd = sprintf(
                '/usr/bin/curl -L %s -o %s --progress-bar >> %s 2>&1 & echo $! > %s',
                escapeshellarg($url),
                escapeshellarg($targetPath),
                escapeshellarg($logFile),
                escapeshellarg($pidFile)
            );

            exec($cmd);

            // Prüfe ob PID-File erstellt wurde
            if (!file_exists($pidFile)) {
                throw new \Exception('Download could not be started');
            }

            $pidContent = file_get_contents($pidFile);
            if ($pidContent === false) {
                throw new \Exception('Could not read PID file');
            }

            $pid = trim($pidContent);
            if (empty($pid) || !is_numeric($pid)) {
                throw new \Exception('Invalid PID generated');
            }

            // Setze initialen Download-Status
            $this->updateDownloadStatus($statusFile, 'downloading', 'Download started', [
                'pid' => (int)$pid,
                'url' => $url,
                'target_path' => $targetPath,
                'log_file' => $logFile,
                'start_time' => time()
            ]);

            return $this->json([
                'status' => 'success',
                'message' => 'Download started',
                'data' => [
                    'pid' => (int)$pid,
                    'log_file' => $logFile
                ]
            ]);
        } catch (\Exception $e) {
            // Cleanup bei Fehler
            if (isset($logFile) && file_exists($logFile)) {
                unlink($logFile);
            }
            if (isset($pidFile) && file_exists($pidFile)) {
                unlink($pidFile);
            }

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Prüft den Status eines ISO-Downloads
     * 
     * Diese Methode überwacht alle aktiven ISO-Downloads und deren Status.
     * Sie sucht nach Status-Dateien im temporären Verzeichnis und prüft:
     * - Ob Downloads noch aktiv sind (PID existiert)
     * - Ob Downloads erfolgreich abgeschlossen wurden
     * - Ob Fehler aufgetreten sind
     *
     * Erfolgsantwort wenn Downloads aktiv:
     * {
     *    "status": "success",
     *    "data": [
     *      {
     *        "status": "downloading",
     *        "message": "Download started",
     *        "timestamp": 1234567890,
     *        "data": {
     *          "pid": 12345,
     *          "url": "https://example.com/file.iso",
     *          "target_path": "/var/lib/libvirt/images/file.iso",
     *          "log_file": "/tmp/file_download.log",
     *          "start_time": 1234567890
     *        }
     *      }
     *    ]
     * }
     *
     * Erfolgsantwort wenn keine Downloads:
     * {
     *    "status": "success",
     *    "data": []
     * }
     *
     * Technische Details:
     * - Prüft PID-Existenz via /proc/{pid}
     * - Bereinigt Status-Dateien automatisch
     * - Löscht Log- und PID-Dateien nach Abschluss
     * - Aktualisiert Download-Status bei Beendigung
     *
     * @Route("/api/qemu/iso/status", methods={"GET"})
     * @return JsonResponse Status aller aktiven Downloads
     */
    public function getIsoStatus(): JsonResponse
    {
        $tempDir = sys_get_temp_dir();
        $downloads = [];

        // Suche alle Status-Dateien
        $files = glob($tempDir . '/*_download_status.json');
        if ($files === false) {
            return $this->json([
                'status' => 'error',
                'message' => 'Could not search for status files'
            ], 500);
        }

        foreach ($files as $statusFile) {
            // Status-Datei einlesen
            $content = file_get_contents($statusFile);
            if ($content === false) {
                unlink($statusFile);
                continue;
            }

            // JSON dekodieren
            $status = json_decode($content, true);
            if (!is_array($status)) {
                unlink($statusFile);
                continue;
            }

            // Prüfe ob alle erforderlichen Schlüssel existieren
            if (!isset($status['status'], $status['data']) || !is_array($status['data'])) {
                unlink($statusFile);
                continue;
            }

            // Download-Status und PID prüfen
            if ($status['status'] === 'downloading' && isset($status['data']['pid'])) {
                // PID validieren und konvertieren
                $pidValue = $status['data']['pid'];
                if (!is_numeric($pidValue)) {
                    unlink($statusFile);
                    continue;
                }

                $pid = (int)$pidValue; // Jetzt ist die Konvertierung sicher
                $procPath = '/proc/' . $pid;

                if (!file_exists($procPath)) {
                    if (!isset($status['data']['target_path'])) {
                        unlink($statusFile);
                        continue;
                    }

                    $targetPath = $status['data']['target_path'];
                    if (file_exists($targetPath)) {
                        // Download erfolgreich
                        $this->updateDownloadStatus(
                            $statusFile,
                            'completed',
                            'Download finished',
                            [
                                'url' => $status['data']['url'],
                                'target_path' => $targetPath,
                                'filesize' => filesize($targetPath),
                                'end_time' => time()
                            ]
                        );

                        // Status zur Liste hinzufügen und aufräumen
                        $downloads[] = $status;
                        unlink($statusFile);

                        // Log und PID Dateien aufräumen
                        if (isset($status['data']['log_file']) && file_exists($status['data']['log_file'])) {
                            unlink($status['data']['log_file']);
                        }
                        $pidFile = str_replace('_download_status.json', '_download.pid', $statusFile);
                        if (file_exists($pidFile)) {
                            unlink($pidFile);
                        }

                        continue;
                    } else {
                        // Download fehlgeschlagen
                        $this->updateDownloadStatus($statusFile, 'failed', 'Download failed');
                        unlink($statusFile);
                        continue;
                    }
                }
                // Nur aktive Downloads zur Liste hinzufügen
                $downloads[] = $status;
            }
        }

        return $this->json([
            'status' => 'success',
            'data' => $downloads
        ]);
    }


    /**
     * Aktualisiert oder erstellt eine Status-Datei für einen ISO-Download
     * 
     * Diese private Hilfsmethode verwaltet den Status eines Downloads durch:
     * - Erstellen/Aktualisieren einer JSON-Status-Datei
     * - Speichern von Status-Informationen wie PID, URL, Pfade
     * - Tracking von Start- und Endzeit
     * 
     * Parameter:
     * @param string $statusFile Pfad zur Status-Datei (z.B. /tmp/debian-12_download_status.json)
     * @param string $status     Aktueller Status ('downloading', 'completed', 'failed')
     * @param string $message    Optionale Statusmeldung (default: '')
     * @param array  $data       Zusätzliche Daten wie PID, URLs, Pfade (default: [])
     * 
     * Status-Datei Format:
     * {
     *    "status": "downloading",
     *    "message": "Download started",
     *    "timestamp": 1234567890,
     *    "data": {
     *      "pid": 12345,
     *      "url": "https://example.com/file.iso",
     *      "target_path": "/var/lib/libvirt/images/file.iso",
     *      "log_file": "/tmp/file_download.log",
     *      "start_time": 1234567890
     *    }
     * }
     * 
     * @return void
     */
    private function updateDownloadStatus(string $statusFile, string $status, string $message = '', array $data = []): void
    {
        $statusData = [
            'status' => $status,
            'message' => $message,
            'timestamp' => time(),
            'data' => $data
        ];
        file_put_contents($statusFile, json_encode($statusData));
    }



    /**
     * Löscht eine ISO-Datei aus dem Storage Pool
     * 
     * Diese Methode löscht eine spezifische ISO-Datei:
     * - Prüft ob die Datei existiert
     * - Prüft ob die Datei im korrekten Pool liegt
     * - Löscht die Datei über libvirt API
     *
     * Request Body Format:
     * {
     *    "path": "/var/lib/libvirt/images/example.iso"
     * }
     *
     * Erfolgsantwort:
     * {
     *    "status": "success",
     *    "message": "ISO successfully deleted"
     * }
     *
     * @param Request $request Symfony HTTP Request Objekt
     * @return JsonResponse Status der Löschoperation
     */
    public function deleteIso(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['path'])) {
                throw new \Exception('Path is required');
            }

            $path = $data['path'];

            // Prüfe ob Datei existiert
            if (!file_exists($path)) {
                throw new \Exception('ISO file not found');
            }

            // Prüfe ob es sich um eine ISO-Datei handelt
            if (!str_ends_with(strtolower($path), '.iso')) {
                throw new \Exception('File is not an ISO image');
            }

            $this->connect();

            // Finde den Pool und Volume für die Datei
            $pools = libvirt_list_storagepools($this->connection);
            $volumeFound = false;

            foreach ($pools as $poolName) {
                $pool = libvirt_storagepool_lookup_by_name($this->connection, $poolName);
                if (!$pool) {
                    continue;
                }

                libvirt_storagepool_refresh($pool);
                $volumes = libvirt_storagepool_list_volumes($pool);

                if ($volumes) {
                    foreach ($volumes as $volumeName) {
                        $volume = libvirt_storagevolume_lookup_by_name($pool, $volumeName);
                        if (!$volume) {
                            continue;
                        }

                        $xml = libvirt_storagevolume_get_xml_desc($volume, 0);
                        $volumeXml = simplexml_load_string($xml);

                        if ($volumeXml && (string)$volumeXml->target->path === $path) {
                            // Volume gefunden, jetzt löschen
                            if (libvirt_storagevolume_delete($volume) === false) {
                                throw new \Exception('Failed to delete ISO file');
                            }
                            $volumeFound = true;
                            break 2;
                        }
                    }
                }
            }

            if (!$volumeFound) {
                throw new \Exception('ISO file not found in any storage pool');
            }

            return $this->json([
                'status' => 'success',
                'message' => 'ISO successfully deleted'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
