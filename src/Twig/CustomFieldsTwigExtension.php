<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Twig;

use Ruhrcoder\RcCustomFields\Service\CustomFieldsReader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * Stellt die defensiven Custom-Fields-Helper für Mail-, Dokumenten- und
 * Storefront-Templates bereit. Reine Delegation auf {@see CustomFieldsReader} —
 * die Logik (Dual-Schema-Read, Normalisierung) liegt dort und ist ohne
 * Twig-Bootstrap testbar.
 *
 *   {% for entry in rc_cf_entries(lineItem.customFields) %}
 *       {{ entry.label }}: {{ entry.value }}
 *   {% endfor %}
 *
 *   {% if lineItem.customFields is rc_cf_filled %} … {% endif %}
 */
final class CustomFieldsTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly CustomFieldsReader $reader,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('rc_cf_entries', $this->entries(...)),
        ];
    }

    public function getTests(): array
    {
        return [
            new TwigTest('rc_cf_filled', $this->hasEntries(...)),
        ];
    }

    /**
     * @param array<string, mixed>|null $customFields
     *
     * @return list<array{index: int, label: string, value: string}>
     */
    public function entries(?array $customFields): array
    {
        return $this->reader->entries($customFields);
    }

    /**
     * @param array<string, mixed>|null $customFields
     */
    public function hasEntries(?array $customFields): bool
    {
        return $this->reader->hasEntries($customFields);
    }
}
