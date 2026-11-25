<?php

// From a disposable EC-CUBE installation with core fixtures and Komoju42 enabled:
// TMPDIR=/tmp APP_ENV=dev APP_DEBUG=0 DATABASE_URL=sqlite:////tmp/komoju-order-confirmation.sqlite \
// MAILER_DSN=smtp://127.0.0.1:1025 MAILCATCHER_URL=http://127.0.0.1:1080 \
// php /path/to/tests/integration/order_confirmation.php
// Only a local SMTP catcher is allowed. Do not run on a shop.
namespace KomojuConfirmationRegression;

use Eccube\Common\Constant;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Kernel;
use Eccube\Service\MailService;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Plugin\Komoju42\Service\KomojuClientFactory;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;

define(__NAMESPACE__ . '\\DB', sys_get_temp_dir() . '/komoju-order-confirmation.sqlite');
const SECRET = 'disposable-webhook-secret';
const STATE = 'disposable-browser-state';
const AMOUNT = 1200;

function expect($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException("$label: expected " . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

function localEndpoint(string $name, string $scheme, int $port): string
{
    $url = getenv($name) ?: "$scheme://127.0.0.1:$port";
    $allowed = array_map(static function ($host) use ($scheme, $port) {
        return "$scheme://$host:$port";
    }, ['127.0.0.1', 'localhost', '[::1]', 'mailcatcher']);
    expect(true, in_array($url, $allowed, true), "$name must use the local catcher");
    putenv("$name=$url");
    $_SERVER[$name] = $_ENV[$name] = $url;
    return $url;
}

function catcher(string $path): string
{
    global $catcherUrl;
    $body = file_get_contents($catcherUrl . $path, false, stream_context_create([
        'http' => ['timeout' => 5, 'follow_location' => 0],
    ]));
    expect(true, is_string($body), "MailCatcher GET $path");
    return $body;
}

expect('cli', PHP_SAPI, 'CLI only');
expect('dev', getenv('APP_ENV'), 'APP_ENV');
expect('0', getenv('APP_DEBUG'), 'APP_DEBUG');
expect('sqlite:///' . DB, getenv('DATABASE_URL'), 'disposable DATABASE_URL');
expect(DB, realpath(DB), 'existing, non-symlinked validation DB');
$mailerDsn = localEndpoint('MAILER_DSN', 'smtp', 1025);
$catcherUrl = localEndpoint('MAILCATCHER_URL', 'http', 1080);
json_decode(catcher('/messages'), true, 512, JSON_THROW_ON_ERROR);
require getcwd() . '/vendor/autoload.php';

function reader(): PDO
{
    return new PDO('sqlite:' . DB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function checkMail(string $email, string $name, int $expected): void
{
    // Unique recipients scope this run without deleting anyone else's inbox messages.
    $messages = array_filter(json_decode(catcher('/messages'), true, 512, JSON_THROW_ON_ERROR), static function ($message) use ($email) {
        return in_array('<' . $email . '>', $message['recipients'], true);
    });
    expect($expected, count($messages), "$name actual SMTP messages");
    foreach ($messages as $message) {
        $recipients = $message['recipients'];
        $envelope = ['<' . $email . '>', '<shop@example.test>'];
        sort($recipients);
        sort($envelope);
        expect($envelope, $recipients, "$name SMTP recipients including shop Bcc");
        expect('<shop@example.test>', $message['sender'], "$name SMTP sender");
        expect(0, strpos($message['subject'], '[Fixture shop] '), "$name mail subject");
        $source = catcher('/messages/' . $message['id'] . '.source');
        foreach (['multipart/alternative', 'text/plain', 'text/html'] as $type) {
            expect(true, stripos($source, 'Content-Type: ' . $type) !== false, "$name MIME $type");
        }
        foreach (['plain', 'html'] as $format) {
            $body = catcher('/messages/' . $message['id'] . '.' . $format);
            foreach ([$name, $email, 'Test Customer', 'KOMOJU', '1,200'] as $content) {
                expect(true, strpos($body, $content) !== false, "$name $format body contains $content");
            }
        }
    }
}

// This is a shop replacement for the core service ID, not a MAIL_ORDER listener.
class ShopMailService extends MailService
{
    public static $statusesAtSend = [];

    public function sendOrderMail(Order $Order)
    {
        self::$statusesAtSend[$Order->getId()][] = (int) $Order->getOrderStatus()->getId();
        return parent::sendOrderMail($Order);
    }
}

class LocalClientFactory extends KomojuClientFactory
{
    public function create($secret_key)
    {
        return new class {
            public function getSession($id)
            {
                return [
                    'id' => $id, 'status' => 'completed', 'amount' => AMOUNT, 'currency' => 'JPY',
                    'payment' => ['id' => 'p-' . $id, 'status' => 'captured', 'amount' => AMOUNT, 'currency' => 'JPY'],
                ];
            }

            public function getStatusCode() { return 200; }
        };
    }
}

class ConfirmationKernel extends Kernel
{
    private $smtpFailure;

    public function __construct(bool $smtpFailure)
    {
        $this->smtpFailure = $smtpFailure;
        parent::__construct('dev', false);
    }

    public function getProjectDir(): string { return getcwd(); }
    public function getCacheDir(): string { return parent::getCacheDir() . '-confirmation-inline' . ($this->smtpFailure ? '-smtp-failure' : ''); }
    protected function getContainerClass(): string { return parent::getContainerClass() . ($this->smtpFailure ? 'SmtpFailure' : ''); }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader)
    {
        parent::configureContainer($container, $loader);
        $container->getDefinition(MailService::class)->setClass(ShopMailService::class);
        $container->getDefinition(KomojuClientFactory::class)->setClass(LocalClientFactory::class);
        if ($this->smtpFailure) {
            $container->register('confirmation.smtp_failures', TestHandler::class)
                ->setArguments([Logger::CRITICAL])->setPublic(true);
            $container->loadFromExtension('monolog', ['handlers' => [
                'confirmation_smtp_failures' => ['type' => 'service', 'id' => 'confirmation.smtp_failures'],
            ]]);
        }
    }
}

function seedOrder(string $name): int
{
    static $run;
    $run = $run ?? bin2hex(random_bytes(6));
    $db = reader();
    $stmt = $db->prepare("INSERT INTO dtb_order (order_no, name01, name02, email, pref_id, order_status_id,
        subtotal, total, payment_total, currency_code, payment_method, create_date, update_date, order_date, discriminator_type)
        VALUES (?, 'Test', 'Customer', ?, 1, ?, ?, ?, ?, 'JPY', 'KOMOJU', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'order')");
    $stmt->execute([$name, Constant::VERSION . '-' . $run . '-' . $name . '@example.test', OrderStatus::PENDING, AMOUNT, AMOUNT, AMOUNT]);
    $id = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO plg_komoju_order (order_id, komoju_session_id, expected_amount, expected_currency, callback_token_hash, created_at)
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)')->execute([$id, $name, AMOUNT, 'JPY', hash('sha256', STATE)]);
    return $id;
}

// Boot a fresh request container each time, as separate PHP web requests would.
function callback(string $name, int $id, string $type, bool $smtpFailure = false): void
{
    $loggerFacade = new \ReflectionProperty(\Eccube\DependencyInjection\Facade\LoggerFacade::class, 'instance');
    $loggerFacade->setAccessible(true);
    $loggerFacade->setValue(null, null);
    $kernel = new ConfirmationKernel($smtpFailure);
    $kernel->boot();
    $services = $kernel->getContainer();
    $conn = $services->get('doctrine')->getConnection();
    expect(DB, $conn->fetchOne('SELECT file FROM pragma_database_list WHERE name = \'main\''), 'active application database');
    $events = $services->get('event_dispatcher');
    if ($type === 'return') {
        $request = Request::create('/plugin/Komoju42/session/return', 'GET', ['session_id' => $name, 'state' => STATE]);
        // Seed the actual EC-CUBE checkout session before the real controller runs.
        $events->addListener(KernelEvents::CONTROLLER, static function () use ($request, $name) {
            $request->getSession()->set('komoju.callback.' . $name, hash('sha256', STATE));
        });
    } else {
        $payload = json_encode(['type' => 'payment.' . $type, 'data' => [
            'id' => 'p-' . $name, 'session' => $name, 'amount' => AMOUNT, 'currency' => 'JPY',
            'metadata' => ['eccube_order_id' => $id], 'payment_details' => ['type' => 'credit_card'],
        ]], JSON_THROW_ON_ERROR);
        $request = Request::create('/plugin/Komoju42/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_KOMOJU_SIGNATURE' => hash_hmac('sha256', $payload, SECRET),
        ], $payload);
    }
    try {
        $response = $kernel->handle($request, Kernel::MAIN_REQUEST, false);
        $kernel->terminate($request, $response);
        if ($type === 'return') {
            expect(302, $response->getStatusCode(), "$name redirect");
            expect($services->get('router')->generate('shopping_complete'), parse_url($response->headers->get('Location'), PHP_URL_PATH), "$name completion route");
        } else {
            expect(200, $response->getStatusCode(), "$name HTTP");
            expect(['status' => 'success'], json_decode($response->getContent(), true), "$name webhook acknowledgment");
        }
        if ($smtpFailure) {
            $address = substr(getenv('MAILER_DSN'), strlen('smtp://'));
            expect(true, $services->get('confirmation.smtp_failures')->hasCriticalThatContains($address), "$name core logged the real SMTP connection failure");
        }
    } finally {
        $kernel->shutdown();
        $conn->close();
    }
}

function checkOrder(int $id, int $status, array $sends, int $received): void
{
    $q = reader()->prepare('SELECT o.order_status_id, o.payment_date, o.email, k.captured_at, k.captured_amount, k.komoju_payment_id, k.komoju_session_id,
        (SELECT COUNT(*) FROM dtb_mail_history WHERE order_id = o.id) AS history
        FROM dtb_order o JOIN plg_komoju_order k ON k.order_id = o.id WHERE o.id = ?');
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $name = $row['komoju_session_id'];
    $paid = $status === OrderStatus::PAID;
    expect($status, (int) $row['order_status_id'], "$name durable order status");
    expect($paid, $row['payment_date'] !== null, "$name payment date");
    expect($paid, $row['captured_at'] !== null, "$name capture date");
    expect($paid ? AMOUNT : null, $row['captured_amount'] === null ? null : (int) $row['captured_amount'], "$name captured amount");
    expect($status === OrderStatus::PENDING ? null : 'p-' . $name, $row['komoju_payment_id'], "$name durable payment id");
    expect($sends, ShopMailService::$statusesAtSend[$id] ?? [], "$name entity status seen by injected shop MailService on each call");
    expect(count($sends), (int) $row['history'], "$name mail history (not proof of delivery)");
    checkMail($row['email'], $name, $received);
}

$db = reader();
expect(0, (int) $db->query('SELECT COUNT(*) FROM dtb_order')->fetchColumn(), 'fresh fixture DB with no orders');
expect(0, (int) $db->query("SELECT COUNT(*) FROM plg_komoju_config WHERE COALESCE(secret_key, '') <> '' OR COALESCE(merchant_uuid, '') <> ''")->fetchColumn(), 'no merchant credentials');
$config = $db->prepare("UPDATE plg_komoju_config SET secret_key = 'local-only', webhook_secret = ?");
$config->execute([SECRET]);
expect(1, $config->rowCount(), 'one installed plugin config');
$db->exec("INSERT INTO dtb_base_info (id, shop_name, update_date, discriminator_type) VALUES (1, 'Fixture shop', CURRENT_TIMESTAMP, 'baseinfo') ON CONFLICT(id) DO NOTHING");
$db->exec("UPDATE dtb_base_info SET shop_name='Fixture shop', email01='shop@example.test', email02='shop@example.test', email03='shop@example.test', email04='shop@example.test' WHERE id=1");

foreach (['return', 'captured'] as $first) {
    $name = $first . '-first';
    $id = seedOrder($name);
    callback($name, $id, $first);
    checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 1);
    callback($name, $id, $first);
    callback($name, $id, $first === 'return' ? 'captured' : 'return');
    checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 1);
}

