<?php

// From a disposable EC-CUBE installation with core fixtures and Komoju42 enabled:
// APP_ENV=dev APP_DEBUG=0 DATABASE_URL=sqlite:////tmp/komoju-order-confirmation.sqlite \
// MAILER_DSN=smtp://127.0.0.1:1025 MAILCATCHER_URL=http://127.0.0.1:1080 \
// php /path/to/tests/integration/order_confirmation.php
// Only a local SMTP catcher is allowed. Do not run on a shop.
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
localEndpoint('MAILER_DSN', 'smtp', 1025);
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
// A fresh SQLite connection sees the previous status until the outer transaction commits.
class ShopMailService extends MailService
{
    public static $statusesAtSend = [];
    public static $afterSend;

    public function sendOrderMail(Order $Order)
    {
        $stmt = reader()->prepare('SELECT order_status_id FROM dtb_order WHERE id = ?');
        $stmt->execute([$Order->getId()]);
        self::$statusesAtSend[$Order->getId()][] = (int) $stmt->fetchColumn();
        $message = parent::sendOrderMail($Order);
        if (self::$afterSend !== null) {
            (self::$afterSend)();
        }
        return $message;
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
    private $persistent;

    public function __construct(bool $persistent)
    {
        $this->persistent = $persistent;
        parent::__construct('dev', false);
    }

    public function getProjectDir(): string { return getcwd(); }
    public function getCacheDir(): string { return parent::getCacheDir() . '-confirmation' . ($this->persistent ? '-persistent' : ''); }
    protected function getContainerClass(): string { return parent::getContainerClass() . ($this->persistent ? 'Persistent' : ''); }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader)
    {
        parent::configureContainer($container, $loader);
        $container->getDefinition(MailService::class)->setClass(ShopMailService::class);
        $container->getDefinition(KomojuClientFactory::class)->setClass(LocalClientFactory::class);
        if ($this->persistent) {
            $container->prependExtensionConfig('doctrine', ['dbal' => ['options' => [PDO::ATTR_PERSISTENT => true]]]);
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
function callback(string $name, int $id, string $type, array $options = []): void
{
    $kernel = new ConfirmationKernel($options['persistent'] ?? false);
    $kernel->boot();
    $services = $kernel->getContainer();
    $conn = $services->get('doctrine')->getConnection();
    expect(DB, $conn->fetchOne('SELECT file FROM pragma_database_list WHERE name = \'main\''), 'active application database');
    $events = $services->get('event_dispatcher');
    ShopMailService::$afterSend = isset($options['after_send']) ? static function () use ($options, $conn) {
        $options['after_send']($conn);
    } : null;
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
    if (isset($options['response'])) {
        $events->addListener(KernelEvents::RESPONSE, static function () use ($options, $conn) {
            $options['response']($conn);
        });
    }
    if (isset($options['terminate'])) {
        $events->addListener(KernelEvents::TERMINATE, static function () use ($options, $conn) {
            $options['terminate']($conn);
        }, -64);
    }
    try {
        if (isset($options['before'])) {
            $options['before']($conn);
        }
        $response = $kernel->handle($request, Kernel::MAIN_REQUEST, false);
        try {
            $kernel->terminate($request, $response);
        } finally {
            if (isset($options['after'])) {
                $options['after']($conn, $services);
            }
        }
        if ($type === 'return') {
            expect(302, $response->getStatusCode(), "$name redirect");
            expect($services->get('router')->generate('shopping_complete'), parse_url($response->headers->get('Location'), PHP_URL_PATH), "$name completion route");
        } else {
            expect(200, $response->getStatusCode(), "$name HTTP");
            expect(['status' => 'success'], json_decode($response->getContent(), true), "$name webhook acknowledgment");
        }
    } finally {
        ShopMailService::$afterSend = null;
        // Simulate PHP request shutdown before a persistent PDO handle is reused.
        if ($conn->isTransactionActive()) {
            while ($conn->getTransactionNestingLevel() > 1) {
                $conn->rollBack();
            }
            $conn->rollBack();
            $conn->setAutoCommit(true);
        }
        $kernel->shutdown();
        $conn->close();
    }
}

function checkOrder(int $id, int $status, array $sends, int $history): void
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
    expect($sends, ShopMailService::$statusesAtSend[$id] ?? [], "$name committed status seen by shop MailService on each call");
    expect($history, (int) $row['history'], "$name durable mail history");
    checkMail($row['email'], $name, count($sends));
}

$db = reader();
expect(0, (int) $db->query('SELECT COUNT(*) FROM dtb_order')->fetchColumn(), 'fresh fixture DB with no orders');
expect(0, (int) $db->query("SELECT COUNT(*) FROM plg_komoju_config WHERE COALESCE(secret_key, '') <> '' OR COALESCE(merchant_uuid, '') <> ''")->fetchColumn(), 'no merchant credentials');
$config = $db->prepare("UPDATE plg_komoju_config SET secret_key = 'local-only', webhook_secret = ?");
$config->execute([SECRET]);
expect(1, $config->rowCount(), 'one installed plugin config');
$db->exec("INSERT INTO dtb_base_info (id, shop_name, update_date, discriminator_type) VALUES (1, 'Fixture shop', CURRENT_TIMESTAMP, 'baseinfo') ON CONFLICT(id) DO NOTHING");
$db->exec("UPDATE dtb_base_info SET shop_name='Fixture shop', email01='shop@example.test', email02='shop@example.test', email03='shop@example.test', email04='shop@example.test' WHERE id=1");

foreach (['captured', 'return'] as $type) {
    $name = 'rollback-' . $type;
    $id = seedOrder($name);
    callback($name, $id, $type, ['response' => static function ($conn) use ($id) {
        expect(OrderStatus::PAID, (int) $conn->fetchOne('SELECT order_status_id FROM dtb_order WHERE id = ?', [$id]), 'before outer rollback');
        $conn->setRollbackOnly();
    }]);
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

$name = 'persistent-nested';
$id = seedOrder($name);
callback($name, $id, 'captured', [
    'persistent' => true,
    'response' => static function ($conn) use ($id) {
        expect(true, $conn->getNativeConnection()->getAttribute(PDO::ATTR_PERSISTENT), 'persistent PDO enabled');
        expect(1, $conn->getTransactionNestingLevel(), 'request root transaction');
        $conn->beginTransaction();
        expect(2, $conn->getTransactionNestingLevel(), 'extra transaction outstanding through RESPONSE');
        checkOrder($id, OrderStatus::PENDING, [], 0);
    },
    'after' => static function ($conn) use ($id) {
        expect(1, $conn->getTransactionNestingLevel(), 'real commit still outstanding');
        expect(OrderStatus::PAID, (int) $conn->fetchOne('SELECT order_status_id FROM dtb_order WHERE id = ?', [$id]), 'capture remains uncommitted');
        checkOrder($id, OrderStatus::PENDING, [], 0);
        $conn->rollBack();
        $conn->setAutoCommit(true); // Close only the empty transaction reopened by this rollback.
        expect(false, $conn->isTransactionActive(), 'extra transaction cleaned up');
    },
]);
checkOrder($id, OrderStatus::PENDING, [], 0);
callback($name, $id, 'captured', ['persistent' => true]);
callback($name, $id, 'captured', ['persistent' => true]);
checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 1);

$name = 'commit-failure';
$id = seedOrder($name);
$db->exec('CREATE TABLE fixture_deferred_commit (order_id INTEGER REFERENCES dtb_order (id) DEFERRABLE INITIALLY DEFERRED)');
try {
    $failed = false;
    try {
        callback($name, $id, 'captured', [
            'before' => static function ($conn) {
                $conn->executeStatement('PRAGMA foreign_keys = ON');
                expect(1, (int) $conn->fetchOne('PRAGMA foreign_keys'), 'deferred foreign keys enabled');
            },
            'response' => static function ($conn) {
                $conn->executeStatement('INSERT INTO fixture_deferred_commit (order_id) VALUES (-1)');
            },
            'after' => static function ($conn) use ($id) {
                expect(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM fixture_deferred_commit'), 'failed commit was rolled back');
                checkOrder($id, OrderStatus::PENDING, [], 0);
            },
        ]);
    } catch (\Throwable $e) {
        expect(true, strpos($e->getMessage(), 'FOREIGN KEY constraint failed') !== false, 'root commit failure propagated');
        $failed = true;
    }
    expect(true, $failed, 'root commit failed');
} finally {
    $db->exec('DROP TABLE fixture_deferred_commit');
}
checkOrder($id, OrderStatus::PENDING, [], 0);
callback($name, $id, 'captured');
checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 1);

$name = 'late-listener';
$id = seedOrder($name);
callback($name, $id, 'captured', [
    'terminate' => static function ($conn) use ($id) {
        checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 1);
        expect(1, $conn->getTransactionNestingLevel(), 'core empty transaction restored before later listeners');
        $conn->executeStatement("UPDATE dtb_base_info SET shop_name = 'Late listener' WHERE id = 1");
    },
    'after' => static function ($conn) {
        expect(1, $conn->getTransactionNestingLevel(), 'later listener transaction remains open');
        expect('Late listener', $conn->fetchOne('SELECT shop_name FROM dtb_base_info WHERE id = 1'), 'later listener wrote its change');
        expect('Fixture shop', reader()->query('SELECT shop_name FROM dtb_base_info WHERE id = 1')->fetchColumn(), 'later listener change is not committed');
        $conn->rollBack();
        $conn->setAutoCommit(true);
        expect('Fixture shop', $conn->fetchOne('SELECT shop_name FROM dtb_base_info WHERE id = 1'), 'later listener change rolled back');
    },
]);
checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 1);

