<?php
declare(strict_types=1);

class HAAuto extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyInteger('MQTTInstanceID', 0);
        // Wohin sollen die Ordner? 0 = unter der Instanz
        $this->RegisterPropertyInteger('RootCategoryID', 0);
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
                [
                    'type'    => 'SelectInstance',
                    'name'    => 'MQTTInstanceID',
                    'caption' => 'MQTT-Client (I/O)'
                ],
                [
                    'type'    => 'SelectCategory',
                    'name'    => 'RootCategoryID',
                    'caption' => 'Zielordner für "HAAuto – Geräte" (leer = unter der Instanz)'
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Es werden Ordner "switch", "light", "sensor" automatisch angelegt.'
                ]
            ],
            'actions' => []
        ]);
    }

    /** -------- helpers ---------- */

    private function ensureStructure(): void
    {
        $rootCat = $this->ReadPropertyInteger('RootCategoryID');
        $parent  = ($rootCat > 0 && @IPS_ObjectExists($rootCat)) ? $rootCat : $this->InstanceID;

        $devicesCat = $this->getOrCreateCategory('HAAuto – Geräte', $parent, 'haa_devices');
        $this->getOrCreateCategory('switch', $devicesCat, 'haa_switch');
        $this->getOrCreateCategory('light',  $devicesCat, 'haa_light');
        $this->getOrCreateCategory('sensor', $devicesCat, 'haa_sensor');
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
}
