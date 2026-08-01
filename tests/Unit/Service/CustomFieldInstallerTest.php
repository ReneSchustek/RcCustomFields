<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcCustomFields\RcCustomFields;
use Ruhrcoder\RcCustomFields\Service\CustomFieldInstaller;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationEntity;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Shopware\Core\System\CustomField\CustomFieldEntity;

#[CoversClass(CustomFieldInstaller::class)]
final class CustomFieldInstallerTest extends TestCase
{
    /** @var EntityRepository<CustomFieldSetCollection>&MockObject */
    private EntityRepository&MockObject $setRepository;
    /** @var EntityRepository<CustomFieldCollection>&MockObject */
    private EntityRepository&MockObject $fieldRepository;
    private LoggerInterface&MockObject $logger;
    private Context $context;

    protected function setUp(): void
    {
        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->fieldRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->context = Context::createDefaultContext();

        // Defaults: keine bestehenden Felder, kein bestehendes Set — sauberer Erstinstall-Pfad.
        $this->fieldRepository->method('search')->willReturn($this->emptySearchResult());
        $this->setRepository->method('search')->willReturn($this->emptySearchResult());
    }

    public function testInstallUpsertsCustomFieldSetWithProductRelation(): void
    {
        $this->setRepository->expects(self::once())
            ->method('upsert')
            ->with(self::callback(function (array $payload): bool {
                self::assertCount(1, $payload);
                self::assertSame(CustomFieldInstaller::SET_NAME, $payload[0]['name']);
                self::assertSame([['entityName' => 'product']], $payload[0]['relations']);

                return true;
            }), $this->context);

        $this->logger->expects(self::once())->method('info');

        $this->makeInstaller()->install($this->context);
    }

    public function testInstallProducesEnabledFlagPlusFiveFieldDefinitions(): void
    {
        $this->setRepository->method('upsert')
            ->with(self::callback(function (array $payload): bool {
                $fields = $payload[0]['customFields'];
                // 1 enabled-Flag + N Feld-Defs x 9 Felder pro Definition.
                self::assertCount(1 + RcCustomFields::FIELD_COUNT * 9, $fields);
                self::assertSame('rc_custom_fields_enabled', $fields[0]['name']);

                // Felder pro Definition: active, label, type, required, placeholder, unit, min, max, options
                $expectedSuffixes = ['active', 'label', 'type', 'required', 'placeholder', 'unit', 'min', 'max', 'options'];
                foreach ($expectedSuffixes as $offset => $suffix) {
                    self::assertSame(
                        "rc_custom_field_1_{$suffix}",
                        $fields[1 + $offset]['name'],
                        sprintf('Feld %d (suffix=%s) hat falschen Namen', $offset, $suffix),
                    );
                }

                return true;
            }));

        $this->makeInstaller()->install($this->context);
    }

    public function testInstallExposesAllEightTypeOptionsInSelect(): void
    {
        $this->setRepository->method('upsert')
            ->with(self::callback(function (array $payload): bool {
                $typeField = null;
                foreach ($payload[0]['customFields'] as $field) {
                    if ($field['name'] === 'rc_custom_field_1_type') {
                        $typeField = $field;
                        break;
                    }
                }
                self::assertNotNull($typeField, 'Typ-Feld nicht gefunden');

                $values = array_column($typeField['config']['options'], 'value');
                $expected = ['text', 'number', 'textarea', 'date', 'time', 'datetime', 'checkbox', 'select'];
                self::assertSame($expected, $values);

                return true;
            }));

        $this->makeInstaller()->install($this->context);
    }

    public function testInstallLogsAndRethrowsOnUpsertFailure(): void
    {
        $boom = new \RuntimeException('DAL exploded');
        $this->setRepository->method('upsert')->willThrowException($boom);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                'RcCustomFields: CustomFieldSet-Install fehlgeschlagen.',
                self::callback(function (array $context): bool {
                    self::assertSame(\RuntimeException::class, $context['exception']);

                    return true;
                }),
            );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CustomFieldSet-Install fehlgeschlagen');

