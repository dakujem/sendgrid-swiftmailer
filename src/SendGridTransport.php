<?php


namespace Dakujem\SwiftGrid;

use finfo,
	Psr\Log\LoggerInterface,
	SendGrid,
	Swift_Events_EventListener,
	Swift_Mime_Attachment,
	Swift_Mime_SimpleMessage,
	Swift_Transport;


/**
 * SendGrid transport for Swift Mailer.
 *
 * @author Andrej Rypák (dakujem) <xrypak@gmail.com>
 */
class SendGridTransport implements Swift_Transport
{

	/**
	 * Sendgrid api key.
	 *
	 * @var string
	 */
	protected $sendGridApiKey;

	/**
	 * On-ready event handlers.
	 * The event fires when the Mail has been populated and is ready to be posted to SendGrid API.
	 *
	 * Note: this is where you may want to alter the Mail object using your own routines.
	 *
	 * handler signature: function(SendGrid\Mail, Swift_Mime_SimpleMessage, SendGridTransport): void
	 *
	 * @var callable[] on-ready event handlers
	 */
	public $onReady = [];

	/**
	 * On-error event handlers.
	 * The event fires when an error occures during the SendGrid API post call.
	 *
	 * handler signature: function(SendGrid\Mail, Swift_Mime_SimpleMessage, SendGrid\Response, SendGridTransport): void
	 *
	 * @var callable[] on-error event handlers
	 */
	public $onError = [];

	/**
	 * On-send event handlers.
	 * The event fires when the Mail has been posted to the SendGrid API.
	 *
	 * handler signature: function(SendGrid\Mail, Swift_Mime_SimpleMessage, SendGrid\Response, SendGridTransport): void
	 *
	 * @var callable[] on-send event handlers
	 */
	public $onSend = [];

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	protected $logger;


	public function __construct($sendGridApiKey)
	{
		$this->sendGridApiKey = $sendGridApiKey;
	}


	public function setLogger(LoggerInterface $logger = null)
	{
		$this->logger = $logger;
	}


	/**
	 * @param Swift_Mime_SimpleMessage $message
	 * @param array              $failedRecipients
	 *
	 * @return int
	 */
	public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
	{
		// create the Mail object
		$mail = $this->prepareMail($message);

		// fire on-ready event
		$this->fire($this->onReady, $mail, $message);

		// send the mail
		$response = $this->sendMail($mail);

		// fire on-send event
		$this->fire($this->onSend, $mail, $message, $response);

		if ($this->responseIsOk($response)) {
			// return the count of recipients
			// Note: it may happen that not all will actually be delivered...
			return count($message->getTo()) + count($message->getCc()) + count($message->getBcc());
		}

		// the API call is not valid, handle the error...
		//
		// fire on-error event
		$this->fire($this->onError, $mail, $message, $response);

		// log
		if ($this->logger !== null) {
			$this->logger->error($response->statusCode() . ': ' . $response->body());
		}

		// failed recipients (all)
		foreach (array_keys(array_merge($message->getTo(), $message->getCc(), $message->getBcc())) as $recipient) {
			$failedRecipients[] = $recipient;
		}
		return 0; // Note: it may happen that some will actually be delivered...
	}


	/**
	 * Convert a Swiftmailer message object into SendGrid Mail object.
	 *
	 * @param Swift_Mime_SimpleMessage $message
	 * @return \SendGrid\Mail
	 */
	public function prepareMail(Swift_Mime_SimpleMessage $message): SendGrid\Mail
	{
		// Get the first from email (SendGrid PHP library only seems to support one)
		$fromArr = $this->mapRecipients($message->getFrom());
		$from = reset($fromArr);

		$toArr = $this->mapRecipients($message->getTo());
		$ccArr = $this->mapRecipients($message->getCc());
		$bccArr = $this->mapRecipients($message->getBcc());
		$attachments = $message->getChildren();

		$to = array_shift($toArr);

		// extract content type from body to prevent multi-part content-type error
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$contentType = $finfo->buffer($message->getBody());
		$content = new SendGrid\Content($contentType, $message->getBody());

		// mail instance
		$mail = new SendGrid\Mail($from, $message->getSubject(), $to, $content);
		$p = new SendGrid\Personalization();

		// more TO recipients
		foreach ($toArr as $recipient) {
			$p->addTo($recipient);
		}

		// CC
		foreach ($ccArr as $email => $name) {
			$p->addCc(new SendGrid\Email($name, $email));
		}

		// BCC
		foreach ($bccArr as $email => $name) {
			$p->addBcc(new SendGrid\Email($name, $email));
		}

		// process attachment
		foreach ($attachments as $att) {
			if ($att instanceof Swift_Mime_Attachment) {
				$sgAttachment = new SendGrid\Attachment();
				$sgAttachment->setContent(base64_encode($att->getBody()));
				$sgAttachment->setType($att->getContentType());
				$sgAttachment->setFilename($att->getFilename());
				$sgAttachment->setDisposition($att->getDisposition());
				$sgAttachment->setContentId($att->getId());
				$mail->addAttachment($sgAttachment);
			} elseif (in_array($att->getContentType(), ['text/plain', 'text/html'])) {
				// add part if any is defined, to avoid error please set body as text and part as html
				$mail->addContent(new SendGrid\Content($att->getContentType(), $att->getBody()));
			}
		}

		// add personalization only if it's not empty (causes a weird 502 Bad Gateway error)
		$p->jsonSerialize() !== null && $mail->addPersonalization($p);

		return $mail;
	}


	public function sendMail(SendGrid\Mail $mail): SendGrid\Response
	{
		return (new SendGrid($this->sendGridApiKey))->client->mail()->send()->post($mail);
	}


	/**
	 * Is the API call response OK?
	 * 2xx responses indicate a successful request.
	 *
	 * @link https://sendgrid.com/docs/API_Reference/Web_API_v3/Mail/errors.html API call status documentation
	 */
	protected function responseIsOk(SendGrid\Response $response): bool
	{
		return
				$response->statusCode() >= 200 &&
				$response->statusCode() < 300
		;
	}


	protected function fire($eventHandlers, ...$args): void
	{
		foreach ($eventHandlers as $handler) {
			call_user_func_array($handler, array_merge($args, [$this]));
		}
	}


	/**
	 * Map swift recipients to SG emails.
	 *
	 * @param array $recipients
	 * @return array
	 */
	private function mapRecipients(array $recipients): array
	{
		return array_map(function($name, $email) {
			return new SendGrid\Email($name, $email);
		}, $recipients, array_keys($recipients));
	}


	public function ping()
	{
		// Not used
		return true;
	}


	public function isStarted()
	{
		// Not used
		return true;
	}


	public function start()
	{
		// Not used
	}


	public function stop()
	{
		// Not used
	}


	public function registerPlugin(Swift_Events_EventListener $plugin)
	{
		// unused
	}

}
