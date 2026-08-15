<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Kappt Kundeneingaben beim Warenkorb-Submit serverseitig auf eine Maximallänge,
 * damit keine unbegrenzten Datenmengen in Payload/Bestellung gelangen (Client-`maxlength`
 * ist trivial umgehbar).
 *
 * Bewusst KEIN `strip_tags`: die Ausgabe ist an allen Stellen bereits escaped
 * (`|e('html')` bzw. Auto-Escape) — `strip_tags` würde dagegen legitime Sonderzeichen
 * zerstören (ein Gravurtext „Länge < 10mm" würde ab dem `<` abgeschnitten).
 *
 * Zwei Wege herein, zwei Parameternamen
 * -------------------------------------
 * Die Storefront postet auf `frontend.checkout.*` und schickt `lineItems`, die Store-API
 * auf `store-api.checkout.*` und schickt `items`. Wer nur den ersten Weg prüft, hat eine
 * Kappung, die jede entkoppelte Oberfläche umgeht — und über die Schnittstelle landen
 * dann unbegrenzte Zeichenketten in `order_line_item.payload`. Derselbe Fehler steckte
 * in RcColorPicker und war dort schwerer, weil der Wert in einem Stilattribut landet.
 */
final class PayloadSanitizerSubscriber implements EventSubscriberInterface
{
    /** Serverseitige Obergrenze, konsistent zur Client-`maxlength` im Storefront-Template. */
    private const MAX_VALUE_LENGTH = 1000;

    /** Beide Einstiege in den Warenkorb — Storefront und Store-API. */
    private const ROUTE_PREFIXES = ['frontend.checkout', 'store-api.checkout'];

    /** Storefront und Store-API benennen dieselbe Sache verschieden. */
    private const PARAMETER_NAMES = ['lineItems', 'items'];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'sanitizePayload'];
    }

    public function sanitizePayload(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$this->isCartRoute((string) $request->attributes->get('_route'))) {
            return;
        }

        $all = $request->request->all();

        foreach (self::PARAMETER_NAMES as $parameterName) {
            // Gelesen über all() ohne Schlüssel, nicht über all($key): Letzteres wirft bei
            // einem skalaren Wert eine BadRequestException und quittiert einen krummen
            // Request mit 400, statt ihn stillschweigend zu übergehen.
            $items = $all[$parameterName] ?? null;

            if (!is_array($items) || $items === []) {
                continue;
            }

            [$sanitized, $changed] = $this->capValues($items);

            if ($changed) {
                $request->request->set($parameterName, $sanitized);
            }
        }
    }

    private function isCartRoute(string $route): bool
    {
        foreach (self::ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $items
     *
     * @return array{0: array<mixed>, 1: bool}
     */
    private function capValues(array $items): array
    {
        $changed = false;

        foreach ($items as $id => $lineItem) {
            if (!is_array($lineItem) || !isset($lineItem['payload']) || !is_array($lineItem['payload'])) {
                continue;
            }

            foreach ($lineItem['payload'] as $key => $value) {
                if (!str_starts_with((string) $key, 'rcCustomField') || !is_string($value)) {
                    continue;
                }

                $capped = mb_substr($value, 0, self::MAX_VALUE_LENGTH);

                if ($capped !== $value) {
                    $items[$id]['payload'][$key] = $capped;
                    $changed = true;
                }
            }
        }

        return [$items, $changed];
    }
}
