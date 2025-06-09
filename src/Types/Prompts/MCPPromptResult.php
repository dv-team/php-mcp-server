<?php

namespace McpSrv\Types\Prompts;

use McpSrv\Types\Prompts\PromptResult\PromptResultMessage;

class MCPPromptResult {
	/**
	 * @param string $description
	 * @param PromptResultMessage[] $messages
	 */
	public function __construct(
		public readonly string $description,
		public readonly array $messages,
	) {}
}