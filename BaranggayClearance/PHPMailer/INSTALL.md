# PHPMailer Installation

Download PHPMailer from: https://github.com/PHPMailer/PHPMailer

Option A — Manual:
1. Download the repository ZIP from GitHub
2. Extract and copy the three files from `src/` into this `PHPMailer/src/` folder:
   - PHPMailer.php
   - SMTP.php
   - Exception.php

Option B — Composer (recommended):
```
composer require phpmailer/phpmailer
```
Then in mailer.php change the require lines to: require_once 'vendor/autoload.php';

After installing, open mailer.php and set:
- MAIL_HOST       → your SMTP host (e.g. smtp.gmail.com)
- MAIL_USERNAME   → your email
- MAIL_PASSWORD   → your Gmail App Password (not your normal password)
- MAIL_FROM       → sender address
- MAIL_FROM_NAME  → display name

For Gmail: enable 2FA, then generate an App Password at:
https://myaccount.google.com/apppasswords
