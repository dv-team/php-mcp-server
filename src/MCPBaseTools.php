<?php

namespace McpSrv;

use DateTimeImmutable;
use McpSrv\Common\Properties\MCPToolString;
use McpSrv\Types\Tools\MCPToolInputSchema;
use McpSrv\Types\Tools\MCPToolProperties;
use McpSrv\Types\Tools\MCPToolResult;
use ReflectionClass;
use RuntimeException;
use Throwable;

class MCPBaseTools {
	public static function register(MCPServer $server): void {
		$server->registerTool(
			name: 'current_date_and_time',
			description: 'Get the current date and time and timezone',
			inputSchema: new MCPToolInputSchema(new MCPToolProperties()),
			handler: static function() {
				$dt = (new DateTimeImmutable())->format('c');

				return new MCPToolResult(content: ['current_date_and_time' => $dt], isError: false);
			},
			annotations: (object) [
				'readOnlyHint' => true,
				'openWorldHint' => false,
			]
		);

		$server->registerTool(
			name: 'find_class_file',
			description: 'Find the relative file path by the name of the fully qualified class name',
			inputSchema: new MCPToolInputSchema(
				new MCPToolProperties(
					new MCPToolString(name: 'class_name', description: 'A single class name to get the file name for', required: true),
				)
			),
			handler: static function(object $args) {
				/** @var object{class_name: class-string} $args */
				try {
					$reflectionClass = new ReflectionClass($args->class_name);
					$filename = $reflectionClass->getFileName();
					if(!is_string($filename)) {
						throw new RuntimeException("Could not get filename for class {$args->class_name}");
					}

					return new MCPToolResult(content: ['path' => $filename], isError: false);
				} catch(Throwable $e) {
					return new MCPToolResult(content: ['message' => $e->getMessage()], isError: true);
				}
			},
			annotations: (object) [
				'readOnlyHint' => true,
				'idempotentHint' => true,
				'openWorldHint' => false,
			]
		);
	}
}
