<?php
declare(strict_types=1);

class HAAuto extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        // Beispiel-Property (optional)
        $this->RegisterPropertyString('Note', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // Keine Parent-Verbindung, noch minimal
    }
}