        $this->makeInstaller()->install($this->context);
    }

    public function testInstallReconcilesTypeDriftByDroppingMismatchedFields(): void
    {
        // Bestandsfall: rc_custom_field_1_min existiert als "text", Code erwartet "float".
        $driftEntity = new CustomFieldEntity();
        $driftEntity->setId('id-1-min-drifted');
        $driftEntity->setName('rc_custom_field_1_min');
        $driftEntity->setType('text');

        $matchingEntity = new CustomFieldEntity();
        $matchingEntity->setId('id-1-active-ok');
        $matchingEntity->setName('rc_custom_field_1_active');
        $matchingEntity->setType('bool');

        $this->fieldRepository = $this->createMock(EntityRepository::class);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')
            ->willReturn(new CustomFieldCollection([$driftEntity, $matchingEntity]));
        $this->fieldRepository->method('search')->willReturn($searchResult);

        // Nur die Drift-Field-ID darf gelöscht werden, das matching nicht.
        $this->fieldRepository->expects(self::once())
            ->method('delete')
            ->with([['id' => 'id-1-min-drifted']], $this->context);

        $this->logger->expects(self::atLeastOnce())
            ->method('info');

        $this->makeInstaller()->install($this->context);
    }

    public function testInstallSkipsDeleteWhenNoTypeDrift(): void
    {
        // Bestandsfall: bestehende Felder, alle mit korrektem Type.
        $okEntity = new CustomFieldEntity();
        $okEntity->setId('id-1-active');
        $okEntity->setName('rc_custom_field_1_active');
        $okEntity->setType('bool');

        $this->fieldRepository = $this->createMock(EntityRepository::class);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')
            ->willReturn(new CustomFieldCollection([$okEntity]));
        $this->fieldRepository->method('search')->willReturn($searchResult);

        $this->fieldRepository->expects(self::never())->method('delete');

        $this->makeInstaller()->install($this->context);
    }

    public function testInstallEnrichesPayloadWithExistingFieldIdsForIdempotentUpgrade(): void
    {
        // Simuliert eine Bestandsinstallation: 'rc_custom_fields_enabled' und
        // 'rc_custom_field_1_active' existieren bereits mit stabilen IDs.
        $existing = [
            'rc_custom_fields_enabled' => 'id-enabled',
            'rc_custom_field_1_active' => 'id-1-active',
        ];

        $this->fieldRepository = $this->createMock(EntityRepository::class);
        $this->fieldRepository->method('search')->willReturn(
            $this->searchResultWithFields($existing),
        );

        $this->setRepository->method('upsert')
            ->with(self::callback(function (array $payload) use ($existing): bool {
                $fields = $payload[0]['customFields'];
                $byName = [];
                foreach ($fields as $field) {
                    $byName[$field['name']] = $field;
                }

                foreach ($existing as $name => $expectedId) {
                    self::assertArrayHasKey('id', $byName[$name], "Feld {$name} muss bestehende ID tragen");
                    self::assertSame($expectedId, $byName[$name]['id']);
                }

                // Andere, nicht-bestehende Felder dürfen KEINE id tragen
                self::assertArrayNotHasKey('id', $byName['rc_custom_field_2_active']);

                return true;
            }));

        $this->makeInstaller()->install($this->context);
    }

    public function testInstallEnrichesPayloadWithExistingSetIdForIdempotentUpgrade(): void
    {
        $existingSet = $this->makeSetEntity('set-id-existing', null);

        $setSearchResult = $this->createMock(EntitySearchResult::class);
        $setSearchResult->method('first')->willReturn($existingSet);

        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->setRepository->method('search')->willReturn($setSearchResult);

        $this->setRepository->expects(self::once())
            ->method('upsert')
            ->with(self::callback(function (array $payload): bool {
                self::assertArrayHasKey('id', $payload[0]);
                self::assertSame('set-id-existing', $payload[0]['id']);

                return true;
            }));

        $this->makeInstaller()->install($this->context);
    }

    public function testInstallEnrichesProductRelationWithExistingIdForIdempotentUpgrade(): void
    {
        $existingSet = $this->makeSetEntity('set-id-existing', 'relation-id-product');

        $setSearchResult = $this->createMock(EntitySearchResult::class);
        $setSearchResult->method('first')->willReturn($existingSet);

        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->setRepository->method('search')->willReturn($setSearchResult);

        $this->setRepository->expects(self::once())
            ->method('upsert')
            ->with(self::callback(function (array $payload): bool {
                self::assertCount(1, $payload[0]['relations']);
                self::assertSame('product', $payload[0]['relations'][0]['entityName']);
                self::assertArrayHasKey('id', $payload[0]['relations'][0], 'Relation muss bestehende ID tragen');
                self::assertSame('relation-id-product', $payload[0]['relations'][0]['id']);

                return true;
            }));

        $this->makeInstaller()->install($this->context);
    }

    public function testInstallOmitsSetIdAndRelationIdForGreenfieldInstall(): void
    {
        // Defaults aus setUp: kein bestehendes Set.
        $this->setRepository->expects(self::once())
            ->method('upsert')
            ->with(self::callback(function (array $payload): bool {
                self::assertArrayNotHasKey('id', $payload[0]);
                self::assertCount(1, $payload[0]['relations']);
                self::assertSame('product', $payload[0]['relations'][0]['entityName']);
                self::assertArrayNotHasKey('id', $payload[0]['relations'][0]);

                return true;
            }));

        $this->makeInstaller()->install($this->context);
    }

    public function testUninstallSkipsDeleteWhenSetNotFound(): void
    {
        // Default setUp liefert leeres SearchResult → first() == null.
        $this->setRepository->expects(self::never())->method('delete');
        $this->logger->expects(self::once())
            ->method('info')
            ->with('RcCustomFields: CustomFieldSet bereits abwesend, Uninstall ist No-op.', self::anything());

        $this->makeInstaller()->uninstall($this->context);
    }

    public function testUninstallDeletesExistingSet(): void
    {
        $existingSet = $this->makeSetEntity('set-id-123', null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($existingSet);

        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->setRepository->method('search')->willReturn($searchResult);

        $this->setRepository->expects(self::once())
            ->method('delete')
            ->with([['id' => 'set-id-123']], $this->context);

        $this->logger->expects(self::once())
            ->method('info')
            ->with('RcCustomFields: CustomFieldSet entfernt.', self::anything());

        $this->makeInstaller()->uninstall($this->context);
    }

    public function testUninstallLogsAndRethrowsOnDeleteFailure(): void
    {
        $existingSet = $this->makeSetEntity('set-id-123', null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($existingSet);

        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->setRepository->method('search')->willReturn($searchResult);

        $boom = new \RuntimeException('Delete fehlgeschlagen');
        $this->setRepository->method('delete')->willThrowException($boom);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('RcCustomFields: CustomFieldSet-Uninstall fehlgeschlagen.', self::anything());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CustomFieldSet-Uninstall fehlgeschlagen');

        $this->makeInstaller()->uninstall($this->context);
    }

    public function testNullLoggerIstZulaessigerDefault(): void
    {
        $this->setRepository->expects(self::once())->method('upsert');

        (new CustomFieldInstaller($this->setRepository, $this->fieldRepository))->install($this->context);

        self::assertInstanceOf(NullLogger::class, new NullLogger());
    }

    private function makeInstaller(): CustomFieldInstaller
    {
        return new CustomFieldInstaller($this->setRepository, $this->fieldRepository, $this->logger);
    }

    private function makeSetEntity(string $setId, ?string $productRelationId): CustomFieldSetEntity
    {
        $set = new CustomFieldSetEntity();
        $set->setId($setId);

        if ($productRelationId !== null) {
            $relation = new CustomFieldSetRelationEntity();
            $relation->setId($productRelationId);
            $relation->setEntityName('product');
            $set->setRelations(new CustomFieldSetRelationCollection([$relation]));
        }

        return $set;
    }

    /**
     * @return EntitySearchResult<CustomFieldCollection>&MockObject
     */
    private function emptySearchResult(): EntitySearchResult&MockObject
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new CustomFieldCollection());
        $result->method('first')->willReturn(null);

        return $result;
    }

    /**
     * @param array<string, string> $nameToId
     *
     * @return EntitySearchResult<CustomFieldCollection>&MockObject
     */
    private function searchResultWithFields(array $nameToId): EntitySearchResult&MockObject
    {
        $entities = [];
        foreach ($nameToId as $name => $id) {
            $entity = new CustomFieldEntity();
            $entity->setId($id);
            $entity->setName($name);
            // Type passend zur Code-Definition setzen, damit reconcileTypeDrift hier no-op bleibt.
            $entity->setType($this->expectedTypeFor($name));
            $entities[] = $entity;
        }

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new CustomFieldCollection($entities));
        $result->method('first')->willReturn($entities[0] ?? null);

        return $result;
    }

    private function expectedTypeFor(string $name): string
    {
        // Spiegelt CustomFieldInstaller::buildFieldDefinitions(); ohne Drift-Erkennung im Test.
        return match (true) {
            $name === 'rc_custom_fields_enabled' => 'bool',
            str_ends_with($name, '_active'), str_ends_with($name, '_required') => 'bool',
            str_ends_with($name, '_type') => 'select',
            str_ends_with($name, '_min'), str_ends_with($name, '_max') => 'float',
            default => 'text',
        };
    }
}
