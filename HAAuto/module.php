<?php
declare(strict_types=1);

class HAAuto extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        // IO + Zielordner
        $this->RegisterPropertyInteger('MQTTInstanceID', 0);
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
                ['type' => 'SelectInstance',   'name' => 'MQTTInstanceID', 'caption' => 'MQTT-Client (I/O)'],
                ['type' => 'SelectCategory',   'name' => 'RootCategoryID', 'caption' => 'Zielordner (leer = unter Instanz)'],
                ['type' => 'ValidationTextBox','name' => 'HA_URL',         'caption' => 'Home Assistant URL (z.B. http://homeassistant.local:8123)'],
                ['type' => 'PasswordTextBox',  'name' => 'HA_TOKEN',       'caption' => 'Home Assistant Token (Long-Lived)'],
                ['type' => 'Label', 'caption' => 'Ordner "HAAuto – Geräte/switch|light|sensor" werden automatisch erstellt.']
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'HA-Verbindung testen', 'onClick' => 'HAAuto_TestHA($id);']
            ]
        ]);
    }

    /* ---------- Buttons / API ---------- */

    public function TestHA(): void
    {
        try {
            $res = $this->haRequest('GET', '/api/');
            if (is_string($res) && stripos($res, 'API running') !== false) {
                echo "OK: Home Assistant API erreichbar.";
            } else {
                echo "Antwort: " . (is_string($res) ? $res : json_encode($res));
            }
        } catch (\Throwable $e) {
            echo "Fehler: " . $e->getMessage();
        }
    }

    /**
     * Legt eine schaltbare Variable (switch) an und bindet sie an HA-Service.
     * Beispiel: HAAuto_CreateSwitch(<InstanzID>, 'switch.wohnzimmer', 'Wohnzimmer');
     */
    public function CreateSwitch(string $entityId, string $friendlyName = ''): int
    {
        $cats = $this->ensureStructure();
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
        if (str_starts_with($Ident, 'sw_')) {
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
            throw new Exception("HA-Service fehlgeschlagen");
        }

        throw new Exception("Unbekannter Ident: $Ident");
    }

    /* ---------- Struktur ---------- */

    private function ensureStructure(): array
    {
        $rootCat = $this->ReadPropertyInteger('RootCategoryID');
        $parent  = ($rootCat > 0 && @IPS_ObjectExists($rootCat)) ? $rootCat : $this->InstanceID;

        $devicesCat = $this->getOrCreateCategory('HAAuto – Geräte', $parent, 'haa_devices');
        $switchCat  = $this->getOrCreateCategory('switch', $devicesCat, 'haa_switch');
        $lightCat   = $this->getOrCreateCategory('light',  $devicesCat, 'haa_light');
        $sensorCat  = $this->getOrCreateCategory('sensor', $devicesCat, 'haa_sensor');

        return ['devices'=>$devicesCat, 'switch'=>$switchCat, 'light'=>$lightCat, 'sensor'=>$sensorCat];
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

    /* ---------- HA HTTP ---------- */

    private function haRequest(string $method, string $path, ?array $json = null)
    {
        $base = rtrim($this->ReadPropertyString('HA_URL'), '/');
        $tok  = trim($this->ReadPropertyString('HA_TOKEN'));

        if ($base === '' || $tok === '') {
            throw new Exception('Bitte HA_URL und HA_TOKEN in der Instanz eintragen.');
        }

        $opts = [
            'Timeout' => 5000,
            'Headers' => [
                'Authorization: Bearer ' . $tok,
                'Content-Type: application/json'
            ],
            'Method'  => strtoupper($method)
        ];
        if (!is_null($json)) {
            $opts['Content'] = json_encode($json);
        }

        $url = $base . $path;
        $res = @Sys_GetURLContentEx($url, $opts);
        if ($res === false || $res === null) {
            throw new Exception('HTTP fehlgeschlagen: ' . $url);
        }
        $trim = trim($res);
        if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) {
            return $res;
        }
        return json_decode($res, true);
    }

    private function haCallService(string $domain, string $service, string $entityId, array $data): bool
    {
        $payload = array_merge(['entity_id' => $entityId], $data);
        $this->haRequest('POST', "/api/services/{$domain}/{$service}", $payload);
        return true;
    }
}
