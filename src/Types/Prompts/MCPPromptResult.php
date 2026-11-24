<?php

namespace McpSrv\Types\Prompts;

use McpSrv\Common\TextCleaner;
use McpSrv\Types\Prompts\PromptResult\PromptResultMessage;

class MCPPromptResult {
	public readonly string $description;

	/**
	 * @param string $description
	 * @param PromptResultMessage[] $messages
	 */
	public function __construct(
		string $description,
		public readonly array $messages,
	) {
		$this->description = TextCleaner::text($description);
	}
}