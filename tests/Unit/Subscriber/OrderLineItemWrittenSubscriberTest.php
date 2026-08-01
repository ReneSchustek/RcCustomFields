<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcCustomFields\Service\PayloadConverter;
use Ruhrcoder\RcCustomFields\Subscriber\OrderLineItemWrittenSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Checkout\Cart\Order\OrderConversionContext;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class OrderLineItemWrittenSubscriberTest extends TestCase
{
    public function testGetSubscribedEventsReturnsCartConverted(): void
    {
        $events = OrderLineItemWrittenSubscriber::getSubscribedEvents();
        self::assertArrayHasKey(CartConvertedEvent::class, $events);
    }

    public function testOnCartConvertedSkipsWhenNoLineItems(): void
    {
        $subscriber = new OrderLineItemWrittenSubscriber(new PayloadConverter(), new NullLogger());
        $event = $this->createEvent(['orderNumber' => 'O-1']);
        $subscriber->onCartConverted($event);

        self::assertArrayNotHasKey('lineItems', $event->getConvertedCart());
    }

    public function testOnCartConvertedSkipsLineItemsWithoutCustomFieldsPayload(): void
    {
        $subscriber = new OrderLineItemWrittenSubscriber(new PayloadConverter(), new NullLogger());
        $event = $this->createEvent([
            'lineItems' => [
                ['id' => 'li-1', 'payload' => ['other' => 'data']],
            ],
        ]);
        $subscriber->onCartConverted($event);

        $converted = $event->getConvertedCart();
        self::assertArrayNotHasKey('customFields', $converted['lineItems'][0]);
    }

    public function testOnCartConvertedPatchesActiveLineItems(): void
    {
        $subscriber = new OrderLineItemWrittenSubscriber(new PayloadConverter(), new NullLogger());
        $event = $this->createEvent([
            'lineItems' => [
                [
                    'id' => 'li-1',
                    'payload' => [
                        'rcCustomFieldsActive' => '1',
                        'rcCustomField1Value' => 'Hans',
                        'rcCustomField1Label' => 'Name',
                    ],
                ],
            ],
        ]);
        $subscriber->onCartConverted($event);

        $converted = $event->getConvertedCart();
        self::assertArrayHasKey('customFields', $converted['lineItems'][0]);
        self::assertArrayHasKey('rc_custom_fields', $converted['lineItems'][0]['customFields']);
        self::assertCount(1, $converted['lineItems'][0]['customFields']['rc_custom_fields']);
    }

    public function testOnCartConvertedPreservesExistingCustomFields(): void
    {
        $subscriber = new OrderLineItemWrittenSubscriber(new PayloadConverter(), new NullLogger());
        $event = $this->createEvent([
            'lineItems' => [
                [
                    'id' => 'li-1',
                    'payload' => [
                        'rcCustomFieldsActive' => '1',
                        'rcCustomField1Value' => 'Hans',
                    ],
                    'customFields' => ['foreign_plugin_key' => 'keep'],
                ],
            ],
        ]);
        $subscriber->onCartConverted($event);

        $cf = $event->getConvertedCart()['lineItems'][0]['customFields'];
        self::assertSame('keep', $cf['foreign_plugin_key']);
        self::assertArrayHasKey('rc_custom_fields', $cf);
    }

    /**
     * @param array<string, mixed> $convertedCart
     */
    private function createEvent(array $convertedCart): CartConvertedEvent
    {
        $cart = new Cart('test-token');
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return new CartConvertedEvent($cart, $convertedCart, $salesChannelContext, new OrderConversionContext());
    }
}
