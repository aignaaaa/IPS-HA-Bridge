<?php
declare(strict_types=1);

class HAAuto extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        // optional: wir speichern den MQTT-Client (I/O), auch wenn wir ihn hier nicht benötigen
        $this->RegisterPropertyInteger('MQTTInstanceID', 0);
        // Zielordner für die Geräte-Struktur (0 = unter der Instanz)
        $this->RegisterPropertyInteger('RootCategoryID', 0);
        // Home Assistant API
        $this->RegisterPropertyString('HA_URL', '');
        $this->RegisterPropertyString('HA_TOKEN', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->ensureStructure();
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'SelectInstance',    'name' => 'MQTTInstanceID', 'caption' => 'MQTT-Client (optional)'],
                ['type' => 'SelectCategory',    'name' => 'RootCategoryID', 'caption' => 'Zielordner (leer = unter Instanz)'],
                ['type' => 'ValidationTextBox', 'name' => 'HA_URL',         'caption' => 'Home Assistant URL (z. B. http://192.168.1.234:8123)'],
                ['type' => 'PasswordTextBox',   'name' => 'HA_TOKEN',       'caption' => 'Home Assistant Token (Long-Lived)'],
                ['type' => 'Label', 'caption' => 'Ordner "HAAuto – Geräte / switch | light | sensor" werden automatisch erstellt.']
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'HA-Verbindung testen', 'onClick' => 'HAAuto_TestHA($id);']
            ]
        ]);
    }

    /* ===================== Buttons / öffentliche Methoden ===================== */

    public function TestHA(): void
    {
        try {
            $res = $this->haRequest('GET', '/api/');
            if (is_string($res) && stripos($res, 'API running') !== false) {
                echo "OK: Home Assistant API erreichbar.";
                return;
            }
            // Einige HA-Installationen antworten mit JSON
            if (is_array($res)) {
                echo "Antwort: " . json_encode($res);
                return;
            }
            echo "Antwort: " . (string)$res;
        } catch (\Throwable $e) {
            echo "Fehler: " . $e->getMessage();
        }
    }

    /**
     * Legt eine schaltbare Variable (Switch) an, die HA-Service switch.turn_on/off nutzt.
     * Beispiel-Aufruf: HAAuto_CreateSwitch(<InstanzID>, 'switch.wohnzimmer_steckdose', 'Wohnzimmer Steckdose');
     */
    public function CreateSwitch(string $entityId, string $friendlyName = ''): int
    {
        $cats   = $this->ensureStructure();
        $parent = $cats['switch'];

        $ident = 'sw_' . preg_replace('/[^A-Za-z0-9_]/', '_', $entityId);
        $name  = $friendlyName !== '' ? $friendlyName : $entityId;

        $varID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($varID === false) {
            $varID = $this->RegisterVariableBoolean($ident, $name);
            IPS_SetParent($varID, $parent);
            $this->EnableAction($ident);
            IPS_SetInfo($varID, json_encode(['entity_id' => $entityId, 'domain' => 'switch']));
        } else {
            IPS_SetName($varID, $name);
            IPS_SetParent($varID, $parent);
        }
        return $varID;
    }

    /** Action-Handler für schaltbare Variablen */
    public function RequestAction($Ident, $Value)
    {
        if ($this->startsWith($Ident, 'sw_')) {
            $varID = $this->GetIDForIdent($Ident);
            $info  = @json_decode(IPS_GetObject($varID)['ObjectInfo'] ?? '[]', true);
            $entity = $info['entity_id'] ?? null;
            if (!$entity) {
                throw new Exception("Keine entity_id im Objekt gespeichert.");
            }
            $service = $Value ? 'turn_on' : 'turn_off';
            $ok = $this->haCallService('switch', $service, $entity, []);
            if ($ok) {
                SetValueBoolean($varID, (bool)$Value);
                return true;
            }
            throw new Exception("HA-Service fehlgeschlagen.");
        }

        throw new Exception("Unbekannter Ident: $Ident");
    }

    /* ===================== Struktur-Helfer ===================== */

    private function ensureStructure(): array
    {
        $rootCat = $this->ReadPropertyInteger('RootCategoryID');
        $parent  = ($rootCat > 0 && @IPS_ObjectExists($rootCat)) ? $rootCat : $this->InstanceID;

        $devicesCat = $this->getOrCreateCategory('HAAuto – Geräte', $parent, 'haa_devices');
        $switchCat  = $this->getOrCreateCategory('switch',           $devicesCat, 'haa_switch');
        $lightCat   = $this->getOrCreateCategory('light',            $devicesCat, 'haa_light');
        $sensorCat  = $this->getOrCreateCategory('sensor',           $devicesCat, 'haa_sensor');

        return ['devices' => $devicesCat, 'switch' => $switchCat, 'light' => $lightCat, 'sensor' => $sensorCat];
    }

    private function getOrCreateCategory(string $name, int $parentID, string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        if ($id && IPS_ObjectExists($id)) {
            IPS_SetName($id, $name);
            IPS_SetParent($id, $parentID);
            return $id;
        }
        $id = IPS_CreateCategory();
        IPS_SetName($id, $name);
        IPS_SetIdent($id, $ident);
        IPS_SetParent($id, $parentID);
        return $id;
    }

    private function startsWith(string $haystack, string $needle): bool
    {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }

    /* ===================== Home Assistant HTTP ===================== */

    /**
     * Robust: Erst Sys_GetURLContentEx(); wenn das fehlschlägt, Fallback per Raw-Socket (HTTP).
     * Für HTTPS kann (nur im LAN) die Zertifikatsprüfung deaktiviert werden.
     */
    private function haRequest(string $method, string $path, ?array $json = null)
    {
        $base = rtrim($this->ReadPropertyString('HA_URL'), '/');
        $tok  = trim($this->ReadPropertyString('HA_TOKEN'));
        if ($base === '' || $tok === '') {
            throw new Exception('Bitte HA_URL und HA_TOKEN in der Instanz eintragen.');
        }

        $opts = [
            'Timeout'        => 10000,
            'FollowLocation' => true,
            'Headers'        => [
                'Authorization: Bearer ' . $tok,
                'Accept: application/json',
                'Content-Type: application/json'
            ],
            'Method'         => strtoupper($method)
        ];
        if (!is_null($json)) {
            $opts['Content'] = json_encode($json);
        }

        // Bei https ggf. (nur intern!) Zertifikatsprüfung aus:
        if (stripos($base, 'https://') === 0) {
            $opts['VerifyPeer'] = false;
            $opts['VerifyHost'] = false;
        }

        $url = $base . $path;
        $res = @Sys_GetURLContentEx($url, $opts);
        if ($res !== false && $res !== null) {
            $trim = trim($res);
            if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) {
                return $res;
            }
            return json_decode($res, true);
        }

        // --------- Fallback (Raw HTTP via Socket) nur für http:// ----------
        $pu = parse_url($url);
        $scheme = $pu['scheme'] ?? 'http';
        if ($scheme !== 'http') {
            throw new Exception('HTTP fehlgeschlagen (und HTTPS-Fallback nicht aktiviert): ' . $url);
        }
        $host = $pu['host'] ?? '127.0.0.1';
        $port = $pu['port'] ?? 80;
        $pathOnly = ($pu['path'] ?? '/') . (isset($pu['query']) ? ('?' . $pu['query']) : '');

        $fp = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$fp) {
            throw new Exception("HTTP Fallback Socket-Fehler: $errno $errstr");
        }

        $req  = $opts['Method'] . ' ' . $pathOnly . " HTTP/1.1\r\n";
        $req .= 'Host: ' . $host . "\r\n";
        $req .= 'Authorization: Bearer ' . $tok . "\r\n";
        $req .= "Accept: application/json\r\n";
        if (!is_null($json)) {
            $body = json_encode($json);
            $req .= "Content-Type: application/json\r\n";
            $req .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        $req .= "Connection: close\r\n\r\n";
        if (!is_null($json)) {
            $req .= $body;
        }

        fwrite($fp, $req);
        $response = '';
        while (!feof($fp)) {
            $response .= fread($fp, 8192);
        }
        fclose($fp);

        // Header und Body trennen
        $parts = explode("\r\n\r\n", $response, 2);
        $body  = $parts[1] ?? '';
        $trim  = trim($body);
        if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) {
            return $body;
        }
        return json_decode($body, true);
    }

    private function haCallService(string $domain, string $service, string $entityId, array $data): bool
    {
        $payload = array_merge(['entity_id' => $entityId], $data);
        $this->haRequest('POST', "/api/services/{$domain}/{$service}", $payload);
        return true;
    }
}
