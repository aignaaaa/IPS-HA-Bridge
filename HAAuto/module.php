<?php
declare(strict_types=1);

class HAAuto extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyInteger('MQTTInstanceID', 0); // optional (derzeit ungenutzt)
        $this->RegisterPropertyInteger('RootCategoryID', 0); // Zielordner (0 = unter der Instanz)
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
                ['type' => 'Button', 'caption' => 'HA-Verbindung testen', 'onClick' => 'HAAuto_TestHA($id);'],
                ['type' => 'Button', 'caption' => 'Aktionen reparieren',  'onClick' => 'HAAuto_FixActions($id);']
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
            if (is_array($res)) {
                echo "Antwort: " . json_encode($res);
                return;
            }
            echo "Antwort: " . (string)$res;
        } catch (\Throwable $e) {
            echo "Fehler: " . $e->getMessage();
        }
    }

    /** Repariert fehlende Actions für alle Switch-Variablen */
    public function FixActions(): int
    {
        $cats  = $this->ensureStructure();
        $parent = $cats['switch'];
        $fixed = 0;
        foreach (IPS_GetChildrenIDs($parent) as $cid) {
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] !== 2) continue; // 2 = Variable
            $var = IPS_GetVariable($cid);
            if (($var['VariableCustomAction'] ?? 0) !== $this->InstanceID) {
                @IPS_SetVariableCustomAction($cid, $this->InstanceID);
                $fixed++;
            }
        }
        echo "Repariert: $fixed";
        return $fixed;
    }

    /**
     * Entities auflisten – nutzt robustes haRequest (mit HTTP-Fallback).
     * @param string $filterDomain z.B. 'switch', 'light', 'sensor' oder '' für alle
     * @return array [['entity_id' => 'switch.xyz', 'friendly_name' => 'Name'], ...]
     */
    public function ListEntities(string $filterDomain = ''): array
    {
        try {
            $states = $this->haRequest('GET', '/api/states');
        } catch (\Throwable $e) {
            echo "Fehler: " . $e->getMessage();
            return [];
        }
        if (!is_array($states)) {
            echo "Unerwartete Antwort.";
            return [];
        }
        $out = [];
        foreach ($states as $row) {
            $eid = $row['entity_id'] ?? '';
            if ($eid === '') continue;
            if ($filterDomain !== '' && strpos($eid, $filterDomain . '.') !== 0) continue;
            $name = $row['attributes']['friendly_name'] ?? '';
            $out[] = ['entity_id' => $eid, 'friendly_name' => $name];
        }
        // Für bequeme Anzeige in der Konsole zusätzlich ausgeben
        foreach ($out as $e) {
            echo str_pad($e['entity_id'], 40) . " " . $e['friendly_name'] . PHP_EOL;
        }
        echo "Gefunden: " . count($out) . ($filterDomain ? " ($filterDomain)" : " Entities") . PHP_EOL;
        return $out;
    }

    /**
     * Legt eine schaltbare Variable (Switch) an (Service switch.turn_on/off).
     */
    public function CreateSwitch(string $entityId, string $friendlyName = ''): int
    {
        $cats   = $this->ensureStructure();
        $parent = $cats['switch'];

        $ident = $this->identFromEntity('sw', $entityId);
        $name  = $friendlyName !== '' ? $friendlyName : $entityId;

        $varID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($varID === false) {
            $varID = $this->RegisterVariableBoolean($ident, $name);
            if (@IPS_VariableProfileExists('~Switch')) {
                @IPS_SetVariableCustomProfile($varID, '~Switch');
            }
            IPS_SetParent($varID, $parent);
        } else {
            IPS_SetName($varID, $name);
            IPS_SetParent($varID, $parent);
        }

        IPS_SetInfo($varID, json_encode(['entity_id' => $entityId, 'domain' => 'switch']));

        // Action zwingend zuweisen
        $this->EnableAction($ident);
        $var = IPS_GetVariable($varID);
        if (($var['VariableCustomAction'] ?? 0) !== $this->InstanceID) {
            @IPS_SetVariableCustomAction($varID, $this->InstanceID);
        }

        return $varID;
    }

    /** Action-Handler */
    public function RequestAction($Ident, $Value)
    {
        if ($this->startsWith($Ident, 'sw_')) {
            $varID = $this->GetIDForIdent($Ident);
            $info  = @json_decode(IPS_GetObject($varID)['ObjectInfo'] ?? '[]', true);
            $entity = $info['entity_id'] ?? null;
            if (!$entity) throw new Exception("Keine entity_id im Objekt gespeichert.");
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

    private function identFromEntity(string $prefix, string $entityId): string
    {
        $san = preg_replace('/[^A-Za-z0-9_]/', '_', $entityId);
        return $prefix . '_' . $san;
    }

    private function startsWith(string $haystack, string $needle): bool
    {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }

    /* ===================== Home Assistant HTTP ===================== */

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
        if (!is_null($json)) $opts['Content'] = json_encode($json);

        if (stripos($base, 'https://') === 0) {
            $opts['VerifyPeer'] = false; // nur im LAN verwenden!
            $opts['VerifyHost'] = false;
        }

        $url = $base . $path;
        $res = @Sys_GetURLContentEx($url, $opts);
        if ($res !== false && $res !== null) {
            $trim = trim($res);
            if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) return $res;
            return json_decode($res, true);
        }

        // --------- Fallback (Raw HTTP via Socket) nur für http:// ----------
        $pu = parse_url($url);
        $scheme = $pu['scheme'] ?? 'http';
        if ($scheme !== 'http') throw new Exception('HTTP fehlgeschlagen (und HTTPS-Fallback nicht aktiviert): ' . $url);

        $host = $pu['host'] ?? '127.0.0.1';
        $port = $pu['port'] ?? 80;
        $pathOnly = ($pu['path'] ?? '/') . (isset($pu['query']) ? ('?' . $pu['query']) : '');

        $fp = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$fp) throw new Exception("HTTP Fallback Socket-Fehler: $errno $errstr");

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
        if (!is_null($json)) $req .= $body;

        fwrite($fp, $req);
        $response = '';
        while (!feof($fp)) { $response .= fread($fp, 8192); }
        fclose($fp);

        $parts = explode("\r\n\r\n", $response, 2);
        $body  = $parts[1] ?? '';
        $trim  = trim($body);
        if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) return $body;
        return json_decode($body, true);
    }

    private function haCallService(string $domain, string $service, string $entityId, array $data): bool
    {
        $payload = array_merge(['entity_id' => $entityId], $data);
        $this->haRequest('POST', "/api/services/{$domain}/{$service}", $payload);
        return true;
    }
}
