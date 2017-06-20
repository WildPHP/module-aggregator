<?php

/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\Aggregator;

interface IAggregatorSource
{
	/**
	 * @param string $searchTerm
	 * @return false|SearchResult[]
	 */
	public function find(string $searchTerm);

	/**
	 * @return string
	 */
	public function getDescription(): string;

	/**
	 * @return string
	 */
	public function getReadableName(): string;
}