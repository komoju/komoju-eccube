<?php

// From a disposable EC-CUBE installation with core fixtures and Komoju42 enabled:
// APP_ENV=dev DATABASE_URL=sqlite:////tmp/komoju-order-confirmation.sqlite \
// MAILER_DSN=smtp://mailcatcher:1025 php /path/to/tests/integration/order_confirmation.php
// Use a local SMTP catcher named mailcatcher (four messages per run). Do not run on a shop.
namespace KomojuConfirmationRegression;

use Eccube\Common\Constant;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Kernel;
use Eccube\Service\MailService;
use PDO;
use Plugin\Komoju42\Service\KomojuClientFactory;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;

const DB = '/tmp/komoju-order-confirmation.sqlite';
const SECRET = 'disposable-webhook-secret';
const STATE = 'disposable-browser-state';
const AMOUNT = 1200;

function expect($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException("$label: expected " . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

expect('cli', PHP_SAPI, 'CLI only');
expect('dev', getenv('APP_ENV'), 'APP_ENV');
expect('sqlite:///' . DB, getenv('DATABASE_URL'), 'disposable DATABASE_URL');
expect(DB, realpath(DB), 'existing, non-symlinked validation DB');
expect('smtp://mailcatcher:1025', getenv('MAILER_DSN'), 'local SMTP catcher');
require getcwd() . '/vendor/autoload.php';

function reader(): PDO
{
    return new PDO('sqlite:' . DB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

// This is a shop replacement for the core service ID, not a MAIL_ORDER listener.
// A fresh SQLite connection sees the previous status until the outer transaction commits.
class ShopMailService extends MailService
{
    public static $statusesAtSend = [];

    public function sendOrderMail(Order $Order)
    {
        $stmt = reader()->prepare('SELECT order_status_id FROM dtb_order WHERE id = ?');
        $stmt->execute([$Order->getId()]);
        self::$statusesAtSend[$Order->getId()][] = (int) $stmt->fetchColumn();
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
    public function getProjectDir(): string { return getcwd(); }
    public function getCacheDir(): string { return parent::getCacheDir() . '-confirmation'; }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader)
    {
        parent::configureContainer($container, $loader);
        $container->getDefinition(MailService::class)->setClass(ShopMailService::class);
        $container->getDefinition(KomojuClientFactory::class)->setClass(LocalClientFactory::class);
    }
}

function seedOrder(string $name): int
{
    $db = reader();
    $stmt = $db->prepare("INSERT INTO dtb_order (order_no, name01, name02, email, pref_id, order_status_id,
        subtotal, total, payment_total, currency_code, payment_method, create_date, update_date, order_date, discriminator_type)
        VALUES (?, 'Test', 'Customer', ?, 1, ?, ?, ?, ?, 'JPY', 'KOMOJU', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'order')");
    $stmt->execute([$name, Constant::VERSION . '-' . $name . '@example.test', OrderStatus::PENDING, AMOUNT, AMOUNT, AMOUNT]);
    $id = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO plg_komoju_order (order_id, komoju_session_id, expected_amount, expected_currency, callback_token_hash, created_at)
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)')->execute([$id, $name, AMOUNT, 'JPY', hash('sha256', STATE)]);
    return $id;
}

// Boot a fresh request container each time, as separate PHP web requests would.
function callback(string $name, int $id, string $type, bool $rollback = false): void
{
    $kernel = new ConfirmationKernel('dev', false);
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
    if ($rollback) {
        $events->addListener(KernelEvents::RESPONSE, static function () use ($conn, $id) {
            expect(OrderStatus::PAID, (int) $conn->fetchOne('SELECT order_status_id FROM dtb_order WHERE id = ?', [$id]), 'before outer rollback');
            $conn->setRollbackOnly();
        });
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
    } finally {
        $kernel->shutdown();
        $conn->close();
    }
}

function checkOrder(int $id, int $status, array $sends, int $history): void
{
    $q = reader()->prepare('SELECT o.order_status_id, o.payment_date, k.captured_at, k.captured_amount, k.komoju_payment_id, k.komoju_session_id,
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
    expect($sends, ShopMailService::$statusesAtSend[$id] ?? [], "$name committed status seen by shop MailService on each call");
    expect($history, (int) $row['history'], "$name durable mail history");
}

$db = reader();
expect(0, (int) $db->query('SELECT COUNT(*) FROM dtb_order')->fetchColumn(), 'fresh fixture DB with no orders');
expect(0, (int) $db->query("SELECT COUNT(*) FROM plg_komoju_config WHERE COALESCE(secret_key, '') <> '' OR COALESCE(merchant_uuid, '') <> ''")->fetchColumn(), 'no merchant credentials');
$config = $db->prepare("UPDATE plg_komoju_config SET secret_key = 'local-only', webhook_secret = ?");
$config->execute([SECRET]);
expect(1, $config->rowCount(), 'one installed plugin config');
$db->exec("INSERT INTO dtb_base_info (id, shop_name, update_date, discriminator_type) VALUES (1, 'Fixture shop', CURRENT_TIMESTAMP, 'baseinfo') ON CONFLICT(id) DO NOTHING");
$db->exec("UPDATE dtb_base_info SET email01='shop@example.test', email02='shop@example.test', email03='shop@example.test', email04='shop@example.test' WHERE id=1");

foreach (['captured', 'return'] as $type) {
    $name = 'rollback-' . $type;
    $id = seedOrder($name);
    callback($name, $id, $type, true);
    checkOrder($id, OrderStatus::PENDING, [], 0);
    callback($name, $id, $type);
    callback($name, $id, 'captured');
    checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 1);
}

$name = 'authorize-capture';
$id = seedOrder($name);
callback($name, $id, 'authorized');
checkOrder($id, OrderStatus::NEW, [OrderStatus::NEW], 1);
callback($name, $id, 'authorized');
callback($name, $id, 'captured');
checkOrder($id, OrderStatus::PAID, [OrderStatus::NEW], 1);

$name = 'history-failure';
$id = seedOrder($name);
$db->exec("CREATE TRIGGER reject_fixture_history BEFORE INSERT ON dtb_mail_history BEGIN SELECT RAISE(ABORT, 'forced mail history failure'); END");
try {
    callback($name, $id, 'captured');
    checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 0);
} finally {
    $db->exec('DROP TRIGGER reject_fixture_history');
}

echo 'Order confirmations passed on EC-CUBE ' . Constant::VERSION . PHP_EOL;
