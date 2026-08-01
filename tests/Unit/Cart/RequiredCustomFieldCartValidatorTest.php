<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit\Cart;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCustomFields\Cart\Error\RequiredCustomFieldError;
use Ruhrcoder\RcCustomFields\Cart\RequiredCustomFieldCartValidator;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class RequiredCustomFieldCartValidatorTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sc-id';

    private SystemConfigService&MockObject $systemConfigService;
    private RequiredCustomFieldCartValidator $validator;
    private SalesChannelContext&MockObject $context;

    protected function setUp(): void
    {
        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->validator = new RequiredCustomFieldCartValidator($this->systemConfigService);

        $this->context = $this->createMock(SalesChannelContext::class);
        $this->context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
    }

    public function testEmptyRequiredFieldAddsBlockingError(): void
    {
        $this->enforce(true);
        $cart = $this->cartWith($this->productLineItem(
            customFields: [
                'rc_custom_fields_enabled' => true,
                'rc_custom_field_1_active' => true,
                'rc_custom_field_1_required' => true,
                'rc_custom_field_1_label' => 'Gravur',
            ],
            input: ['rcCustomField1Value' => '   '], // nur Whitespace zaehlt als leer
        ));

        $errors = new ErrorCollection();
        $this->validator->validate($cart, $errors, $this->context);

        self::assertCount(1, $errors);
        $error = $errors->first();
        self::assertInstanceOf(RequiredCustomFieldError::class, $error);
        self::assertTrue($error->blockOrder());
        self::assertSame('Gravur', $error->getParameters()['%name%']);
    }

    public function testFilledRequiredFieldProducesNoError(): void
    {
        $this->enforce(true);
        $cart = $this->cartWith($this->productLineItem(
            customFields: [
                'rc_custom_fields_enabled' => true,
                'rc_custom_field_1_active' => true,
                'rc_custom_field_1_required' => true,
                'rc_custom_field_1_label' => 'Gravur',
            ],
            input: ['rcCustomField1Value' => 'Max Mustermann'],
        ));

        $errors = new ErrorCollection();
        $this->validator->validate($cart, $errors, $this->context);

        self::assertCount(0, $errors);
    }

    public function testNonRequiredEmptyFieldProducesNoError(): void
    {
        $this->enforce(true);
        $cart = $this->cartWith($this->productLineItem(
            customFields: [
                'rc_custom_fields_enabled' => true,
                'rc_custom_field_1_active' => true,
                'rc_custom_field_1_required' => false,
                'rc_custom_field_1_label' => 'Optionaler Hinweis',
            ],
            input: [],
        ));

        $errors = new ErrorCollection();
        $this->validator->validate($cart, $errors, $this->context);

        self::assertCount(0, $errors);
    }

    public function testInactiveRequiredFieldIsIgnored(): void
    {
        $this->enforce(true);
        $cart = $this->cartWith($this->productLineItem(
            customFields: [
                'rc_custom_fields_enabled' => true,
                'rc_custom_field_1_active' => false,
                'rc_custom_field_1_required' => true,
                'rc_custom_field_1_label' => 'Deaktiviert',
            ],
            input: [],
        ));

        $errors = new ErrorCollection();
        $this->validator->validate($cart, $errors, $this->context);

        self::assertCount(0, $errors);
    }

    public function testDisabledEnforcementFlagShortCircuits(): void
    {
        $this->enforce(false);
        // getBool wird geprueft, die Line-Item-Schleife darf gar nicht erst laufen.
        $cart = $this->cartWith($this->productLineItem(
            customFields: [
                'rc_custom_fields_enabled' => true,
                'rc_custom_field_1_active' => true,
                'rc_custom_field_1_required' => true,
                'rc_custom_field_1_label' => 'Gravur',
            ],
            input: [],
        ));

        $errors = new ErrorCollection();
        $this->validator->validate($cart, $errors, $this->context);

        self::assertCount(0, $errors);
    }

    public function testDisabledSetOnProductIsIgnored(): void
    {
        $this->enforce(true);
        $cart = $this->cartWith($this->productLineItem(
            customFields: [
                'rc_custom_fields_enabled' => false, // Set global aus -> keine Pruefung
                'rc_custom_field_1_active' => true,
                'rc_custom_field_1_required' => true,
                'rc_custom_field_1_label' => 'Gravur',
            ],
            input: [],
        ));

        $errors = new ErrorCollection();
        $this->validator->validate($cart, $errors, $this->context);

        self::assertCount(0, $errors);
    }

    public function testMultipleMissingFieldsYieldDistinctErrors(): void
    {
        $this->enforce(true);
        $cart = $this->cartWith($this->productLineItem(
            customFields: [
                'rc_custom_fields_enabled' => true,
                'rc_custom_field_1_active' => true,
                'rc_custom_field_1_required' => true,
                'rc_custom_field_1_label' => 'Gravur',
                'rc_custom_field_2_active' => true,
                'rc_custom_field_2_required' => true,
                'rc_custom_field_2_label' => 'Geschenkbotschaft',
            ],
            input: [],
        ));

        $errors = new ErrorCollection();
        $this->validator->validate($cart, $errors, $this->context);

        // Zwei distinkte Fehler (eindeutige getId je Feld) — kein Ueberschreiben in der Collection.
        self::assertCount(2, $errors);
    }

    public function testNonProductLineItemIsIgnored(): void
    {
        $this->enforce(true);
        $lineItem = new LineItem('promo-1', LineItem::PROMOTION_LINE_ITEM_TYPE, 'promo-1', 1);
        $lineItem->setPayload([
            'customFields' => [
                'rc_custom_fields_enabled' => true,
                'rc_custom_field_1_active' => true,
                'rc_custom_field_1_required' => true,
                'rc_custom_field_1_label' => 'Gravur',
            ],
        ]);

        $errors = new ErrorCollection();
        $this->validator->validate($this->cartWith($lineItem), $errors, $this->context);

        self::assertCount(0, $errors);
    }

    private function enforce(bool $enabled): void
    {
        $this->systemConfigService
            ->method('getBool')
            ->with('RcCustomFields.config.requireAllFieldsBeforeCart', self::SALES_CHANNEL_ID)
            ->willReturn($enabled);
    }

    /**
     * @param array<string, mixed> $customFields
     * @param array<string, mixed> $input
     */
    private function productLineItem(array $customFields, array $input): LineItem
    {
        $lineItem = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-1', 1);
        $lineItem->setLabel('Testprodukt');
        $lineItem->setPayload(array_merge(['customFields' => $customFields], $input));

        return $lineItem;
    }

    private function cartWith(LineItem $lineItem): Cart
    {
        $cart = new Cart('test');
        $cart->add($lineItem);

        return $cart;
    }
}
