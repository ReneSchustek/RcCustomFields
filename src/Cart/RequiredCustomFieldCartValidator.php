<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Cart;

use Ruhrcoder\RcCustomFields\Cart\Error\RequiredCustomFieldError;
use Ruhrcoder\RcCustomFields\RcCustomFields;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Serverseitige Durchsetzung der Pflicht-Custom-Fields.
 *
 * Die Client-Validierung (JS `_validateRequiredFields`) ist trivial umgehbar (JS deaktiviert, direkter
 * POST auf `frontend.checkout.line-item.add`) — ohne Server-Check landen leere Pflichtfelder bis in die
 * Bestellung. Dieser Validator prüft je Produkt-Line-Item die aktiven Pflichtfelder gegen die Eingabe und
 * blockiert Warenkorb/Checkout mit einem Fehler pro leerem Pflichtfeld.
 *
 * Datenquelle ist ausschließlich das Line-Item-Payload — der Core legt die Produkt-Custom-Fields dort unter
 * `customFields` ab (ProductCartProcessor), die Kundeneingabe steht als `rcCustomField{i}Value` daneben. Damit
 * ist keine zusätzliche DB-Abfrage im Cart-Recalculate-Pfad nötig.
 *
 * Opt-in: nur aktiv, wenn der Merchant `requireAllFieldsBeforeCart` einschaltet (default false → kein
 * Verhaltenswechsel für bestehende Shops). Bewusst nur Pflicht-Präsenz, kein Typ/Min/Max — Format-/Locale-
 * Ambiguität würde False-Positive-Checkout-Blocks riskieren.
 */
final class RequiredCustomFieldCartValidator implements CartValidatorInterface
{
    private const CONFIG_ENFORCE = 'RcCustomFields.config.requireAllFieldsBeforeCart';
    private const CF_ENABLED = 'rc_custom_fields_enabled';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        if (!$this->systemConfigService->getBool(self::CONFIG_ENFORCE, $context->getSalesChannelId())) {
            return;
        }

        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $customFields = $lineItem->getPayloadValue('customFields');
            if (!is_array($customFields) || empty($customFields[self::CF_ENABLED])) {
                continue;
            }

            $payload = $lineItem->getPayload();

            for ($i = 1; $i <= RcCustomFields::FIELD_COUNT; ++$i) {
                if (empty($customFields['rc_custom_field_' . $i . '_active'])) {
                    continue;
                }
                if (empty($customFields['rc_custom_field_' . $i . '_required'])) {
                    continue;
                }

                $value = $payload['rcCustomField' . $i . 'Value'] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    continue;
                }

                $label = $customFields['rc_custom_field_' . $i . '_label'] ?? null;
                $label = is_string($label) && $label !== '' ? $label : ('#' . $i);

                $errors->add(new RequiredCustomFieldError(
                    $lineItem->getId(),
                    $i,
                    $label,
                    (string) ($lineItem->getLabel() ?? ''),
                ));
            }
        }
    }
}
