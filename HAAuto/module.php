<?php
declare(strict_types=1);

class HAAuto extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        // Hier speichern wir die gewählte MQTT-Instanz (I/O)
        $this->RegisterPropertyInteger('MQTTInstanceID', 0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Optional: automatisch auf homeassistant/# subscriben, wenn ein MQTT-Client gewählt ist
        $mqtt = $this->ReadPropertyInteger('MQTTInstanceID');
        if ($mqtt > 0 && @IPS_InstanceExists($mqtt)) {
            @MQTT_Unsubscribe($mqtt, 'homeassistant/#');
            @MQTT_Subscribe($mqtt, 'homeassistant/#', 0);
        }
    }

    // Einfache Konfigurationsseite: Auswahl des MQTT-Clients + Button
    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                [
                    'type'    => 'SelectInstance',
                    'name'    => 'MQTTInstanceID',
                    'caption' => 'MQTT-Client (I/O)',
                    // ohne Filter – du kannst deine MQTT-Instanz manuell auswählen
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Discovery-Prefix wird auf "homeassistant" erwartet.'
                ]
            ],
            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Subscribe auf homeassistant/#',
                    'onClick' => 'HAAuto_Subscribe($_IPS["TARGET"]);'
                ]
            ]
        ];
        return json_encode($form);
    }

    // Action aus dem Button: subscribt auf homeassistant/# am gewählten MQTT-Client
    public function Subscribe(): void
    {
        $mqtt = $this->ReadPropertyInteger('MQTTInstanceID');
        if ($mqtt <= 0 || !@IPS_InstanceExists($mqtt)) {
            echo "Bitte zuerst oben einen MQTT-Client auswählen und übernehmen.";
            return;
        }
        @MQTT_Unsubscribe($mqtt, 'homeassistant/#');
        if (@MQTT_Subscribe($mqtt, 'homeassistant/#', 0)) {
            echo "OK: abonniert auf homeassistant/#";
        } else {
            echo "Konnte nicht subscriben – prüfe den MQTT-Client (verbunden?)";
        }
    }
}
