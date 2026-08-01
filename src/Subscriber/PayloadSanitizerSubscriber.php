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
 */
final class PayloadSanitizerSubscriber implements EventSubscriberInterface
{
    /** Serverseitige Obergrenze, konsistent zur Client-`maxlength` im Storefront-Template. */
    private const MAX_VALUE_LENGTH = 1000;

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'sanitizePayload'];
    }

    public function sanitizePayload(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Reale Add-to-Cart-Route in Shopware 6.7/6.8 (die Buy-Form postet genau hierauf).
        if ($request->attributes->get('_route') !== 'frontend.checkout.line-item.add') {
            return;
        }

        $lineItems = $request->request->all('lineItems');

        if (empty($lineItems)) {
            return;
        }

        $changed = false;

        foreach ($lineItems as $id => $lineItem) {
            if (!isset($lineItem['payload']) || !is_array($lineItem['payload'])) {
                continue;
            }

            foreach ($lineItem['payload'] as $key => $value) {
                if (!str_starts_with((string) $key, 'rcCustomField') || !is_string($value)) {
                    continue;
                }

                $capped = mb_substr($value, 0, self::MAX_VALUE_LENGTH);

                if ($capped !== $value) {
                    $lineItems[$id]['payload'][$key] = $capped;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $request->request->set('lineItems', $lineItems);
        }
    }
}
