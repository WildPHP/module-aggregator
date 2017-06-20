<?php

/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\Aggregator\Sources;

class ArchWiki extends Wikipedia
{
	protected $apiUri = 'https://wiki.archlinux.org/api.php';

	/**
	 * @return string
	 */
	public function getDescription(): string
	{
		return 'Search the Arch Linux Wiki for the given string.';
	}

	/**
	 * @return string
	 */
	public function getReadableName(): string
	{
		return 'Arch Linux Wiki';
	}
}
