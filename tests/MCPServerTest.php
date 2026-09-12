<?php

declare(strict_types=1);

namespace McpSrv;

use McpSrv\Common\PromptResult\RoleEnum;
use McpSrv\Common\Properties\MCPToolString;
use McpSrv\Types\Prompts\MCPPromptArguments;
use McpSrv\Types\Prompts\MCPPromptResult;
use McpSrv\Types\Prompts\PromptResult\PromptResultStringMessage;
use McpSrv\Types\Tools\MCPToolInputSchema;
use McpSrv\Types\Tools\MCPToolProperties;
use McpSrv\Types\Tools\MCPToolResult;
use PHPUnit\Framework\TestCase;

class MCPServerTest extends TestCase {
	public function testRunRejectsInvalidJson(): void {
		$handler = new Common\CapturingResponseHandler();
		$server = new MCPServer('test', $handler);
		
		$server->run('{');
		
		$this->assertNull($handler->reply, 'Expected no success reply');
		$this->assertSame([
			'jsonrpc' => '2.0',
			'id' => null,
			'error' => ['code' => -32700, 'message' => 'Failed to parse request body'],
		], $handler->raw);
	}
}