$name = 'authorize-capture';
$id = seedOrder($name);
callback($name, $id, 'authorized');
checkOrder($id, OrderStatus::NEW, [OrderStatus::NEW], 1);
callback($name, $id, 'authorized');
checkOrder($id, OrderStatus::NEW, [OrderStatus::NEW], 1);
callback($name, $id, 'captured');
checkOrder($id, OrderStatus::PAID, [OrderStatus::NEW], 1);

$name = 'rejected-amount';
$id = seedOrder($name);
$db->prepare('UPDATE plg_komoju_order SET expected_amount = ? WHERE order_id = ?')->execute([AMOUNT + 1, $id]);
callback($name, $id, 'captured');
checkOrder($id, OrderStatus::PENDING, [], 0);

// Reserve an ephemeral loopback port without listening: real connection refusal,
// without assuming a fixed port is unused or racing another process to bind it.
$refusedSmtp = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
expect(true, is_resource($refusedSmtp), 'reserve non-listening SMTP socket: ' . $error);
$smtpAddress = stream_socket_get_name($refusedSmtp, false);
$name = 'smtp-failure';
$id = seedOrder($name);
try {
    $dsn = 'smtp://' . $smtpAddress;
    putenv("MAILER_DSN=$dsn");
    $_SERVER['MAILER_DSN'] = $_ENV['MAILER_DSN'] = $dsn;
    // A separate container/cache avoids reusing the working transport. The catcher
    // API stays reachable, so zero received messages remains a real assertion.
    callback($name, $id, 'captured', true);
    checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 0);
} finally {
    putenv("MAILER_DSN=$mailerDsn");
    $_SERVER['MAILER_DSN'] = $_ENV['MAILER_DSN'] = $mailerDsn;
    fclose($refusedSmtp);
}
// Core can record mail history despite transport failure; callbacks do not retry mail.
callback($name, $id, 'captured');
callback($name, $id, 'return');
checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 0);

echo 'Order confirmations passed on EC-CUBE ' . Constant::VERSION . PHP_EOL;
