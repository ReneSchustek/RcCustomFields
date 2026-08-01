<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcCustomFields\RcCustomFields;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Migriert TMMS-Produkt-Custom-Fields (Drittanbieter-Schema) in das RcCustomFields-Schema.
 *
 * TMMS-Schema (am Produkt):
 *   tmms_customer_input_{n}_active             (bool)
 *   tmms_customer_input_{n}_title              (string)
 *   tmms_customer_input_{n}_fieldtype          (string: 'text'|'input'|'number'|'select'|...)
 *   tmms_customer_input_{n}_placeholder        (string)
 *   tmms_customer_input_{n}_required           (bool)
 *   tmms_customer_input_{n}_unit               (string)
 *   tmms_customer_input_{n}_minvalue           (number, nur fuer fieldtype=number)
 *   tmms_customer_input_{n}_maxvalue           (number, nur fuer fieldtype=number)
 *   tmms_customer_input_{n}_selectfieldvalues  (string CSV, nur fuer fieldtype=select)
 *
 * RcCustomFields-Schema (am Produkt):
 *   rc_custom_fields_enabled             (bool)
 *   rc_custom_field_{n}_active           (bool)
 *   rc_custom_field_{n}_label            (string)
 *   rc_custom_field_{n}_type             (string: 'text'|'number'|'select'|...)
 *   rc_custom_field_{n}_placeholder      (string)
 *   rc_custom_field_{n}_required         (bool)
 *   rc_custom_field_{n}_unit             (string)
 *   rc_custom_field_{n}_min              (float)
 *   rc_custom_field_{n}_max              (float)
 *   rc_custom_field_{n}_options          (string, je Zeile "wert|anzeige")
 *
 * Idempotent: zweiter Lauf produziert keinen neuen Stand, weil read+write auf denselben
 * Custom-Field-Key zielt. `--dry-run` schreibt nicht. `--rollback` setzt `rc_custom_fields_enabled`
 * zurueck auf false fuer alle in dieser Migration markierten Produkte.
 */
class TmmsMigrationService
{
    public const TMMS_FIELD_PREFIX = 'tmms_customer_input_';
    public const TMMS_KEY_ACTIVE = 'active';
    public const TMMS_KEY_TITLE = 'title';
    public const TMMS_KEY_FIELDTYPE = 'fieldtype';
    public const TMMS_KEY_PLACEHOLDER = 'placeholder';
    public const TMMS_KEY_REQUIRED = 'required';
    public const TMMS_KEY_UNIT = 'unit';
    public const TMMS_KEY_MINVALUE = 'minvalue';
    public const TMMS_KEY_MAXVALUE = 'maxvalue';
    public const TMMS_KEY_SELECTFIELDVALUES = 'selectfieldvalues';
    public const RC_MIGRATED_MARKER = 'rc_custom_fields_migrated_from_tmms';

    /** Batch-Groesse fuer die katalogweite Iteration — verhindert OOM/Timeout bei grossen Katalogen. */
    private const BATCH_SIZE = 100;

    /** @var array<string, string> Mapping TMMS-Field-Type → RcCustomFields-Type */
    private const TYPE_MAP = [
        'text' => 'text',
        'input' => 'text',
        'number' => 'number',
        'textarea' => 'textarea',
        'date' => 'date',
        'time' => 'time',
        'datetime' => 'datetime',
        'checkbox' => 'checkbox',
        'select' => 'select',
    ];

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $productRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Liefert eine Vorschau, was die Migration tun wuerde — ohne DB-Schreibung.
     * @return list<array{productId: string, productNumber: string, fieldCount: int}>
     */
    public function dryRun(Context $context): array
    {
        return $this->scanCandidates($context);
    }

