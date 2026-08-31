<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Routen der Anwendung (Prefix /app)
|------------------------------------------------------------------------------
|
| Wird in Phase 1 mit Dashboard, Objekten, Abrechnungslaeufen, Upload und
| Wizard belegt. Jede Route ist mandantenbezogen zu scopen und mit einer Policy
| abzusichern. Downloads laufen ausschliesslich ueber autorisierte
| Streaming-Routen oder kurzlebige signierte Links.
|
*/
