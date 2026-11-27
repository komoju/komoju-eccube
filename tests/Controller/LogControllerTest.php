<?php

namespace Tests\Komoju42\Controller;

use PHPUnit\Framework\TestCase;
use Plugin\Komoju42\Controller\Admin\LogController;

class LogControllerTest extends TestCase
{
    /** @dataProvider csvCells */
    public function testCsvExportNeutralizesSpreadsheetFormulas($value, $expected)
    {
        $this->assertSame($expected, $this->csvRow([$value]));
    }

    public function csvCells(): array
    {
        return [
            'formula' => ['=HYPERLINK("http://x","y")', "\"'=HYPERLINK(\"\"http://x\"\",\"\"y\"\")\"\n"],
            'plus' => ['+1+1', "'+1+1\n"],
            'minus' => ['-1+1', "'-1+1\n"],
            'at' => ['@SUM(A1)', "'@SUM(A1)\n"],
            'leading tab' => ["\t=1", "\"'\t=1\"\n"],
            'after spaces' => ['  =1', "\"'  =1\"\n"],
            'after full-width space' => ["\u{3000}=1", "'\u{3000}=1\n"],
            'invalid UTF-8' => ["\xff=1", "'\xff=1\n"],
            'plain text' => ['payment captured', "\"payment captured\"\n"],
            'formula char later' => ['amount=100', "amount=100\n"],
            'integer order id' => [-5, "-5\n"],
        ];
    }

    public function testCsvExportDoesNotTreatBackslashAsEscape()
    {
        $this->assertSame("\"a\\\"\"b\"\n", $this->csvRow(['a\\"b']));
    }

    private function csvRow(array $values): string
    {
        $controller = (new \ReflectionClass(LogController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(LogController::class, 'writeCsvRow');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $handle = fopen('php://memory', 'w+');
        $method->invoke($controller, $handle, $values);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }
}