    /**
     * Fuehrt die Migration aus. Pro Produkt eine DBAL-Transaktion.
     * @return array{processed: int, skipped: int, errors: list<string>}
     */
    public function migrate(Context $context): array
    {
        $candidates = $this->scanCandidates($context);

        $processed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($candidates as $candidate) {
            try {
                $this->connection->beginTransaction();

                $this->migrateProduct($candidate['productId'], $candidate['rawCustomFields'], $context);
                $this->connection->commit();

                ++$processed;
                $this->logger->info('RcCustomFields TMMS-Migration: Produkt migriert.', [
                    'productId' => $candidate['productId'],
                    'productNumber' => $candidate['productNumber'],
                    'fieldCount' => $candidate['fieldCount'],
                ]);
            } catch (\Throwable $exception) {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
                ++$skipped;
                $errors[] = sprintf('%s: %s', $candidate['productNumber'], $exception->getMessage());
                $this->logger->error('RcCustomFields TMMS-Migration: Produkt uebersprungen.', [
                    'productId' => $candidate['productId'],
                    'productNumber' => $candidate['productNumber'],
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Setzt `rc_custom_fields_enabled` auf false und entfernt den Migrations-Marker
     * fuer alle in dieser Migration markierten Produkte.
     * @return array{rolledBack: int}
     */
    public function rollback(Context $context): array
    {
        // Katalogweit gebatcht statt alle Produkte auf einmal in den Speicher zu laden.
        $criteria = new Criteria();
        $criteria->setLimit(self::BATCH_SIZE);
        $iterator = new RepositoryIterator($this->productRepository, $context, $criteria);

        $rolledBack = 0;
        while (($result = $iterator->fetch()) !== null) {
            $updates = [];
            foreach ($result->getEntities() as $product) {
                $customFields = $product->getCustomFields() ?? [];
                if (!isset($customFields[self::RC_MIGRATED_MARKER])) {
                    continue;
                }

                unset($customFields[self::RC_MIGRATED_MARKER]);
                $customFields['rc_custom_fields_enabled'] = false;

                $updates[] = [
                    'id' => $product->getId(),
                    'customFields' => $customFields,
                ];
                ++$rolledBack;
            }

            if ($updates !== []) {
                $this->productRepository->update($updates, $context);
            }
        }

        $this->logger->info('RcCustomFields TMMS-Migration: Rollback abgeschlossen.', [
            'rolledBack' => $rolledBack,
        ]);

        return ['rolledBack' => $rolledBack];
    }

    /**
     * @return list<array{productId: string, productNumber: string, fieldCount: int, rawCustomFields: array<string, mixed>}>
     */
    private function scanCandidates(Context $context): array
    {
        // Katalogweit gebatcht statt alle Produkte auf einmal in den Speicher zu laden (OOM/Timeout).
        $criteria = new Criteria();
        $criteria->setLimit(self::BATCH_SIZE);
        $iterator = new RepositoryIterator($this->productRepository, $context, $criteria);

        $candidates = [];
        while (($result = $iterator->fetch()) !== null) {
            foreach ($result->getEntities() as $product) {
                $customFields = $product->getCustomFields() ?? [];
                if (!\is_array($customFields)) {
                    continue;
                }

                $fieldCount = $this->countTmmsFields($customFields);
                if ($fieldCount === 0) {
                    continue;
                }

                $candidates[] = [
                    'productId' => $product->getId(),
                    'productNumber' => $product->getProductNumber(),
                    'fieldCount' => $fieldCount,
                    'rawCustomFields' => $customFields,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function countTmmsFields(array $customFields): int
    {
        $count = 0;
        for ($i = 1; $i <= RcCustomFields::FIELD_COUNT; $i++) {
            $activeKey = self::TMMS_FIELD_PREFIX . $i . '_' . self::TMMS_KEY_ACTIVE;
            if (!empty($customFields[$activeKey])) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function migrateProduct(string $productId, array $customFields, Context $context): void
    {
        $rcKeys = [
            'rc_custom_fields_enabled' => false,
        ];

        $migratedAny = false;
        for ($i = 1; $i <= RcCustomFields::FIELD_COUNT; $i++) {
            $tmmsActiveKey = self::TMMS_FIELD_PREFIX . $i . '_' . self::TMMS_KEY_ACTIVE;
            if (empty($customFields[$tmmsActiveKey])) {
                continue;
            }

            $rcKeys['rc_custom_fields_enabled'] = true;
            $rcKeys['rc_custom_field_' . $i . '_active'] = true;
            $rcKeys['rc_custom_field_' . $i . '_label'] = $this->stringValue($customFields, self::TMMS_FIELD_PREFIX . $i . '_' . self::TMMS_KEY_TITLE);
            $rcKeys['rc_custom_field_' . $i . '_type'] = $this->mapType($customFields[self::TMMS_FIELD_PREFIX . $i . '_' . self::TMMS_KEY_FIELDTYPE] ?? 'text');
            $rcKeys['rc_custom_field_' . $i . '_placeholder'] = $this->stringValue($customFields, self::TMMS_FIELD_PREFIX . $i . '_' . self::TMMS_KEY_PLACEHOLDER);
            $rcKeys['rc_custom_field_' . $i . '_required'] = (bool) ($customFields[self::TMMS_FIELD_PREFIX . $i . '_' . self::TMMS_KEY_REQUIRED] ?? false);
            $rcKeys['rc_custom_field_' . $i . '_unit'] = $this->stringValue($customFields, self::TMMS_FIELD_PREFIX . $i . '_' . self::TMMS_KEY_UNIT);

            // Range-Constraints (fieldtype=number) — TMMS-Suffix passt nicht zu RcCustomFields.
            $minKey = self::TMMS_FIELD_PREFIX . $i . '_' . self::TMMS_KEY_MINVALUE;
            if (\array_key_exists($minKey, $customFields) && $customFields[$minKey] !== null && $customFields[$minKey] !== '') {
                $rcKeys['rc_custom_field_' . $i . '_min'] = (float) $customFields[$minKey];
            }
            $maxKey = self::TMMS_FIELD_PREFIX . $i . '_' . self::TMMS_KEY_MAXVALUE;
            if (\array_key_exists($maxKey, $customFields) && $customFields[$maxKey] !== null && $customFields[$maxKey] !== '') {
                $rcKeys['rc_custom_field_' . $i . '_max'] = (float) $customFields[$maxKey];
            }

            // Select-Optionen: TMMS speichert CSV ("a, b, c"), RcCustomFields erwartet "wert|anzeige" je Zeile.
            $selectKey = self::TMMS_FIELD_PREFIX . $i . '_' . self::TMMS_KEY_SELECTFIELDVALUES;
            $selectRaw = $customFields[$selectKey] ?? null;
            if (\is_string($selectRaw) && $selectRaw !== '') {
                $rcKeys['rc_custom_field_' . $i . '_options'] = $this->convertSelectOptions($selectRaw);
            }

            $migratedAny = true;
        }

        if (!$migratedAny) {
            return;
        }

        // Marker fuer Rollback-Faehigkeit
        $rcKeys[self::RC_MIGRATED_MARKER] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $merged = array_merge($customFields, $rcKeys);

        $this->productRepository->update([
            ['id' => $productId, 'customFields' => $merged],
        ], $context);
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function stringValue(array $customFields, string $key): string
    {
        $value = $customFields[$key] ?? '';

        return \is_string($value) ? $value : '';
    }

    private function mapType(mixed $tmmsType): string
    {
        if (!\is_string($tmmsType)) {
            $this->logger->warning('RcCustomFields TMMS-Migration: nicht-String-Fieldtype, Fallback auf "text".', [
                'tmmsTypeRaw' => $tmmsType,
            ]);

            return 'text';
        }

        if (!isset(self::TYPE_MAP[$tmmsType])) {
            $this->logger->warning('RcCustomFields TMMS-Migration: unbekannter TMMS-Fieldtype, Fallback auf "text".', [
                'tmmsType' => $tmmsType,
            ]);

            return 'text';
        }

        return self::TYPE_MAP[$tmmsType];
    }

    /**
     * Konvertiert TMMS-Select-CSV ("a, b, c") in das RcCustomFields-Format
     * (eine Zeile je Option, Format "wert|anzeige"). Da TMMS keine separate
     * Anzeige speichert, wird Wert == Anzeige gesetzt.
     */
    private function convertSelectOptions(string $csv): string
    {
        $tokens = array_map('trim', explode(',', $csv));
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));

        return implode("\n", array_map(static fn (string $t): string => $t . '|' . $t, $tokens));
    }
}
