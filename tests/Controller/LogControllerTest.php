<?php

namespace Tests\Komoju42\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use PHPUnit\Framework\TestCase;
use Plugin\Komoju42\Controller\Admin\LogController;
use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\Entity\KomojuLog;
use Plugin\Komoju42\Repository\KomojuLogRepository;
use Plugin\Komoju42\Service\LogService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogControllerTest extends TestCase
{
    private $entityManager;
    private $queryBuilder;
    private $query;
    private $form;
    private $controller;
    private $config;

    protected function setUp(): void
    {
        $this->query = $this->mockMethods(['toIterable']);
        $this->queryBuilder = $this->mockMethods([
            'orderBy', 'andWhere', 'setParameter', 'setMaxResults', 'getQuery',
        ]);
        foreach (['orderBy', 'andWhere', 'setParameter', 'setMaxResults'] as $method) {
            $this->queryBuilder->method($method)->willReturnSelf();
        }
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

        $repository = $this->getMockBuilder(KomojuLogRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['createQueryBuilder'])
            ->getMock();
        $repository->method('createQueryBuilder')->with('s')->willReturn($this->queryBuilder);
        $this->entityManager = $this->getMockBuilder(EntityManagerInterface::class)
            ->onlyMethods(get_class_methods(EntityManagerInterface::class))
            ->addMethods(['detach'])
            ->getMock();
        $configRepository = $this->mockMethods(['findOneBy']);
        $configRepository->method('findOneBy')->willReturnCallback(function () {
            return $this->config;
        });
        $this->entityManager->method('getRepository')->with(KomojuConfig::class)->willReturn($configRepository);
        $this->form = $this->mockMethods([
            'handleRequest', 'isSubmitted', 'isValid', 'getData', 'setData', 'createView',
        ]);
        $this->form->method('createView')->willReturn('form-view');

        $this->controller = new TestableLogController($repository, $this->entityManager, new LogService($this->entityManager));
        $this->controller->searchForm = $this->form;
        $this->controller->eccubeConfig = $this->mockMethods(['get']);
        $this->controller->eccubeConfig->method('get')
            ->with('eccube_default_page_count')->willReturn(20);
    }

    public function testInvalidPostKeepsSavedFiltersWithoutMutatingSession(): void
    {
        $dateTo = new \DateTime('2026-10-03');
        $saved = ['order_id' => '42', 'date_to' => $dateTo, 'keyword' => 'saved 50%!_'];
        $session = new Session();
        $session->set('komoju_log_search', $saved);
        $request = $this->request('POST', $session);
        $this->form->expects($this->once())->method('handleRequest')->with($request);
        $this->form->method('isSubmitted')->willReturn(true);
        $this->form->method('isValid')->willReturn(false);
        $this->form->expects($this->never())->method('getData');
        $this->form->expects($this->never())->method('setData');
        $this->queryBuilder->expects($this->exactly(3))->method('setParameter')
            ->withConsecutive(
                ['order_id', '42'],
                ['date_to', new \DateTime('2026-10-04')],
                ['keyword', '%saved 50!%!!!_%']
            );

        $result = $this->controller->index($request, $this->expectIndex(3), 3);

        $this->assertSame(['komoju_log_search' => $saved], $session->all());
        $this->assertSame('2026-10-03', $dateTo->format('Y-m-d'));
        $this->assertSame(['page' => 3], $result['pagination']);
    }

    public function testInvalidPostWithoutSavedFiltersUsesUnfilteredQuery(): void
    {
        $session = new Session();
        $request = $this->request('POST', $session);
        $this->form->expects($this->once())->method('handleRequest')->with($request);
        $this->form->method('isSubmitted')->willReturn(true);
        $this->form->method('isValid')->willReturn(false);
        $this->form->expects($this->never())->method('getData');
        $this->queryBuilder->expects($this->never())->method('andWhere');
        $this->queryBuilder->expects($this->never())->method('setParameter');

        $this->controller->index($request, $this->expectIndex(1));

        $this->assertSame([], $session->all());
    }

    public function testValidPostReplacesSavedFiltersAndResetsPage(): void
    {
        $session = new Session();
        $session->set('komoju_log_search', ['keyword' => 'old']);
        $search = ['keyword' => 'new'];
        $request = $this->request('POST', $session);
        $this->form->expects($this->once())->method('handleRequest')->with($request);
        $this->form->method('isSubmitted')->willReturn(true);
        $this->form->method('isValid')->willReturn(true);
        $this->form->method('getData')->willReturn($search);
        $this->queryBuilder->expects($this->once())->method('setParameter')
            ->with('keyword', '%new%');

        $this->controller->index($request, $this->expectIndex(1), 8);

        $this->assertSame($search, $session->get('komoju_log_search'));
    }

    public function testGetPurgesExpiredOperationalLogsAndKeepsSavedFilters(): void
    {
        $this->config = (new KomojuConfig())->setLogRetentionDays(30);
        $database = new \PDO('sqlite::memory:');
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $database->exec('CREATE TABLE plg_komoju_log (id INTEGER PRIMARY KEY, created_at TEXT, is_protected INTEGER)');
        $insert = $database->prepare('INSERT INTO plg_komoju_log VALUES (?, ?, ?)');
        $old = (new \DateTimeImmutable('-1 year'))->format('Y-m-d H:i:s');
        $future = (new \DateTimeImmutable('+1 year'))->format('Y-m-d H:i:s');
        $insert->execute([1, $old, 0]);
        $insert->execute([2, $old, 1]);
        $insert->execute([3, $future, 0]);
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function ($sql, $parameters) use ($database) {
            $statement = $database->prepare($sql);
            $statement->execute($parameters);
            return $statement->rowCount();
        });
        $this->entityManager->method('getConnection')->willReturn($connection);

        $session = new Session();
        $saved = ['keyword' => 'saved'];
        $session->set('komoju_log_search', $saved);
        $this->form->expects($this->once())->method('setData')->with($saved);
        $this->form->expects($this->never())->method('handleRequest');

        $this->controller->index($this->request('GET', $session), $this->expectIndex(2), 2);

        $remainingIds = $database->query('SELECT id FROM plg_komoju_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([2, 3], array_map('intval', $remainingIds));
        $this->assertSame($saved, $session->get('komoju_log_search'));
    }


    public function testExportProducesSafeRoundTrippableCsvAndDetachesRows(): void
    {
        $cases = [
            ['=1+1', "'=1+1"],
            ['+SUM(1,2)', "'+SUM(1,2)"],
            ['-42', "'-42"],
            ['@SUM(1,2)', "'@SUM(1,2)"],
            ["\ttext", "'\ttext"],
            ["\rtext", "'\rtext"],
            ["\ntext", "'\ntext"],
            [" \t\r=1+1", "' \t\r=1+1"],
            ["\x00\x1F+1", "'\x00\x1F+1"],
            ["\u{00A0}\u{200B}@SUM(1,2)", "'\u{00A0}\u{200B}@SUM(1,2)"],
            ["\u{3000}=2", "'\u{3000}=2"],
            ["=1+\xFF", "'=1+\xFF"],
            ['ordinary 注文 text', 'ordinary 注文 text'],
            ['  ordinary text  ', '  ordinary text  '],
            ["path \\\", \"quoted\"\n続き", "path \\\", \"quoted\"\n続き"],
            ["'=already quoted", "'=already quoted"],
            ['', ''],
            [null, ''],
            [0, '0'],
        ];
        $logs = [];
        $expectedRows = [];
        foreach ($cases as [$value, $expected]) {
            $logs[] = (new KomojuLog())
                ->setCreatedAt(new \DateTime('2026-10-03 12:34:56'))
                ->setApi($value)
                ->setOrderId($value)
                ->setMsg($value);
            $expectedRows[] = ['2026-10-03 12:34:56', $expected, $expected, $expected];
        }
        $this->queryBuilder->expects($this->once())->method('setMaxResults')->with(50000);
        $this->query->expects($this->once())->method('toIterable')->willReturn($logs);
        $this->entityManager->expects($this->never())->method('createQueryBuilder');
        $detached = [];
        $this->entityManager->expects($this->exactly(count($logs)))->method('detach')
            ->willReturnCallback(function ($log) use (&$detached) {
                $detached[] = $log;
            });

        $response = $this->controller->export($this->request('GET', new Session()));
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        ob_start();
        try {
            $response->sendContent();
            $csv = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));
        $handle = fopen('php://temp', 'w+');
        try {
            fwrite($handle, substr($csv, 3));
            rewind($handle);
            fgetcsv($handle, 0, ',', '"', '');
            $rows = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $rows[] = $row;
            }
            $this->assertSame($expectedRows, $rows);
        } finally {
            fclose($handle);
        }
        $this->assertSame($logs, $detached);
    }

    private function mockMethods(array $methods)
    {
        return $this->getMockBuilder(\stdClass::class)->addMethods($methods)->getMock();
    }

    private function expectIndex(int $page): PaginatorInterface
    {
        $countQuery = $this->mockMethods(['getSingleScalarResult', 'execute']);
        $countQuery->expects($this->once())->method('getSingleScalarResult')->willReturn(7);
        $countQuery->expects($this->never())->method('execute');
        $countBuilder = $this->mockMethods(['select', 'from', 'where', 'getQuery', 'delete']);
        $countBuilder->method('select')->willReturnSelf();
        $countBuilder->method('from')->willReturnSelf();
        $countBuilder->method('where')->willReturnSelf();
        $countBuilder->method('getQuery')->willReturn($countQuery);
        $countBuilder->expects($this->never())->method('delete');
        $this->entityManager->expects($this->once())->method('createQueryBuilder')->willReturn($countBuilder);
        $paginator = $this->createMock(PaginatorInterface::class);
        $paginator->expects($this->once())->method('paginate')
            ->with($this->queryBuilder, $page, 20)->willReturn(['page' => $page]);
        return $paginator;
    }

    private function request(string $method, Session $session): Request
    {
        $request = new class($session) extends Request {
            private $session;

            public function __construct(Session $session)
            {
                parent::__construct();
                $this->session = $session;
            }

            public function getSession(): Session
            {
                return $this->session;
            }
        };
        $request->setMethod($method);
        return $request;
    }
}

class TestableLogController extends LogController
{
    public $eccubeConfig;
    public $searchForm;

    public function createForm($type)
    {
        return $this->searchForm;
    }

    public function render($view, array $parameters = [])
    {
        return $parameters;
    }
}
