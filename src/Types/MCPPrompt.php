<?php

namespace McpSrv\Types;

use McpSrv\Types\Prompts\MCPPromptArguments;
use McpSrv\Types\Prompts\MCPPromptResult;

class MCPPrompt {
	/**
	 * @param string $description
	 * @param MCPPromptArguments $arguments
	 * @param callable(object): MCPPromptResult $handler
	 */
	public function __construct(
		public string $description,
		public MCPPromptArguments $arguments,
		public $handler
	) {}
}