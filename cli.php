<?php

use McpSrv\Common\Response\StdoutResponseHandler;
use McpSrv\MCPServer;
use Psr\Log\AbstractLogger;

require __DIR__ . '/vendor/autoload.php';

$fileLogger = new class extends AbstractLogger {
	public function log($level, string|Stringable $message, array $context = []): void {
		/** @var string $level */
		$logMessage = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
		if (!empty($context)) {
			$logMessage .= 'Context: ' . json_encode($context) . "\n";
		}
		file_put_contents(__DIR__ . '/stdio–mcp.log', $logMessage, FILE_APPEND);
	}
};

$server = new MCPServer('Example server', new StdoutResponseHandler($fileLogger));

$fp = fopen('php://stdin', 'rb');
if($fp === false) {
	throw new RuntimeException('Failed to open stdin');
}
while(!feof($fp)) {
	$line = fgets($fp, null);
	if($line === false) {
		continue;
	}
	
	file_put_contents(__DIR__ . '/stdio–mcp.log', "IN  ".trim($line)."\n", FILE_APPEND);
	
	$server->run($line);
}