$cleanMailTransaction = static function ($conn, $services) {
    // Only EC-CUBE's fresh, empty transaction may remain, never the failed mail transaction.
    expect(1, $conn->getTransactionNestingLevel(), 'core empty transaction restored after mail failure');
    expect(false, $conn->isAutoCommit(), 'core autocommit setting restored');
    expect(false, $conn->isRollbackOnly(), 'no rollback-only mail transaction remains');
    expect(true, $services->get('doctrine')->getManager()->isOpen(), 'usable entity manager after mail failure');
    $conn->rollBack();
    $conn->setAutoCommit(true);
    expect(false, $conn->isTransactionActive(), 'restored transaction cleaned up');
    expect(false, $conn->getNativeConnection()->inTransaction(), 'native transaction cleaned up');
};

$name = 'history-failure';
$id = seedOrder($name);
$db->exec("CREATE TRIGGER reject_fixture_history BEFORE INSERT ON dtb_mail_history BEGIN SELECT RAISE(ABORT, 'forced mail history failure'); END");
try {
    callback($name, $id, 'captured', ['after' => $cleanMailTransaction]);
    checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 0);
} finally {
    $db->exec('DROP TRIGGER reject_fixture_history');
}

$name = 'mail-nested-failure';
$id = seedOrder($name);
callback($name, $id, 'captured', [
    'after_send' => static function ($conn) { $conn->beginTransaction(); },
    'after' => $cleanMailTransaction,
]);
checkOrder($id, OrderStatus::PAID, [OrderStatus::PAID], 0);

echo 'Order confirmations passed on EC-CUBE ' . Constant::VERSION . PHP_EOL;
