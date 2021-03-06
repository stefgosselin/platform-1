<?php

namespace Oro\Bundle\ImapBundle\Sync;

use Doctrine\Common\Persistence\ManagerRegistry;

use Oro\Bundle\EmailBundle\Entity\EmailOrigin;
use Oro\Bundle\EmailBundle\Sync\AbstractEmailSynchronizer;
use Oro\Bundle\EmailBundle\Sync\AbstractEmailSynchronizationProcessor;
use Oro\Bundle\EmailBundle\Sync\KnownEmailAddressCheckerFactory;
use Oro\Bundle\EmailBundle\Sync\Model\SynchronizationProcessorSettings;
use Oro\Bundle\ImapBundle\Async\Topics;
use Oro\Bundle\ImapBundle\Connector\ImapConfig;
use Oro\Bundle\ImapBundle\Connector\ImapConnectorFactory;
use Oro\Bundle\ImapBundle\Exception\SocketTimeoutException;
use Oro\Bundle\ImapBundle\Manager\ImapEmailGoogleOauth2Manager;
use Oro\Bundle\ImapBundle\Manager\ImapEmailManager;
use Oro\Bundle\ImapBundle\Entity\UserEmailOrigin;
use Oro\Bundle\SecurityBundle\Encoder\Mcrypt;

class ImapEmailSynchronizer extends AbstractEmailSynchronizer
{
    static protected $messageQueueTopic = Topics::SYNC_EMAILS;

    /** @var ImapEmailSynchronizationProcessorFactory */
    protected $syncProcessorFactory;

    /** @var ImapConnectorFactory */
    protected $connectorFactory;

    /** @var Mcrypt */
    protected $encryptor;

    /** @var ImapEmailGoogleOauth2Manager */
    protected $imapEmailGoogleOauth2Manager;

    /**
     * @param ManagerRegistry $doctrine
     * @param KnownEmailAddressCheckerFactory $knownEmailAddressCheckerFactory
     * @param ImapEmailSynchronizationProcessorFactory $syncProcessorFactory
     * @param ImapConnectorFactory $connectorFactory
     * @param Mcrypt $encryptor
     * @param ImapEmailGoogleOauth2Manager $imapEmailGoogleOauth2Manager
     */
    public function __construct(
        ManagerRegistry $doctrine,
        KnownEmailAddressCheckerFactory $knownEmailAddressCheckerFactory,
        ImapEmailSynchronizationProcessorFactory $syncProcessorFactory,
        ImapConnectorFactory $connectorFactory,
        Mcrypt $encryptor,
        ImapEmailGoogleOauth2Manager $imapEmailGoogleOauth2Manager
    ) {
        parent::__construct($doctrine, $knownEmailAddressCheckerFactory);

        $this->syncProcessorFactory = $syncProcessorFactory;
        $this->connectorFactory     = $connectorFactory;
        $this->encryptor            = $encryptor;
        $this->imapEmailGoogleOauth2Manager = $imapEmailGoogleOauth2Manager;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(EmailOrigin $origin)
    {
        return $origin instanceof UserEmailOrigin;
    }

    /**
     * {@inheritdoc}
     */
    protected function getEmailOriginClass()
    {
        return 'OroImapBundle:UserEmailOrigin';
    }

    /**
     * {@inheritdoc}
     */
    public function supportScheduleJob()
    {
        return true;
    }

    /**
     * Creates a processor is used to synchronize emails
     *
     * @param UserEmailOrigin $origin
     * @return ImapEmailSynchronizationProcessor
     */
    protected function createSynchronizationProcessor($origin)
    {
        $config = new ImapConfig(
            $origin->getImapHost(),
            $origin->getImapPort(),
            $origin->getImapEncryption(),
            $origin->getUser(),
            $this->encryptor->decryptData($origin->getPassword()),
            $this->imapEmailGoogleOauth2Manager->getAccessTokenWithCheckingExpiration($origin)
        );

        return $this->syncProcessorFactory->create(
            new ImapEmailManager($this->connectorFactory->createImapConnector($config)),
            $this->getKnownEmailAddressChecker()
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function delegateToProcessor(
        EmailOrigin $origin,
        AbstractEmailSynchronizationProcessor $processor,
        SynchronizationProcessorSettings $settings = null
    ) {
        try {
            parent::delegateToProcessor($origin, $processor, $settings);
        } catch (SocketTimeoutException $ex) {
            $this->logger->warning(
                sprintf(
                    'Exit because of "%s" origin\'s socket timed out. Error: "%s"',
                    $origin->getId(),
                    $ex->getMessage()
                ),
                $ex->getSocketMetadata()
            );
            $this->changeOriginSyncState($origin, self::SYNC_CODE_SUCCESS);

            return;
        }
    }
}
