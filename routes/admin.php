<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Routen des Adminbereichs (Prefix /admin)
|------------------------------------------------------------------------------
|
| Nur interne Rollen, 2FA verpflichtend, getrennt von Kundensitzungen. Jeder
| Supportzugriff auf einen Abrechnungslauf erzeugt einen Audit-Log-Eintrag.
| Wird in Phase 5 belegt.
|
*/
