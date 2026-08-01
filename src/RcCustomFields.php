<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcCustomFields\Service\CustomFieldInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

final class RcCustomFields extends Plugin
{
    public const FIELD_COUNT = 10;
    public const PAYLOAD_KEY = 'rcCustomFields';

    public function install(InstallContext $context): void
    {
        parent::install($context);
        $this->getInstaller()->install($context->getContext());
    }

    public function update(UpdateContext $context): void
    {
        parent::update($context);
        $this->getInstaller()->install($context->getContext());
    }

    public function uninstall(UninstallContext $context): void
    {
        parent::uninstall($context);

        if (!$context->keepUserData()) {
            $this->getInstaller()->uninstall($context->getContext());
        }
    }

    private function getInstaller(): CustomFieldInstaller
    {
        $container = $this->container;
        if ($container === null) {
            throw new \RuntimeException('Plugin-Container ist im aktuellen Lifecycle-Zustand nicht verfügbar.');
        }

        /** @var EntityRepository<\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection> $setRepository */
        $setRepository = $container->get('custom_field_set.repository');
        /** @var EntityRepository<\Shopware\Core\System\CustomField\CustomFieldCollection> $fieldRepository */
        $fieldRepository = $container->get('custom_field.repository');

        // Logger ist im Lifecycle-Container nicht garantiert — Fallback auf NullLogger.
        $logger = $container->has('logger') ? $container->get('logger') : new NullLogger();
        if (!$logger instanceof LoggerInterface) {
            $logger = new NullLogger();
        }

        return new CustomFieldInstaller($setRepository, $fieldRepository, $logger);
    }
}
