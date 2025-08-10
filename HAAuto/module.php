<?php
declare(strict_types=1);

class HAAuto extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyInteger('MQTTInstanceID', 0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // KEIN MQTT_Subscribe/Unsubscribe hier – sonst Fatal Error bei älteren Systemen
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
                    'type'    => 'Label',
                    'caption' => 'Discovery-Prefix ist "homeassistant". '
                               . 'Falls der Button unten nicht funktioniert, bitte im MQTT-Client manuell "homeassistant/#" abonnieren.'
                ]
            ],
            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Subscribe (falls verfügbar)',
                    'onClick' => 'HAAuto_Subscribe($_IPS["TARGET"]);'
                ]
            ]
        ]);
    }

    public function Subscribe(): void
    {
        $mqttID = $this->ReadPropertyInteger('MQTTInstanceID');
        if ($mqttID <= 0 || !IPS_InstanceExists($mqttID)) {
            echo "Bitte zuerst einen MQTT-Client (I/O) auswählen und übernehmen.";
            return;
        }

        // Nur versuchen, wenn die Funktionen in deiner Symcon-Version vorhanden sind
        if (function_exists('MQTT_Unsubscribe') && function_exists('MQTT_Subscribe')) {
            @MQTT_Unsubscribe($mqttID, 'homeassistant/#');
            if (@MQTT_Subscribe($mqttID, 'homeassistant/#', 0)) {
                echo "OK: abonniert auf homeassistant/#";
            } else {
                echo "Subscribe fehlgeschlagen – ist der MQTT-Client verbunden?";
            }
        } else {
            echo "Deine Symcon-Version stellt MQTT_* Funktionen nicht bereit. "
               . "Bitte im MQTT-Client die Subscription 'homeassistant/#' manuell anlegen.";
        }
    }
}
