<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Blockierender Warenkorb-Fehler: Ein aktives Pflicht-Custom-Field wurde nicht ausgefüllt.
 *
 * Wird vom {@see \Ruhrcoder\RcCustomFields\Cart\RequiredCustomFieldCartValidator} erzeugt, wenn der
 * globale Schalter `requireAllFieldsBeforeCart` aktiv ist. Fängt genau die Fälle ab, die die
 * Client-Validierung (JS) umgeht (deaktiviertes JS, direkter POST auf die Add-to-Cart-Route).
 *
 * Storefront-Anzeige: Der Core rendert Level-Error-Fehler über den Snippet-Key
 * `error.<messageKey>` mit Parameter `%name%` (= Feld-Label).
 */
final class RequiredCustomFieldError extends Error
{
    private const KEY_PREFIX = 'rc-custom-fields-required';

    public function __construct(
        private readonly string $lineItemId,
        private readonly int $fieldIndex,
        private readonly string $fieldLabel,
        private readonly string $productName,
    ) {
        $this->message = sprintf('Pflichtfeld "%s" für "%s" wurde nicht ausgefüllt.', $fieldLabel, $productName);

        parent::__construct($this->message);
    }

    public function getId(): string
    {
        // Muss je (Line-Item, Feld) eindeutig sein, sonst überschreiben sich mehrere fehlende
        // Felder in der ErrorCollection (die nach getId() keyed).
        return sprintf('%s-%s-%d', self::KEY_PREFIX, $this->lineItemId, $this->fieldIndex);
    }

    public function getMessageKey(): string
    {
        return 'rcCustomFieldsRequiredFieldMissing';
    }

    /**
     * Wird vom Storefront-Template als `%name%` in den Snippet eingesetzt.
     */
    public function getName(): string
    {
        return $this->fieldLabel;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return [
            '%name%' => $this->fieldLabel,
            '%product%' => $this->productName,
        ];
    }
}
