
# SendGrid transport for Swift Mailer

A framework-agnostic SendGrid transport for Swift Mailer.

Installation:

`$` `composer require dakujem/sendgrid-swiftmailer`

Usage:

```php
$transport = new Dakujem\SwiftGrid\SendGridTransport(getenv('SENDGRID_API_KEY'));
$mailer = new Swift_Mailer($transport);
```

For more information, visit:
- [SendGrid documentation]( https://sendgrid.com/docs/index.html )
- [Swift Mailer documentation]( https://swiftmailer.symfony.com/docs/introduction.html )


---

> Note:
>
> The code has been inspired by `expertcoder/swiftmailer-send-grid-bundle`,
> but is more flexible, works with newest SendGrid and Swift Mailer libs and does not require symfony installation.
