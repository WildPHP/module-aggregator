<?php

/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\Aggregator\Sources;

class WikipediaNL extends Wikipedia
{
	protected $apiUri = 'https://nl.wikipedia.org/w/api.php';

	/**
	 * @return string
	 */
	public function getDescription(): string
	{
		return 'Search the Dutch Wikipedia for a given string.';
	}

	/**
	 * @return string
	 */
	public function getReadableName(): string
	{
		return 'Wikipedia (Dutch variant)';
	}
}
