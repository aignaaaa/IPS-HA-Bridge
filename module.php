<?php
declare(strict_types=1);

/**
 * Home Assistant ⇄ IP-Symcon — Auto-Discovery Bridge
 * Version 0.3 (01 Aug 2025)
 *
 * Unterstützte Domains: switch, light, sensor, binary_sensor,
 * cover, media_player, climate, lock, vacuum
 */

class HABridge extends IPSModule
{
    private const MQTT_DATA_GUID = '{2E0D65E1-E868-4B2B-9378-88D1F1E5BD6D}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('BaseDiscoveryTopic', 'homeassistant');
        $this->RegisterPropertyString(
            'DomainWhitelist',
            'switch,light,sensor,binary_sensor,cover,media_player,climate,lock,vacuum'
        );
        $this->RegisterPropertyBoolean('PublishRetain', true);
        $this->RegisterAttributeString('EntityMap', '[]');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->ConnectParent('{778E2FDE-2B5B-44A9-A8A5-5F732B3F5F39}');

        // Subscribe auf Discovery-Konfigs
        $domains = array_map('trim', explode(',', $this->ReadPropertyString('DomainWhitelist')));
        $base = rtrim($this->ReadPropertyString('BaseDiscoveryTopic'), '/');
        foreach ($domains as $domain) {
            $this->SubscribeTopic("$base/$domain/+/config");
        }

        // Bereits erkannte state_topics erneut abonnieren
        $entities = json_decode($this->ReadAttributeString('EntityMap'), true);
        foreach ($entities as $info) {
            if (!empty($info['state_topic'])) {
                $this->SubscribeTopic($info['state_topic']);
            }
        }
    }

    public function ReceiveData(string $JSONString): void
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['Topic'], $data['Payload'])) return;
        $topic = $data['Topic']; $payload = $data['Payload'];
        $base = rtrim($this->ReadPropertyString('BaseDiscoveryTopic'), '/');

        // Discovery-Config erkannt?
        if (strpos($topic, "$base/") === 0 && substr($topic, -7) === '/config') {
            list(, $domain, $objectId) = explode('/', $topic, 4);
            $this->HandleDiscovery($domain, $objectId, $payload);
            return;
        }

        // State-Update
        $entities = json_decode($this->ReadAttributeString('EntityMap'), true);
        foreach ($entities as $uid => $info) {
            if (isset($info['state_topic']) && $topic === $info['state_topic']) {
                $this->UpdateVariableFromState($uid, $info, $payload);
                break;
            }
        }
    }

    public function RequestAction(string $Ident, $Value): void
    {
        $entities = json_decode($this->ReadAttributeString('EntityMap'), true);
        if (!isset($entities[$Ident])) throw new Exception('Unknown entity');
        $info = $entities[$Ident];
        if (empty($info['command_topic'])) throw new Exception('Entity is read-only');

        // Payload je nach Typ
        $payload = match($info['domain']) {
            'switch','light','binary_sensor','cover','lock' =>
                $Value ? ($info['payload_on'] ?? 'ON') : ($info['payload_off'] ?? 'OFF'),
            default => strval($Value),
        };

        $this->SendDataToParent(json_encode([
            'DataID'  => self::MQTT_DATA_GUID,
            'Topic'   => $info['command_topic'],
            'Payload' => $payload,
            'QoS'     => 0,
            'Retain'  => $this->ReadPropertyBoolean('PublishRetain'),
        ]));

        // Optimistisches Update bei gleichen Topics
        if ($info['state_topic'] === $info['command_topic']) {
            SetValue($info['varID'], $Value);
        }
    }

    private function HandleDiscovery(string $domain, string $objectId, string $json): void
    {
        $config = json_decode($json, true);
        if (!is_array($config) || empty($config['unique_id'])) return;
        $uid = $config['unique_id'];

        $entities = json_decode($this->ReadAttributeString('EntityMap'), true);
        $info = $entities[$uid] ?? [];

        // Standardfelder
        $info += [
            'domain'        => $domain,
            'object_id'     => $objectId,
            'name'          => $config['name'] ?? $objectId,
            'state_topic'   => $config['state_topic'] ?? null,
            'command_topic' => $config['command_topic'] ?? null,
            'payload_on'    => $config['payload_on'] ?? 'ON',
            'payload_off'   => $config['payload_off'] ?? 'OFF',
            'unit'          => $config['unit_of_measurement'] ?? ''
        ];

        // Variable anlegen/aktualisieren
        $info['varID'] = $this->CreateOrUpdateVariable($uid, $info);
        if (!empty($info['state_topic'])) {
            $this->SubscribeTopic($info['state_topic']);
        }

        $entities[$uid] = $info;
        $this->WriteAttributeString('EntityMap', json_encode($entities));
    }

    private function CreateOrUpdateVariable(string $uid, array $info): int
    {
        // Typ + Profil je Domain
        switch ($info['domain']) {
            case 'switch':
            case 'light':
            case 'binary_sensor':
            case 'cover':
            case 'lock':
                $type    = VARIABLETYPE_BOOLEAN;
                $profile = '~Switch';
                break;

            case 'sensor':
                $type    = VARIABLETYPE_FLOAT;
                $profile = $info['unit'] ? '' : '~String';
                break;

            case 'media_player':
            case 'climate':
            case 'vacuum':
                $type    = VARIABLETYPE_STRING;
                $profile = '';
                break;

            default:
                $type    = VARIABLETYPE_STRING;
                $profile = '';
        }

        $parent = $this->GetCategoryForDomain($info['domain']);
        $varID = @IPS_GetObjectIDByIdent($uid, $parent)
            ?: $this->RegisterVariableString($uid, $info['name'], $profile, 0);
        IPS_SetName($varID, $info['name']);

        // Action aktivieren für steuerbare Devices
        if (in_array($info['domain'], ['switch','light','cover','lock','media_player','climate','vacuum'])) {
            IPS_SetVariableCustomAction($varID, $this->InstanceID);
        }
        return $varID;
    }

    private function UpdateVariableFromState(string $uid, array $info, string $payload): void
    {
        $varID = $info['varID'] ?? 0; if (!$varID) return;
        switch ($info['domain']) {
            case 'switch':
            case 'light':
            case 'binary_sensor':
            case 'cover':
            case 'lock':
                $value = strcasecmp($payload, $info['payload_on']) === 0;
                break;
            case 'sensor':
                $value = is_numeric($payload) ? floatval($payload) : $payload;
                break;
            default:
                $value = $payload; // JSON/String
        }
        SetValue($varID, $value);
    }

    private function SubscribeTopic(string $topic): void
    {
        $this->SendDataToParent(json_encode([
            'DataID'=>self::MQTT_DATA_GUID,
            'PacketType'=>8,
            'TopicFilter'=>$topic,
            'QoS'=>0
        ]));
        $this->SendDebug('SUB', $topic, 0);
    }

    private function GetCategoryForDomain(string $domain): int
    {
        $ident = 'CAT_'.strtoupper($domain);
        $catID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if (!$catID) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, $ident);
            IPS_SetName($catID, ucfirst($domain));
        }
        return $catID;
    }
}
?>