<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCustomFields\Subscriber\PayloadSanitizerSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(PayloadSanitizerSubscriber::class)]
final class PayloadSanitizerSubscriberTest extends TestCase
{
    private PayloadSanitizerSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new PayloadSanitizerSubscriber();
    }

    public function testSubscribesToRequestEvent(): void
    {
        $events = PayloadSanitizerSubscriber::getSubscribedEvents();
        self::assertArrayHasKey('kernel.request', $events);
    }

    public function testCapsOverlongValues(): void
    {
        $event = $this->buildAddToCartEventWith([
            'product-1' => [
                'payload' => [
                    'rcCustomField1Value' => str_repeat('a', 1500),
                ],
            ],
        ]);

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame(1000, mb_strlen($lineItems['product-1']['payload']['rcCustomField1Value']));
    }

    public function testPreservesSpecialCharactersAndTags(): void
    {
        // Bewusst KEIN strip_tags: legitime Sonderzeichen/`<` müssen erhalten bleiben
        // (XSS wird am Output escaped). Ein Gravurtext darf nicht datenzerstoert werden.
        $event = $this->buildAddToCartEventWith([
            'product-1' => [
                'payload' => [
                    'rcCustomField1Value' => 'Länge < 10mm & > 5mm',
                    'rcCustomField2Value' => '<b>bold</b> "quotes"',
                ],
            ],
        ]);

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame('Länge < 10mm & > 5mm', $lineItems['product-1']['payload']['rcCustomField1Value']);
        self::assertSame('<b>bold</b> "quotes"', $lineItems['product-1']['payload']['rcCustomField2Value']);
    }

    public function testLeavesNonRcKeysUntouched(): void
    {
        $event = $this->buildAddToCartEventWith([
            'product-1' => [
                'payload' => [
                    'rcCustomField1Value' => 'kurz',
                    'otherField' => str_repeat('b', 1500),
                ],
            ],
        ]);

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame('kurz', $lineItems['product-1']['payload']['rcCustomField1Value']);
        self::assertSame(1500, mb_strlen($lineItems['product-1']['payload']['otherField']));
    }

    public function testSkipsNonStringValues(): void
    {
        $event = $this->buildAddToCartEventWith([
            'product-1' => [
                'payload' => [
                    'rcCustomField1Value' => 42,
                    'rcCustomField2Value' => ['array'],
                    'rcCustomField3Value' => null,
                ],
            ],
        ]);

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame(42, $lineItems['product-1']['payload']['rcCustomField1Value']);
        self::assertSame(['array'], $lineItems['product-1']['payload']['rcCustomField2Value']);
        self::assertNull($lineItems['product-1']['payload']['rcCustomField3Value']);
    }

    public function testSkipsRoutesOutsideCheckout(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'frontend.account.login');
        $request->request->set('lineItems', [
            'product-1' => ['payload' => ['rcCustomField1Value' => str_repeat('a', 1500)]],
        ]);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->subscriber->sanitizePayload($event);

        $lineItems = $request->request->all('lineItems');
        self::assertSame(1500, mb_strlen($lineItems['product-1']['payload']['rcCustomField1Value']));
    }

    /**
     * Die Store-API nimmt dieselben Positionen unter `items` entgegen. Wird nur
     * `lineItems` gelesen, umgeht jede entkoppelte Oberfläche die Kappung.
     */
    public function testCapsOverlongValuesFromStoreApi(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'store-api.checkout.cart.add-line-item');
        $request->request->set('items', [
            'product-1' => ['payload' => ['rcCustomField1Value' => str_repeat('a', 1500)]],
        ]);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->subscriber->sanitizePayload($event);

        $items = $request->request->all('items');
        self::assertSame(1000, mb_strlen($items['product-1']['payload']['rcCustomField1Value']));
    }

    public function testCapsBothParameterNamesInOneRequest(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'store-api.checkout.cart.add-line-item');
        $request->request->set('lineItems', [
            'a' => ['payload' => ['rcCustomField1Value' => str_repeat('a', 1500)]],
        ]);
        $request->request->set('items', [
            'b' => ['payload' => ['rcCustomField1Value' => str_repeat('b', 1500)]],
        ]);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->subscriber->sanitizePayload($event);

        self::assertSame(1000, mb_strlen($request->request->all('lineItems')['a']['payload']['rcCustomField1Value']));
        self::assertSame(1000, mb_strlen($request->request->all('items')['b']['payload']['rcCustomField1Value']));
    }

    public function testNoopOnEmptyLineItems(): void
    {
        $event = $this->buildAddToCartEventWith([]);

        $this->subscriber->sanitizePayload($event);

        // Kein Crash, keine Änderung.
        self::assertSame([], $event->getRequest()->request->all('lineItems'));
    }

    public function testNoopWhenNoSanitizationNeeded(): void
    {
        $event = $this->buildAddToCartEventWith([
            'product-1' => [
                'payload' => [
                    'rcCustomField1Value' => 'plain text',
                ],
            ],
        ]);

        $this->subscriber->sanitizePayload($event);

        $lineItems = $event->getRequest()->request->all('lineItems');
        self::assertSame('plain text', $lineItems['product-1']['payload']['rcCustomField1Value']);
    }

    /**
     * @param array<string, array{payload: array<string, mixed>}> $lineItems
     */
    private function buildAddToCartEventWith(array $lineItems): RequestEvent
    {
        $request = new Request();
        $request->attributes->set('_route', 'frontend.checkout.line-item.add');
        $request->request->set('lineItems', $lineItems);

        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
