<?php

use McpSrv\Common\Response\StdoutResponseHandler;
use McpSrv\MCPServer;
use McpSrv\Types\Tools\MCPToolInputSchema;
use McpSrv\Types\Tools\MCPToolProperties;
use McpSrv\Types\Tools\MCPToolResult;
use Psr\Log\AbstractLogger;

require __DIR__ . '/vendor/autoload.php';

$fileLogger = new class extends AbstractLogger {
	public function log($level, string|Stringable $message, array $context = []): void {
		/** @var string $level */
		$logMessage = sprintf("[%s] %s: %s", date('Y-m-d H:i:s'), strtoupper($level), trim($message));
		if (!empty($context)) {
			$logMessage .= ' ' . json_encode(value: $context, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		}
		$logMessage .= "\n";
		file_put_contents(__DIR__ . '/stdio–mcp.log', $logMessage, FILE_APPEND);
	}
};

$server = new MCPServer('Example server', new StdoutResponseHandler($fileLogger));

$server->registerTool(
	name: 'tell_date_and_time',
	description: 'Tells the current ISO 8601',
	inputSchema: new MCPToolInputSchema(new MCPToolProperties(), required: []),
	isDangerous: false,
	handler: fn(object $args): MCPToolResult => new MCPToolResult(content: ['current_date' => date('c')], isError: false)
);

$fp = fopen('php://stdin', 'rb');
if($fp === false) {
	throw new RuntimeException('Failed to open stdin');
}
while(!feof($fp)) {
	$line = fgets($fp, null);
	if($line === false) {
		continue;
	}
	
	$fileLogger->info("IN", (array) json_decode(json: $line, associative: false, flags: JSON_THROW_ON_ERROR));
	
	$server->run($line);
}
$fileLogger->info('SYSTEM: Server stopped');