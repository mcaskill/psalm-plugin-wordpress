<?php

namespace PsalmWordPress\Tests;

class TestConfig extends \Psalm\Tests\TestConfig {
	protected function getContents() : string {
		return '<?xml version="1.0"?>
			<projectFiles>
				<directory name="./" />
			</projectFiles>';
	}
}
