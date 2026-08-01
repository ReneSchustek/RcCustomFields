<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Subscriber;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcCustomFields\Service\PayloadConverter;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt RcCustomFields-Payload-Werte vor der Order-Persistierung
 * in `order_line_item.custom_fields` ein — damit sind die Werte später
 * in Order-Detail, E-Mail-Templates und Dokumenten verfügbar.
 *
 * Greift in `CartConvertedEvent::getConvertedCart()` ein, BEVOR die Order
 * in die DB geschrieben wird. Das ist der Shopware-Standard-Hook für
 * Cart→Order-Transformation und vermeidet einen zweiten DB-Write.
 */
class OrderLineItemWrittenSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PayloadConverter $payloadConverter,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CartConvertedEvent::class => 'onCartConverted',
        ];
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        $convertedCart = $event->getConvertedCart();
        if (!isset($convertedCart['lineItems']) || !\is_array($convertedCart['lineItems'])) {
            return;
        }

        $patched = 0;
        foreach ($convertedCart['lineItems'] as $index => $lineItem) {
            $payload = $lineItem['payload'] ?? null;
            if (!\is_array($payload)) {
                continue;
            }

            $entries = $this->payloadConverter->extractFromPayload($payload);
            if ($entries === []) {
                continue;
            }

            $existingCustomFields = $lineItem['customFields'] ?? [];
            if (!\is_array($existingCustomFields)) {
                $existingCustomFields = [];
            }

            $convertedCart['lineItems'][$index]['customFields'] = $this->payloadConverter->mergeIntoCustomFields(
                $existingCustomFields,
                $entries,
            );
            ++$patched;
        }

        if ($patched > 0) {
            $event->setConvertedCart($convertedCart);
            $this->logger->info('RcCustomFields: order_line_item.custom_fields für ' . $patched . ' Position(en) gesetzt.', [
                'context' => 'ruhrcoder_custom_fields.order_converter',
                'patched' => $patched,
            ]);
        }
    }
}
