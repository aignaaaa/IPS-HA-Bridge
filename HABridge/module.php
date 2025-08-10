<?php
declare(strict_types=1);

class HAAuto extends IPSModule
{
    // Expliziter, korrekter Konstruktor (nimmt $InstanceID entgegen und reicht sie weiter)
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
    }

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Note', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }
}
