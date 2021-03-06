<?php

/**
 * San Francisco parser for State Decoded.
 * Extends AmericanLegal base classes.
 *
 * PHP version 5
 *
 * @license		http://www.gnu.org/licenses/gpl.html GPL 3
 * @version		0.8
 * @link		http://www.statedecoded.com/
 * @since		0.3
*/

/**
 * This class may be populated with custom functions.
 */

require 'class.AmericanLegal.inc.php';

class State extends AmericanLegalState {}

class Parser extends AmericanLegalParser
{
	/*
	 * Most codes have a Table of Contents as the first LEVEL.
	 */
	public function pre_parse_chapter(&$chapter, &$structures)
	{
		$this->logger->message('Skipping first level.', 2);
		unset($chapter->LEVEL->LEVEL[0]);
	}
}
