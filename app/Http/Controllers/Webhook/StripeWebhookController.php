<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Application\Payment\HandleStripeEvent;
use App\Enums\PaymentProvider;
use App\Enums\WebhookProcessingStatus;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Payment\Exceptions\WebhookVerificationException;
use App\Services\Payment\StripeWebhookVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Providerbenachrichtigungen der Zahlung (Abschnitt 15.1, 19).
 *
 * VERBINDLICHE REGELN
 *
 *  1. Die Signaturpruefung ist zwingend und die erste Handlung. Eine
 *     Benachrichtigung ohne gueltige Signatur wird mit 400 abgewiesen und
 *     niemals verarbeitet. Der Versuch wird mit Signaturstatus vermerkt, damit
 *     ein Angriff im Adminbereich erkennbar ist.
 *  2. Geprueft wird die ROHE Nutzlast. Es wird ausdruecklich nicht $request->all()
 *     verwendet, weil jede Umformung die Signatur unbrauchbar machen wuerde.
 *  3. Die Route laeuft ohne Session und ohne CSRF-Token. Die Echtheit ergibt
 *     sich ausschliesslich aus der Signatur; die Ausnahme ist in
 *     bootstrap/app.php gesetzt.
 *  4. Es werden keine Roh-Payloads protokolliert. In das Log gelangen nur
 *     Ereignisart, Event-ID und ein technischer Fehlercode.
 *  5. Die Antwort ist knapp und ohne Fachangaben. Bei einem internen Fehler
 *     wird 500 gemeldet, damit der Anbieter erneut zustellt; die Verarbeitung
 *     ist idempotent.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeWebhookVerifier $verifier,
        private readonly HandleStripeEvent $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = $request->header(StripeWebhookVerifier::SIGNATURE_HEADER);

        try {
            $event = $this->verifier->verify($rawPayload, is_string($signature) ? $signature : null);
        } catch (WebhookVerificationException $exception) {
            $this->recordRejected($exception, $rawPayload);

            return response()->json(['status' => 'ungueltige_signatur'], 400);
        }

        try {
            $status = ($this->handler)($event);
        } catch (Throwable $exception) {
            Log::error('Eine Providerbenachrichtigung konnte nicht verarbeitet werden.', [
                'ereignis' => $event->eventType,
                'ereignis_id' => $event->eventId,
                'fehler' => $exception->getMessage(),
            ]);

            return response()->json(['status' => 'verarbeitung_fehlgeschlagen'], 500);
        }

        return response()->json([
            'status' => $status === WebhookProcessingStatus::VERARBEITET ? 'verarbeitet' : 'ignoriert',
        ]);
    }

    /**
     * Abgewiesener Versuch. Gespeichert werden ausschliesslich Signaturstatus,
     * Ereignisart und der Digest der Nutzlast, niemals die Nutzlast selbst.
     */
    private function recordRejected(WebhookVerificationException $exception, string $rawPayload): void
    {
        try {
            WebhookEvent::query()->create([
                'provider' => PaymentProvider::STRIPE,
                'provider_event_id' => 'abgewiesen-'.Str::ulid()->toString(),
                'event_type' => 'signatur.abgewiesen',
                'signature_status' => $exception->signatureStatus,
                'processing_status' => WebhookProcessingStatus::IGNORIERT,
                'payload_digest' => hash('sha256', $rawPayload),
                'payload' => null,
                'received_at' => now(),
                'processed_at' => now(),
                'attempts' => 1,
                'error_code' => 'SIGNATUR_'.$exception->signatureStatus->value,
            ]);
        } catch (QueryException) {
            // Der Nachweis des abgewiesenen Versuchs darf die Antwort nicht
            // scheitern lassen.
        }
    }
}
