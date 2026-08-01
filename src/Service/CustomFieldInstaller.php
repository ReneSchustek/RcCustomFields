<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcCustomFields\RcCustomFields;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Shopware\Core\System\CustomField\CustomFieldTypes;

final class CustomFieldInstaller
{
    public const SET_NAME = 'rc_custom_fields';
    private const LOG_CONTEXT = 'ruhrcoder_custom_fields.installer';

    /**
     * @param EntityRepository<CustomFieldSetCollection> $customFieldSetRepository
     * @param EntityRepository<CustomFieldCollection> $customFieldRepository
     */
    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $customFieldRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function install(Context $context): void
    {
        $fields = $this->buildEnabledFlag();

        for ($i = 1; $i <= RcCustomFields::FIELD_COUNT; $i++) {
            $fields = array_merge($fields, $this->buildFieldDefinitions($i));
        }

        // Type-Drift aus historischen v1.0.x-Installationen reconcilen: type ist in Shopware
        // immutable, daher droppen wir Drift-Felder bevor enrichWithExistingIds versucht,
        // ihre IDs zu mappen. Der nachfolgende upsert legt sie dann mit korrektem Type neu an.
        $this->reconcileTypeDrift($fields, $context);

        // Bestehende Felder per Name → ID auflösen, damit upsert UPDATE statt INSERT macht
        // (sonst kollidiert der uniq.custom_field.name-Index beim Upgrade einer Bestandsinstallation).
        $fields = $this->enrichWithExistingIds($fields, $context);

        // Set + Set-Relation analog idempotent: bestehende IDs in Payload einreichen,
        // sonst kollidiert uniq.custom_field_set_relation.entity_name beim Re-Upsert.
        $existingSet = $this->resolveExistingSet(self::SET_NAME, $context);

        $data = [
            'name' => self::SET_NAME,
            'config' => [
                'label' => [
                    'de-DE' => 'RC Custom Fields — Kundeneingaben',
                    'en-GB' => 'RC Custom Fields — Customer Inputs',
                ],
            ],
            'customFields' => $fields,
            'relations' => $this->buildRelationsPayload($existingSet),
        ];

        if ($existingSet !== null) {
            $data['id'] = $existingSet->getId();
        }

        try {
            $this->customFieldSetRepository->upsert([$data], $context);

            $this->logger->info('RcCustomFields: CustomFieldSet installiert/aktualisiert.', [
                'context' => self::LOG_CONTEXT,
                'setName' => self::SET_NAME,
                'fieldCount' => count($fields),
                'updatedSet' => $existingSet !== null,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('RcCustomFields: CustomFieldSet-Install fehlgeschlagen.', [
                'context' => self::LOG_CONTEXT,
                'setName' => self::SET_NAME,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new \RuntimeException(sprintf('RcCustomFields: CustomFieldSet-Install fehlgeschlagen (%s).', self::SET_NAME), 0, $exception);
        }
    }

    public function uninstall(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::SET_NAME));
        $set = $this->customFieldSetRepository->search($criteria, $context)->first();

        if (!$set instanceof CustomFieldSetEntity) {
            $this->logger->info('RcCustomFields: CustomFieldSet bereits abwesend, Uninstall ist No-op.', [
                'context' => self::LOG_CONTEXT,
                'setName' => self::SET_NAME,
            ]);

            return;
        }

        try {
            $this->customFieldSetRepository->delete([['id' => $set->getId()]], $context);

            $this->logger->info('RcCustomFields: CustomFieldSet entfernt.', [
                'context' => self::LOG_CONTEXT,
                'setName' => self::SET_NAME,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('RcCustomFields: CustomFieldSet-Uninstall fehlgeschlagen.', [
                'context' => self::LOG_CONTEXT,
                'setName' => self::SET_NAME,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new \RuntimeException(sprintf('RcCustomFields: CustomFieldSet-Uninstall fehlgeschlagen (%s).', self::SET_NAME), 0, $exception);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    private function reconcileTypeDrift(array $fields, Context $context): void
    {
        $expectedTypeByName = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            $type = $field['type'] ?? null;
            if (is_string($name) && is_string($type) && $name !== '') {
                $expectedTypeByName[$name] = $type;
            }
        }

        if ($expectedTypeByName === []) {
            return;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('name', array_keys($expectedTypeByName)));

        $toDelete = [];
        $drift = [];
        foreach ($this->customFieldRepository->search($criteria, $context)->getEntities() as $entity) {
            if (!$entity instanceof CustomFieldEntity) {
                continue;
            }
            $name = $entity->getName();
            $currentType = $entity->getType();
            $expectedType = $expectedTypeByName[$name] ?? null;
            if ($expectedType !== null && $currentType !== $expectedType) {
                $toDelete[] = ['id' => $entity->getId()];
                $drift[] = [
                    'name' => $name,
                    'oldType' => $currentType,
                    'newType' => $expectedType,
                ];
            }
        }

        if ($toDelete === []) {
            return;
        }

        $this->customFieldRepository->delete($toDelete, $context);

        $this->logger->info('RcCustomFields: Type-Drift in CustomField-Definitionen aufgeloest.', [
            'context' => self::LOG_CONTEXT,
            'droppedCount' => count($toDelete),
            'drift' => $drift,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     *
     * @return array<int, array<string, mixed>>
     */
    private function enrichWithExistingIds(array $fields, Context $context): array
    {
        $names = array_values(array_filter(
            array_map(static fn (array $field): mixed => $field['name'] ?? null, $fields),
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));

        if ($names === []) {
            return $fields;
        }

        $existingIds = $this->resolveExistingFieldIds($names, $context);
        if ($existingIds === []) {
            return $fields;
        }

        foreach ($fields as $index => $field) {
            $name = $field['name'] ?? null;
            if (is_string($name) && isset($existingIds[$name])) {
                $fields[$index]['id'] = $existingIds[$name];
            }
        }

        return $fields;
    }

    /**
     * @param array<int, string> $names
     *
     * @return array<string, string>
     */
    private function resolveExistingFieldIds(array $names, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('name', $names));

        $map = [];
        foreach ($this->customFieldRepository->search($criteria, $context)->getEntities() as $entity) {
            if (!$entity instanceof CustomFieldEntity) {
                continue;
            }
            $name = $entity->getName();
            if (is_string($name) && $name !== '') {
                $map[$name] = $entity->getId();
            }
        }

        return $map;
    }

    private function resolveExistingSet(string $name, Context $context): ?CustomFieldSetEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));
        $criteria->addAssociation('relations');

        $set = $this->customFieldSetRepository->search($criteria, $context)->first();

        return $set instanceof CustomFieldSetEntity ? $set : null;
    }

    /**
     * Liefert die Relations als Upsert-Payload. Wenn das Set bereits eine
     * product-Relation hat, wird deren ID mitgegeben — sonst legt Shopware eine neue an
     * und der unique-Index (set_id, entity_name) bricht beim Re-Upsert.
     *
     * @return array<int, array<string, string>>
     */
    private function buildRelationsPayload(?CustomFieldSetEntity $existingSet): array
    {
        $productRelation = ['entityName' => 'product'];

        if ($existingSet === null) {
            return [$productRelation];
        }

        $relations = $existingSet->getRelations();
        if ($relations === null) {
            return [$productRelation];
        }

        foreach ($relations as $relation) {
            if ($relation->getEntityName() === 'product') {
                $productRelation['id'] = $relation->getId();
                break;
            }
        }

        return [$productRelation];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildEnabledFlag(): array
    {
        return [
            [
                'name' => 'rc_custom_fields_enabled',
                'type' => CustomFieldTypes::BOOL,
                'config' => [
                    'label' => [
                        'de-DE' => 'RC Custom Fields aktivieren',
                        'en-GB' => 'Enable RC Custom Fields',
                    ],
                    'helpText' => [
                        'de-DE' => 'Kundeneingaben für dieses Produkt aktivieren. Unterschiedliche Eingaben erzeugen separate Warenkorbpositionen.',
                        'en-GB' => 'Enable customer input fields for this product. Different inputs create separate cart line items.',
                    ],
                    'customFieldType' => 'checkbox',
                    'customFieldPosition' => 1,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFieldDefinitions(int $index): array
    {
        $pos = $index * 10;

        return [
            [
                'name' => "rc_custom_field_{$index}_active",
                'type' => CustomFieldTypes::BOOL,
                'config' => [
                    'label' => ['de-DE' => "Feld {$index}: Aktiv", 'en-GB' => "Field {$index}: Active"],
                    'customFieldType' => 'checkbox',
                    'customFieldPosition' => $pos,
                ],
            ],
            [
                'name' => "rc_custom_field_{$index}_label",
                'type' => CustomFieldTypes::TEXT,
                'config' => [
                    'label' => ['de-DE' => "Feld {$index}: Bezeichnung", 'en-GB' => "Field {$index}: Label"],
                    'helpText' => ['de-DE' => 'z.B. Länge, Breite, Gravurtext', 'en-GB' => 'e.g. Length, Width, Engraving text'],
                    'customFieldType' => 'text',
                    'customFieldPosition' => $pos + 1,
                ],
            ],
            [
                'name' => "rc_custom_field_{$index}_type",
                'type' => CustomFieldTypes::SELECT,
                'config' => [
                    'label' => ['de-DE' => "Feld {$index}: Typ", 'en-GB' => "Field {$index}: Type"],
                    'options' => [
                        ['value' => 'text', 'label' => ['de-DE' => 'Text (einzeilig)', 'en-GB' => 'Text (single line)']],
                        ['value' => 'number', 'label' => ['de-DE' => 'Zahl', 'en-GB' => 'Number']],
                        ['value' => 'textarea', 'label' => ['de-DE' => 'Text (mehrzeilig)', 'en-GB' => 'Text (multi line)']],
                        ['value' => 'date', 'label' => ['de-DE' => 'Datum', 'en-GB' => 'Date']],
                        ['value' => 'time', 'label' => ['de-DE' => 'Uhrzeit', 'en-GB' => 'Time']],
                        ['value' => 'datetime', 'label' => ['de-DE' => 'Datum und Uhrzeit', 'en-GB' => 'Date and time']],
                        ['value' => 'checkbox', 'label' => ['de-DE' => 'Checkbox (Ja/Nein)', 'en-GB' => 'Checkbox (Yes/No)']],
                        ['value' => 'select', 'label' => ['de-DE' => 'Auswahl (vordefinierte Optionen)', 'en-GB' => 'Select (predefined options)']],
                    ],
                    'customFieldType' => 'select',
                    'customFieldPosition' => $pos + 2,
                ],
            ],
            [
                'name' => "rc_custom_field_{$index}_required",
                'type' => CustomFieldTypes::BOOL,
                'config' => [
                    'label' => ['de-DE' => "Feld {$index}: Pflichtfeld", 'en-GB' => "Field {$index}: Required"],
                    'customFieldType' => 'checkbox',
                    'customFieldPosition' => $pos + 3,
                ],
            ],
            [
                'name' => "rc_custom_field_{$index}_placeholder",
                'type' => CustomFieldTypes::TEXT,
                'config' => [
                    'label' => ['de-DE' => "Feld {$index}: Platzhalter", 'en-GB' => "Field {$index}: Placeholder"],
                    'helpText' => ['de-DE' => 'Hinweis im leeren Eingabefeld', 'en-GB' => 'Hint shown in empty field'],
                    'customFieldType' => 'text',
                    'customFieldPosition' => $pos + 4,
                ],
            ],
            [
                'name' => "rc_custom_field_{$index}_unit",
                'type' => CustomFieldTypes::TEXT,
                'config' => [
                    'label' => ['de-DE' => "Feld {$index}: Einheit", 'en-GB' => "Field {$index}: Unit"],
                    'helpText' => ['de-DE' => 'Hinter dem Wert angezeigt, z.B. mm, cm, kg', 'en-GB' => 'Shown after value, e.g. mm, cm, kg'],
                    'customFieldType' => 'text',
                    'customFieldPosition' => $pos + 5,
                ],
            ],
            [
                'name' => "rc_custom_field_{$index}_min",
                'type' => CustomFieldTypes::FLOAT,
                'config' => [
                    'label' => ['de-DE' => "Feld {$index}: Mindestwert (Zahlenfelder)", 'en-GB' => "Field {$index}: Minimum value (number fields)"],
                    'customFieldType' => 'number',
                    'customFieldPosition' => $pos + 6,
                ],
            ],
            [
                'name' => "rc_custom_field_{$index}_max",
                'type' => CustomFieldTypes::FLOAT,
                'config' => [
                    'label' => ['de-DE' => "Feld {$index}: Maximalwert (Zahlenfelder)", 'en-GB' => "Field {$index}: Maximum value (number fields)"],
                    'customFieldType' => 'number',
                    'customFieldPosition' => $pos + 7,
                ],
            ],
            [
                'name' => "rc_custom_field_{$index}_options",
                'type' => CustomFieldTypes::TEXT,
                'config' => [
                    'label' => ['de-DE' => "Feld {$index}: Auswahl-Optionen", 'en-GB' => "Field {$index}: Select options"],
                    'helpText' => ['de-DE' => 'Eine Option pro Zeile (nur bei Typ „Auswahl"). Format: Wert|Anzeigetext, z.B. „klein|Klein".', 'en-GB' => 'One option per line (only for type "Select"). Format: value|label, e.g. "small|Small".'],
                    'customFieldType' => 'textEditor',
                    'componentName' => 'sw-textarea-field',
                    'customFieldPosition' => $pos + 8,
                ],
            ],
        ];
    }
}
