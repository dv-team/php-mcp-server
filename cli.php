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
	handler: fn(object $args): MCPToolResult => new MCPToolResult(content: ['current_iso8601' => (new DateTimeImmutable())->format('c')], isError: false)
);

$server->runCli(STDIN, loop: false);
