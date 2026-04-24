<?php
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

$dotenv = new Dotenv();
$dotenv->load(__DIR__.'/.env');
$dsn = getenv('MAILER_DSN');
$raw = file_get_contents(__DIR__.'/.env');
echo "ENV_EXISTS=" . (file_exists(__DIR__.'/.env') ? 'yes' : 'no') . "\n";
echo "ENV_RAW=" . str_replace("\n", '\\n', $raw) . "\n";
echo "DSN=" . ($dsn ?: '<empty>') . "\n";

try {
    $transport = Transport::fromDsn($dsn);
    $mailer = new Mailer($transport);
    $email = (new Email())
        ->from('maryemsaid.42@gmail.com')
        ->to('maryemsaid.42@gmail.com')
        ->subject('Test SMTP')
        ->text('Test envoi mail');

    $mailer->send($email);
    echo "MAIL_SENT\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
