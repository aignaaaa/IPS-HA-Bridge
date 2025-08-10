<?php
declare(strict_types=1);

class HABridge extends IPSModule
{
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
