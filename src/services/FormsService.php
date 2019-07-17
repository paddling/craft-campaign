<?php
/**
 * @link      https://craftcampaign.com
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\services;

use craft\errors\MissingComponentException;
use craft\helpers\UrlHelper;
use craft\mail\Message;
use craft\web\View;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\ContactElement;
use putyourlightson\campaign\elements\MailingListElement;
use putyourlightson\campaign\events\SubscribeContactEvent;
use putyourlightson\campaign\events\UnsubscribeContactEvent;
use putyourlightson\campaign\events\UpdateContactEvent;
use putyourlightson\campaign\helpers\ContactActivityHelper;
use putyourlightson\campaign\models\PendingContactModel;

use Craft;
use craft\base\Component;
use craft\errors\ElementNotFoundException;
use RuntimeException;
use Throwable;
use yii\base\Exception;

/**
 * FormsService
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.10.0
 */
class FormsService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event SubscribeContactEvent
     */
    const EVENT_BEFORE_SUBSCRIBE_CONTACT = 'beforeSubscribeContact';

    /**
     * @event SubscribeContactEvent
     */
    const EVENT_AFTER_SUBSCRIBE_CONTACT = 'afterSubscribeContact';

    /**
     * @event UnsubscribeContactEvent
     */
    const EVENT_BEFORE_UNSUBSCRIBE_CONTACT = 'beforeUnsubscribeContact';

    /**
     * @event UnsubscribeContactEvent
     */
    const EVENT_AFTER_UNSUBSCRIBE_CONTACT = 'afterUnsubscribeContact';

    /**
     * @event UpdateContactEvent
     */
    const EVENT_BEFORE_UPDATE_CONTACT = 'beforeUpdateContact';

    /**
     * @event UpdateContactEvent
     */
    const EVENT_AFTER_UPDATE_CONTACT = 'afterUpdateContact';

    // Public Methods
    // =========================================================================

    /**
     * Sends a verification email
     *
     * @param PendingContactModel $pendingContact
     * @param MailingListElement $mailingList
     *
     * @return bool
     * @throws Exception
     * @throws MissingComponentException
     */
    public function sendVerificationEmail(PendingContactModel $pendingContact, MailingListElement $mailingList): bool
    {
        // Set the current site from the mailing list's site ID
        Craft::$app->sites->setCurrentSite($mailingList->siteId);

        $path = Craft::$app->getConfig()->getGeneral()->actionTrigger.'/campaign/forms/verify-email';
        $url = UrlHelper::siteUrl($path, ['pid' => $pendingContact->pid]);

        $subject = Craft::t('campaign', 'Verify your email address');
        $bodyText = Craft::t('campaign', 'Thank you for subscribing to the mailing list. Please verify your email address by clicking on the following link:');
        $body = $bodyText."\n".$url;

        // Get subject from setting if defined
        $subject = $mailingList->mailingListType->verifyEmailSubject ?: $subject;

        // Get body from template if defined
        if ($mailingList->mailingListType->verifyEmailTemplate) {
            $view = Craft::$app->getView();

            // Set template mode to site
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            try {
                $body = $view->renderTemplate($mailingList->mailingListType->verifyEmailTemplate, [
                    'message' => $bodyText,
                    'url' => $url,
                    'mailingList' => $mailingList,
                    'pendingContact' => $pendingContact,
                ]);
            }
            catch (RuntimeException $e) {}
        }

        return $this->_sendEmail($pendingContact->email, $subject, $body, $mailingList->siteId);
    }

    /**
     * Sends an unsubscribe email
     *
     * @param ContactElement $contact
     * @param MailingListElement $mailingList
     *
     * @return bool
     * @throws Exception
     * @throws MissingComponentException
     */
    public function sendUnsubscribeEmail(ContactElement $contact, MailingListElement $mailingList): bool
    {
        // Set the current site from the mailing list's site ID
        Craft::$app->sites->setCurrentSite($mailingList->siteId);

        $path = Craft::$app->getConfig()->getGeneral()->actionTrigger.'/campaign/forms/unsubscribe-email';
        $url = UrlHelper::siteUrl($path, [
            'cid' => $contact->cid,
            'uid' => $contact->uid,
            'mlid' => $mailingList,
        ]);

        $subject = Craft::t('campaign', 'Confirm unsubscribe');
        $bodyText = Craft::t('campaign', 'Please confirm that you would like to unsubscribe from the mailing list by clicking on the following link:');
        $body = $bodyText."\n".$url;

        // Get subject from setting if defined
        $subject = $mailingList->mailingListType->unsubscribeEmailSubject ?: $subject;

        // Get body from template if defined
        if ($mailingList->mailingListType->unsubscribeEmailTemplate) {
            $view = Craft::$app->getView();

            // Set template mode to site
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            try {
                $body = $view->renderTemplate($mailingList->mailingListType->verifyEmailTemplate, [
                    'message' => $bodyText,
                    'url' => $url,
                    'mailingList' => $mailingList,
                    'contact' => $contact,
                ]);
            }
            catch (RuntimeException $e) {}
        }

        return $this->_sendEmail($contact->email, $subject, $body, $mailingList->siteId);
    }

    /**
     * Subscribe contact
     *
     * @param ContactElement $contact
     * @param MailingListElement $mailingList
     * @param string|null $sourceType
     * @param string|null $source
     * @param bool|null $verify
     *
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws Throwable
     */
    public function subscribeContact(ContactElement $contact, MailingListElement $mailingList, string $sourceType = null, string $source = null, bool $verify = null)
    {
        $sourceType = $sourceType ?? '';
        $source = $source ?? '';
        $verify = $verify ?? false;

        // Fire a before event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SUBSCRIBE_CONTACT)) {
            $this->trigger(self::EVENT_BEFORE_SUBSCRIBE_CONTACT, new SubscribeContactEvent([
                'contact' => $contact,
                'mailingList' => $mailingList,
                'sourceType' => $sourceType,
                'source' => $source,
            ]));
        }

        Campaign::$plugin->mailingLists->addContactInteraction($contact, $mailingList, 'subscribed', $sourceType, $source, $verify);

        // Update contact activity
        ContactActivityHelper::updateContactActivity($contact);

        // Fire an after event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SUBSCRIBE_CONTACT)) {
            $this->trigger(self::EVENT_AFTER_SUBSCRIBE_CONTACT, new SubscribeContactEvent([
                'contact' => $contact,
                'mailingList' => $mailingList,
                'sourceType' => $sourceType,
                'source' => $source,
            ]));
        }
    }

    /**
     * Unsubscribes a contact
     *
     * @param ContactElement $contact
     * @param MailingListElement $mailingList
     *
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws Throwable
     */
    public function unsubscribeContact(ContactElement $contact, MailingListElement $mailingList)
    {
        // Fire a before event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_UNSUBSCRIBE_CONTACT)) {
            $this->trigger(self::EVENT_BEFORE_UNSUBSCRIBE_CONTACT, new UnsubscribeContactEvent([
                'contact' => $contact,
                'mailingList' => $mailingList,
            ]));
        }

        Campaign::$plugin->mailingLists->addContactInteraction($contact, $mailingList, 'unsubscribed');

        // Fire an after event
        if ($mailingList !== null AND $this->hasEventHandlers(self::EVENT_AFTER_UNSUBSCRIBE_CONTACT)) {
            $this->trigger(self::EVENT_AFTER_UNSUBSCRIBE_CONTACT, new UnsubscribeContactEvent([
                'contact' => $contact,
                'mailingList' => $mailingList,
            ]));
        }

        // Update contact activity
        ContactActivityHelper::updateContactActivity($contact);
    }

    /**
     * Updates a contact
     *
     * @param ContactElement $contact
     *
     * @return bool
     */
    public function updateContact(ContactElement $contact): bool
    {
        // Fire a before event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_UPDATE_CONTACT)) {
            $this->trigger(self::EVENT_BEFORE_UPDATE_CONTACT, new UpdateContactEvent([
                'contact' => $contact,
            ]));
        }

        if (!Craft::$app->getElements()->saveElement($contact)) {
            return false;
        }

        // Update contact activity
        ContactActivityHelper::updateContactActivity($contact);

        // Fire an after event
        if ($this->hasEventHandlers(self::EVENT_AFTER_UPDATE_CONTACT)) {
            $this->trigger(self::EVENT_AFTER_UPDATE_CONTACT, new UpdateContactEvent([
                'contact' => $contact,
            ]));
        }

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Sends an email to a contact
     *
     * @param string $email
     * @param string $subject
     * @param string $body
     * @param int $siteId
     *
     * @return bool
     * @throws MissingComponentException
     */
    public function _sendEmail(string $email, string $subject, string $body, int $siteId): bool
    {
        $mailer = Campaign::$plugin->createMailer();

        // Get from name and email
        $fromNameEmail = Campaign::$plugin->settings->getFromNameEmail($siteId);

        // Create message
        /** @var Message $message */
        $message = $mailer->compose()
            ->setFrom([$fromNameEmail['email'] => $fromNameEmail['name']])
            ->setTo($email)
            ->setSubject($subject)
            ->setHtmlBody($body)
            ->setTextBody($body);

        if ($fromNameEmail['replyTo']) {
            $message->setReplyTo($fromNameEmail['replyTo']);
        }

        return $message->send();
    }
}
