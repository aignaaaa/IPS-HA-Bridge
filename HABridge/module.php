<?php
declare(strict_types=1);

class HABridge extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        // Minimal: nur eine Dummy-Property
        $this->RegisterPropertyString('Note', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // Noch keine Parent-Verbindung, nur sichtbar werden
    }
}
