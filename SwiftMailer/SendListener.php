<?php

/**
 * This file is part of the pd-admin pd-mailer package.
 *
 * @package     pd-mailer
 * @license     LICENSE
 * @author      Ramazan APAYDIN <apaydin541@gmail.com>
 * @link        https://github.com/appaydin/pd-mailer
 */

namespace Pd\MailerBundle\SwiftMailer;

use Doctrine\ORM\EntityManagerInterface;
use Pd\MailerBundle\Entity\MailLog;
use Pd\MailerBundle\Render\RenderInterface;
use Swift_Events_TransportExceptionEvent;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Swiftmailer Plugin.
 *
 * @author Ramazan APAYDIN <apaydin541@gmail.com>
 */
class SendListener implements \Swift_Events_SendListener, \Swift_Events_TransportExceptionListener
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var ParameterBagInterface
     */
    private $bag;

    /**
     * @var RequestStack
     */
    private $request;

    /**
     * @var RenderInterface
     */
    private $engine;

    /**
     * @var \Swift_Mime_SimpleMessage
     */
    private $msg = null;

    /**
     * @var MailLog
     */
    private $log;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $bag, RequestStack $request, RenderInterface $engine)
    {
        $this->entityManager = $entityManager;
        $this->bag = $bag;
        $this->request = $request;
        $this->engine = $engine;
    }

    /**
     * Invoked immediately before the Message is sent.
     *
     * @throws \Throwable
     */
    public function beforeSendPerformed(\Swift_Events_SendEvent $evt)
    {
        if (\Swift_Events_SendEvent::RESULT_PENDING === $evt->getResult() && (null === $this->msg)) {
            $this->msg = $evt->getMessage();
            $locale = $this->request->getCurrentRequest()->getLocale() ?? 'en';

            // Find & Remove Template ID
            $templateID = null;
            if (null !== ($tID = $this->msg->getHeaders()->get('TemplateID'))) {
                $templateID = $tID->getFieldBody();
                $this->msg->getHeaders()->remove('TemplateID');
            }

            // Create Log
            if ($this->bag->get('pd_mailer.logger_active')) {
                $this->logCreate($evt->getMessage(), $evt->getResult(), $templateID, $locale);
            }

            // Set Message From
            if (\count($this->msg->getFrom()) < 1) {
                $evt->getMessage()->setFrom(
                    $this->bag->get('pd_mailer.sender_address'),
                    $this->bag->get('pd_mailer.sender_name')
                );
            }

            // Render Template
            if (null !== $templateID) {
                if ($this->bag->get('pd_mailer.template_active')) {
                    $this->engine->render($templateID, $locale, $this->msg);
                }
            }
        }
    }

    /**
     * Invoked immediately after the Message is sent.
     */
    public function sendPerformed(\Swift_Events_SendEvent $evt)
    {
        if ($this->bag->get('pd_mailer.logger_active')) {
            if (!\in_array($evt->getResult(), [\Swift_Events_SendEvent::RESULT_PENDING, \Swift_Events_SendEvent::RESULT_SPOOLED], true)) {
                $this->logUpdate($evt->getMessage(), $evt->getResult());
            }
        }
    }

    /**
     * Invoked as a TransportException is thrown in the Transport system.
     */
    public function exceptionThrown(Swift_Events_TransportExceptionEvent $evt)
    {
        if ($this->bag->get('pd_mailer.logger_active') && $this->log) {
            // Update Data
            $this->log->setStatus(\Swift_Events_SendEvent::RESULT_FAILED);
            $this->log->addException($evt->getException()->getMessage().PHP_EOL);

            // Update
            $this->entityManager->persist($this->log);
            $this->entityManager->flush();
        }
    }

    /**
     * Create Mail Log.
     *
     * @param $status
     * @param $templateID
     * @param $language
     *
     * @return bool
     */
    private function logCreate(\Swift_Mime_SimpleMessage $message, $status, $templateID, $language)
    {
        // Check Message
        if (null === $message->getId() || empty($message->getId())) {
            return false;
        }

        // Create Log
        $class = $this->bag->get('pd_mailer.mail_log_class');
        $this->log = new $class();
        $this->log->setMailId($message->getId());
        $this->log->setFrom($message->getFrom());
        $this->log->setTo($message->getTo());
        $this->log->setSubject($message->getSubject());
        $this->log->setBody($message->getBody());
        $this->log->setContentType($message->getContentType());
        $this->log->setDate($message->getDate());
        $this->log->setHeader($message->getHeaders()->toString());
        $this->log->setReplyTo($message->getReplyTo());
        $this->log->setStatus($status);
        $this->log->setTemplateId($templateID);
        $this->log->setLanguage($language);

        // Save
        $this->entityManager->persist($this->log);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Update Log Status & Date.
     *
     * @param $status
     *
     * @return bool
     */
    private function logUpdate(\Swift_Mime_SimpleMessage $message, $status)
    {
        // Check Message
        if (null === $message->getId() || empty($message->getId())) {
            return false;
        }

        // Update Data
        $this->log->setStatus($status);
        $this->log->setDate($message->getDate());

        // Save || Update
        $this->entityManager->persist($this->log);
        $this->entityManager->flush();

        return true;
    }
}
