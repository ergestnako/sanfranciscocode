<?php

/**
 * Library for importing XML formatted by American Legal.
 *
 * PHP version 5
 *
 * @license		http://www.gnu.org/licenses/gpl.html GPL 3
 * @version		0.8
 * @link		http://www.statedecoded.com/
 * @since		0.3
 *
 * This library is Abstract - meaning you must derive it to use it!
 *
 * Example usage (to replace State-sample):
********************************************************************
// class.MyCity.inc.php

 <?php

require 'class.AmericanLegal.inc.php';

// All we need is a derivative of both State and Parser.
class State extends AmericanLegalState {}

// We should probably list the images to ignore, though!
class Parser extends AmericanLegalParser
{
	public $image_blacklist = array(
		'seal.png',
		'seal.jpg'
	);
}

// class.MyCity.inc.php
*******************************************************************/

/**
 * This class may be populated with custom functions.
 */
abstract class AmericanLegalState
{

}


/**
 * The parser for importing legal codes. This is fully functional for importing The State Decoded's
 * prescribed XML format <https://github.com/statedecoded/statedecoded/wiki/XML-Format-for-Parser>,
 * and serves as a guide for those who want to parse an alternate format.
 */
abstract class AmericanLegalParser
{

	public $file = 0;
	public $directory;
	public $files = array();
	public $db;
	public $logger;
	public $edition_id;
	public $structure_labels;

	public $section_count = 1;
	public $structure_depth = 1;

	/*
	 * Most codes have a Table of Contents as the first LEVEL.
	 */
	public $skip_toc = TRUE;

	/*
	 * Regexes.
	 */
	//                            | type of section                 |!temp!|    | section number                    (opt ' - section number')       |      | hyphen | catch line
	public $section_regex = '/^\[?(?P<type>SEC(TION|S\.|\.)|APPENDIX|ARTICLE)\s+(?P<number>[0-9A-Z]+[0-9A-Za-z_\.\-]*(.?\s-\s[0-9]+[0-9A-Za-z\.\-]*)?)\.?\s*(?:-\s*)?(?P<catch_line>.*?)\.?\]?$/i';

	public $structure_regex = '/^(?P<type>SEC(TION|S\.|\.)|APPENDIX|CHAPTER|ARTICLE)\s+(?P<number>[A-Za-z0-9]+)(:|.)\s*(?P<name>.*?)$/';

	public $appendix_regex = '/^APPENDICES:\s+(?P<name>.*?)$/';

	/*
	 * Xpaths.
	 */
	public $structure_xpath = "./LEVEL[not(@style-name='Section')]";
	public $structure_heading_xpath = "./RECORD/HEADING";
	public $section_xpath = "./LEVEL[@style-name='Section']";

	/*
	 * Files to ignore.
	 */
	public $ignore_files = array(
		'0-0-0-1.xml',
		'0-0-0-2.xml'
	);

	/*
	 * Unfortunately, there are some images that we cannot use, for a variety of reasons.
	 * Most notably are city seals - most localities have laws preventing their use by
	 * anyone other than the city.  This is going to be locality-specific, so put them here.
	 * If you need more complex rules, override check_image()
	 */
	public $image_blacklist = array();

	/*
	 * Images to store.
	 */
	public $images = array();

	/*
	 * Count the structures and appendices statically
	 * so this will persist across instances.
	 */
	public static $appendix_count = 1;

	public function __construct($options)
	{

		/**
		 * Set our defaults
		 */
		foreach ($options as $key => $value)
		{
			$this->$key = $value;
		}

		/**
		 * Set the directory to parse
		 */
		if ($this->directory)
		{

			if (!isset($this->directory))
			{
				$this->directory = getcwd();
			}

			if (file_exists($this->directory) && is_dir($this->directory))
			{
				$directory = dir($this->directory);
			}
			else
			{
				throw new Exception('Import directory does not exist "' .
					$this->directory . '"');
			}

			while (false !== ($filename = $directory->read()))
			{

				/*
				 * We should make sure we've got an actual file that's readable.
				 * Ignore anything that starts with a dot.
				 */
				$filepath = $this->directory . $filename;
				if (is_file($filepath) &&
					is_readable($filepath) &&
					substr($filename, 0, 1) !== '.')
				{
					$this->files[] = $filepath;
				}

			}

			/*
			 * Check that we found at least one file
			 */
			if (count($this->files) < 1)
			{
				throw new Exception('No Import Files found in path "' .
					$this->directory . '"');
			}

		}

		if (!$this->structure_labels)
		{
			$this->structure_labels = $this->get_structure_labels();
		}

	}
	/**
	 * Step through every line of every file that contains the contents of the code.
	 */
	public function iterate()
	{

		/*
		 * Iterate through our resulting file listing.
		 */
		$file_count = count($this->files);
		for ($i = $this->file; $i < $file_count; $i++)
		{

			/*
			 * Operate on the present file.
			 */
			$filename = $this->files[$i];

			$file = array_pop(explode('/', $filename));

			/*
			 * We only care about xml files.
			 */
			$extension = substr($filename, strrpos($filename, '.')+1);

			/*
			 * Increment our placeholder counter.
			 */
			$this->file++;

			if($extension == 'xml' && !in_array($file, $this->ignore_files))
			{
				$this->import_xml($filename);

				/*
				 * If we have a valid file.
				 */
				if(@isset($this->chapter->REFERENCE->TITLE)){
					/*
					 * Send this object back, out of the iterator.
					 */

					$this->logger->message('Importing "' . $filename . '"', 3);
					return $this->chapter;
				}
				else {
					$this->logger->message('No sections found in "' . $filename . '"', 3);
					continue;
				}
			}
		}

	} // end iterate() function


	/**
	 * Convert the XML into an object.
	 */
	public function import_xml($filename)
	{
		$xml = file_get_contents($filename);

		try
		{
			$this->chapter = new SimpleXMLElement($xml);
		}
		catch(Exception $e)
		{
			/*
			 * If we can't convert to XML, try cleaning the data first.
			 */
			if (class_exists('tidy', FALSE))
			{

				$tidy_config = array('input-xml' => TRUE);
				$tidy = new tidy();
				$tidy->parseString($xml, $tidy_config, 'utf8');
				$tidy->cleanRepair();
				$xml = (string) $tidy;

			}
			elseif (exec('which tidy'))
			{
				exec('tidy -xml '.$filename, $output);
				$xml = join('', $output);
			}
			$this->chapter = new SimpleXMLElement($xml);
		}

		/*
		 * Send this object back, out of the iterator.
		 */
		return $this->chapter;
	}

	/**
	 * Accept the raw content of a section of code and normalize it.
	 */
	public function parse()
	{

		/*
		 * If a section of code hasn't been passed to this, then it's of no use.
		 */
		if (!isset($this->chapter))
		{
			return FALSE;
		}

		/*
		 * AM Legal gives a chapter at a time, which we break up
		 * and parse.
		 */
		$structures = array();

		/*
		 * The real chapter starts at the first level.
		 */
		$chapter = $this->chapter->LEVEL;

		/*
		 * The first child LEVEL we encounter is actually the table of contents, so we skip it.
		 */
		if($this->skip_toc)
		{
			unset($chapter->LEVEL[0]);
		}

		/*
		 * There are multiple sections per file.
		 */
		$this->sections = array();
		$this->section_count = 1;
		$this->parse_recurse($chapter, $structures);
	}

	public function parse_recurse($level, $structures)
	{
		$this->logger->message('parse_recurse', 1);

		/*
		 * Check to see if we have another layer of nesting
		 */
		if(isset($level->LEVEL))
		{
			/*
			 * If we have two levels deeper, this is a structure.
			 */
			if(count($level->xpath('./LEVEL/LEVEL')))
			{
				$structure = FALSE;

				$this->logger->message('STRUCTURE', 1);

				// If we have a structure heading, add it to the structures.
				if(count($level->xpath($this->structure_heading_xpath))) {
					$this->structure_depth++;

					$structure = $this->parse_structure( $level );

					if($structure) {
						$this->logger->message('Descending : ' . $structure->name, 1);

						$structures[] = $structure;
					}
				}
				foreach($level->LEVEL as $sublevel)
				{
					// But recurse, either way.
					$this->parse_recurse($sublevel, $structures);
				}

				// If we had a structure heading, pop it from the structures.
				if($structure) {
					$this->logger->message('Ascending', 1);

					$this->structure_depth--;
					array_pop($structures);
				}
			}
			/*
			 * If we have one level deeper, this is a section.
			 */
			else
			{
				$this->logger->message('SECTION', 1);

				$new_section = $this->parse_section($level, $structures);

				if($new_section)
				{
					$this->sections[] = $new_section;
				}
			}
		}
		/*
		 * If we have no children, somehow we've gone too far!
		 */
		else
		{
			$this->logger->message('Empty', 1);
		}

		$this->logger->message('Exit parse_recurse', 1);
	}

	public function parse_structure($level)
	{
		$structure_name = (string) $level->RECORD->HEADING;

		$structure = FALSE;

		if(preg_match($this->structure_regex, $structure_name, $chapter_parts))
		{
			$this->logger->message('Structure name: ' . $structure_name, 1);

			$structure = new stdClass();
			$structure->metadata = new stdClass();

			if(isset($chapter_parts['name']) && strlen(trim($chapter_parts['name'])))
			{
				$structure->name = $chapter_parts['name'];
			}
			else
			{
				$structure->name = $structure_name;
			}
			$structure->identifier = $chapter_parts['number'];
			$structure->order_by = str_pad($chapter_parts['number'], 4, '0', STR_PAD_LEFT);
			$structure->label = ucwords(strtolower($chapter_parts['type']));
		}
		elseif(preg_match($this->appendix_regex, $structure_name, $chapter_parts))
		{
			$this->logger->message('Appendix name: ' . $structure_name, 1);

			$structure = new stdClass();
			$structure->name = $chapter_parts['name'];
			// Make up an identifier.
			// Right now, there's only one!
			//$structure->identifier = 'A' . self::$appendix_count;
			$structure->identifier = 'appendix'; // Put these at the end.
			$structure->order_by = '1' . str_pad(self::$appendix_count, 3, '0', STR_PAD_LEFT);
			$structure->label = 'Appendix';

			self::$appendix_count++;
		}
		else
		{
			$this->logger->message('Failed to match structure title: ' . $structure_name, 1);
		}

		if($structure)
		{
			/*
			 * Set the level.
			 */
			$structure->level = $this->structure_depth;

			/*
			 * Check to see if this structure has text of its own.
			 */
			if($paragraphs = $level->xpath('./LEVEL[@style-name="Normal Level"]/RECORD'))
			{
				foreach($paragraphs as $paragraph)
				{
					$attributes = $paragraph->PARA->attributes();

					$type = '';

					if(isset($attributes['style-name']))
					{
						$type = (string) $attributes['style-name'];
					}

					switch($type)
					{
						case 'History' :
						case 'Section-Deleted' :
							$structure->metadata->history .= $this->clean_text($paragraph->PARA->asXML());
							break;

						case 'EdNote' :
							$structure->metadata->notes .= $this->clean_text($paragraph->PARA->asXML());
							break;

						default :
							$structure->metadata->text .= $this->clean_text($paragraph->PARA->asXML());
							break;
					}
				}
			}

		}

		return $structure;
	}

	public function parse_section($section, $structures)
	{
		$code = new stdClass();

		$code->structure = $structures;

		/*
		 * Parse the catch line and section number.
		 */
		$section_title = trim((string) $section->RECORD->HEADING);

		$this->logger->message('Title: ' . $section_title, 1);

		preg_match($this->section_regex, $section_title, $section_parts);

		if(!isset($section_parts['number']) || !isset($section_parts['catch_line']))
		{
			// TODO: Handle this error somewhat more gracefully.
			$this->logger->message('Could not get Section info from title, "' . $section_title . '"', 5);
		}

		$code->section_number = $section_parts['number'];

		$code->catch_line = $section_parts['catch_line'];


		/*
		 * If this is an appendix, use the whole line as the title.
		 */
		if($section_parts['type'] === 'APPENDIX')
		{
			$code->catch_line = $section_parts[0];
		}
		$code->text = '';
		$code->history = '';
		$code->metadata = array(
			'repealed' => 'n'
		);
		$code->order_by = str_pad($this->section_count, 4, '0', STR_PAD_LEFT);

		/*
		 * Get the paragraph text from the children RECORDs.
		 */

		$code->section = new stdClass();
		$i = 0;

		foreach($section->LEVEL->RECORD as $paragraph) {

			$attributes = $paragraph->PARA->attributes();

			$type = '';

			if(isset($attributes['style-name']))
			{
				$type = (string) $attributes['style-name'];
			}

			switch($type)
			{
				case 'History' :
					$code->history .= $this->clean_text($paragraph->PARA->asXML());
					break;

				case 'Section-Deleted' :
					$code->catch_line = '[REPEALED]';
					$code->metadata['repealed'] = 'y';
					break;

				case 'EdNote' :
					$code->metadata['notes'] = $this->clean_text($paragraph->PARA->asXML());
					break;

				default :
					$code->section->{$i} = new stdClass();

					$section_text = $this->clean_text($paragraph->PARA->asXML());

					$code->text .= $section_text . "\r\r";
					/*
					 * Get the section identifier if it exists.
					 */

					if(preg_match("/^<p>\s*\((?P<letter>[a-zA-Z0-9]{1,3})\) /", $section_text, $paragraph_id))
					{
						$code->section->{$i}->prefix = $paragraph_id['letter'];
						/*
						 * TODO: !IMPORTANT Deal with hierarchy.  This is just a hack.
						 */
						$code->section->{$i}->prefix_hierarchy = array($paragraph_id['letter']);

						/*
						 * Remove the section letter from the section.
						 */
						$section_text = str_replace($paragraph_id[0], '<p>', $section_text);
					}
					// TODO: Clean up tags in the paragraph.

					$code->section->{$i}->text = $section_text;

					$i++;
			}
		}

		if(isset($code->catch_line) && strlen($code->catch_line))
		{
			$this->section_count++;

			return $code;
		}
		else
		{
			return FALSE;
		}
	}

	/**
	 * Clean up XML into nice HTML.
	 */
	public function clean_text($xml)
	{
		// Remove TABLEFORMAT.
		$xml = preg_replace('/<TABLEFORMAT[^>]*>.*?<\/TABLEFORMAT>/sm', '', $xml);

		// Replace SCROLLTABLE
		$xml = preg_replace('/<SCROLL_TABLE[^>]*>(.*?)<\/SCROLL_TABLE>/sm', '<table>$1</table>', $xml);

		// Replace ROW with tr.
		$xml = str_replace(array('<ROW>', '</ROW>'), array('<tr>', '</tr>'), $xml);

		// Replace COL with td.
		$xml = str_replace(array('<COL>', '</COL>'), array('<td>', '</td>'), $xml);

		// Replace CELLFORMAT.
		$xml = preg_replace('/<CELLFORMAT[^>]*>(.*?)<\/CELLFORMAT>/sm', '$1', $xml);

		// Replace CELL.
		$xml = preg_replace('/<CELL[^>]*>(.*?)<\/CELL>/sm', '$1', $xml);

		// Replace empty tables.
		$xml = preg_replace('/<TABLE>\s*<\/TABLE>/sm', '', $xml);

		// Replace PARA with P.
		$xml = preg_replace('/<PARA[^>]*>/sm', '<p>', $xml);
		$xml = str_replace('</PARA>', '<p>', $xml);

		// Replace <td><p> with <td>
		$xml = preg_replace('/<td>\s*<p>/sm', '<td>', $xml);
		$xml = preg_replace('/<\/p>\s*<\/td>/sm', '<td>', $xml);

		// Replace TAB
		// TODO: !IMPORTANT Handle nested paragraphs here.
		$xml = str_replace('<TAB tab-count="1"/>', ' ', $xml);

		// Deal with images
		preg_match_all('/<PICTURE(?P<args>[^>]*?)\/>/', $xml, $images, PREG_SET_ORDER);
		foreach($images as $current_image)
		{
			// Parse the arguments into an array.
			preg_match_all('/(?P<name>[a-zA-Z_-]+)="(?P<value>[^"]*)"/',
				$current_image['args'], $image_attrs, PREG_SET_ORDER);

			$image = array();
var_dump($image_attrs);
			foreach($image_attrs as $image_attr)
			{
				$image[ $image_attr['name'] ] = $image_attr['value'];
			}

var_dump($image);
			if( $this->check_image($image) )
			{
				$this->images[] = $image;
				$xml = str_replace($current_image[0],
					'<img src="/downloads/' . $image['id'] . '.jpg"/>',
					$xml);
			}
			else
			{
				$xml = str_replace($current_image[0], '', $xml);

			}

		}

		// Trim.
		$xml = trim($xml);

		return $xml;
	}

	public function clean_title($text)
	{
		$text = str_replace('<LINEBRK/>', ' ', $text);

		return $text;
	}

	/**
	 * Check that the image is valid.
	 */
	public function check_image($image)
	{
		return in_array($image['id'], $this->image_blacklist);
	}


	/**
	 * Create permalinks from what's in the database
	 */
	public function build_permalinks()
	{

		$this->move_old_permalinks();
		$this->build_permalink_subsections();

	}

	/**
	 * Remove all old permalinks
	 */
	// TODO: eventually, we'll want to keep these and have multiple versions.
	// See issues #314 #362 #363
	public function move_old_permalinks()
	{

		$sql = 'DELETE FROM permalinks';
		$statement = $this->db->prepare($sql);

		$result = $statement->execute();
		if ($result === FALSE)
		{
			echo '<p>Query failed: '.$sql.'</p>';
			return;
		}

	}

	/**
	 * Recurse through all subsections to build permalink data.
	 */
	public function build_permalink_subsections($parent_id = null)
	{

		$structure_sql = '	SELECT structure_unified.*,
							editions.current AS current_edition,
							editions.slug AS edition_slug
							FROM structure
							LEFT JOIN structure_unified
								ON structure.id = structure_unified.s1_id
							LEFT JOIN editions
								ON structure.edition_id = editions.id';

		/*
		 * We use prepared statements for efficiency.  As a result,
		 * we need to keep an array of our arguments rather than
		 * hardcoding them in the SQL.
		 */
		$structure_args = array();

		if (isset($parent_id))
		{
			$structure_sql .= ' WHERE parent_id = :parent_id';
			$structure_args[':parent_id'] = $parent_id;
		}
		else
		{
			$structure_sql .= ' WHERE parent_id IS NULL';
		}

		$structure_statement = $this->db->prepare($structure_sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
		$structure_result = $structure_statement->execute($structure_args);

		if ($structure_result === FALSE)
		{
			echo '<p>' . $structure_sql . '</p>';
			echo '<p>' . $structure_result->getMessage() . '</p>';
			return;
		}

		/*
		 * Get results as an array to save memory
		 */
		while ($item = $structure_statement->fetch(PDO::FETCH_ASSOC))
		{

			/*
			 * Figure out the URL for this structural unit by iterating through the "identifier"
			 * columns in this row.
			 */
			$identifier_parts = array();

			foreach ($item as $key => $value)
			{
				if (preg_match('/s[0-9]_identifier/', $key) == 1)
				{
					/*
					 * Higher-level structural elements (e.g., titles) will have blank columns in
					 * structure_unified, so we want to omit any blank values. Because a valid
					 * structural unit identifier is "0" (Virginia does this), we check the string
					 * length, rather than using empty().
					 */
					if (strlen($value) > 0)
					{
						$identifier_parts[] = urlencode($value);
					}
				}
			}
			$identifier_parts = array_reverse($identifier_parts);

			foreach ($identifier_parts as $key => $value) {
				$identifier_parts[$key] = $this->slugify($value);
			}

			$token = implode('/', $identifier_parts);


			if ($item['current_edition'])
			{
				$url = '/' . $token . '/';
			}
			else
			{
				$url = '/' . $item['edition_slug'] . '/' . $token .'/';
			}

			/*
			 * Insert the structure
			 */
			$insert_sql = 'INSERT INTO permalinks SET
				object_type = :object_type,
				relational_id = :relational_id,
				identifier = :identifier,
				token = :token,
				url = :url';
			$insert_statement = $this->db->prepare($insert_sql);
			$insert_data = array(
				':object_type' => 'structure',
				':relational_id' => $item['s1_id'],
				':identifier' => $item['s1_identifier'],
				':token' => $token,
				':url' => $url,
			);


			$insert_result = $insert_statement->execute($insert_data);
			if ($insert_result === FALSE)
			{
				echo '<p>'.$sql.'</p>';
				echo '<p>'.$structure_result->getMessage().'</p>';
				return;
			}

			/*
			 * Now we can use our data to build the child law identifiers
			 */
			if (INCLUDES_REPEALED !== TRUE)
			{
				$laws_sql = '	SELECT id, structure_id, section AS section_number, catch_line
								FROM laws
								WHERE structure_id = :s_id
								ORDER BY order_by, section';
			}
			else
			{
				$laws_sql = '	SELECT laws.id, laws.structure_id, laws.section AS section_number,
								laws.catch_line
								FROM laws
								LEFT OUTER JOIN laws_meta
									ON laws_meta.law_id = laws.id AND laws_meta.meta_key = "repealed"
								WHERE structure_id = :s_id
								AND (laws_meta.meta_value = "n" OR laws_meta.meta_value IS NULL)
								ORDER BY order_by, section';
			}
			$laws_statement = $this->db->prepare($laws_sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
			$laws_result = $laws_statement->execute( array( ':s_id' => $item['s1_id'] ) );

			if ($structure_result === FALSE)
			{
				echo '<p>'.$laws_sql.'</p>';
				echo '<p>'.$laws_result->getMessage().'</p>';
				return;
			}

			while($law = $laws_statement->fetch(PDO::FETCH_ASSOC))
			{
				$section_slug = $this->slugify($law['section_number']);

				if(defined('LAW_LONG_URLS') && LAW_LONG_URLS === TRUE)
				{
					$law_token = $token . '/' . $section_slug;
					$law_url = $url . $section_slug . '/';
				}
				else
				{
					$law_token = $section_slug;

					if ($item['current_edition'])
					{
						$law_url = '/' . $section_slug . '/';
					}
					else
					{
						$law_url = '/' . $item['edition_slug'] . '/' . $section_slug . '/';
					}
				}
				/*
				 * Insert the structure
				 */
				$insert_sql =  'INSERT INTO permalinks SET
								object_type = :object_type,
								relational_id = :relational_id,
								identifier = :identifier,
								token = :token,
								url = :url';
				$insert_statement = $this->db->prepare($insert_sql);
				$insert_data = array(
					':object_type' => 'law',
					':relational_id' => $law['id'],
					':identifier' => $law['section_number'],
					':token' => $law_token,
					':url' => $law_url,
				);

				$insert_result = $insert_statement->execute($insert_data);

				if ($insert_result === FALSE)
				{
					echo '<p>'.$insert_sql.'</p>';
					echo '<p>'.$insert_result->getMessage().'</p>';
					return;
				}
			}

			$this->build_permalink_subsections($item['s1_id']);

		}
	}

	/**
	 * Do any setup.
	 */
	public function pre_parse()
	{
	}
	/**
	 * Do any cleanup.
	 */
	public function post_parse()
	{
	}

	public function store()
	{
		foreach($this->sections as $code)
		{
			$this->code = $code;
			$this->store_section();
		}
	}

	/**
	 * Take an object containing the normalized code data and store it.
	 */
	public function store_section()
	{
		if (!isset($this->code))
		{
			die('No data provided.');
		}

		/*
		 * This first section creates the record for the law, but doesn't do anything with the
		 * content of it just yet.
		 */

		/*
		 * Try to create this section's structural element(s). If they already exist,
		 * create_structure() will handle that silently. Either way a structural ID gets returned.
		 */
		$structure = new Parser(
			array(
				'db' => $this->db,
				'logger' => $this->logger,
				'edition_id' => $this->edition_id,
				'structure_labels' => $this->structure_labels
			)
		);

		foreach ($this->code->structure as $struct)
		{

			$structure->identifier = $struct->identifier;
			$structure->name = $struct->name;
			$structure->label = $struct->label;
			$structure->level = $struct->level;
			$structure->order_by = $struct->order_by;
			$structure->metadata = $struct->metadata;

			/* If we've gone through this loop already, then we have a parent ID. */
			if (isset($this->code->structure_id) && $this->code->structure_id > 0)
			{
				$structure->parent_id = $this->code->structure_id;
			}
			$this->code->structure_id = $structure->create_structure();

		}

		/*
		 * When that loop is finished, because structural units are ordered from most general to
		 * most specific, we're left with the section's parent ID. Preserve it.
		 */
		$query['structure_id'] = $this->code->structure_id;

		/*
		 * Build up an array of field names and values, using the names of the database columns as
		 * the key names.
		 */
		$query['catch_line'] = $this->code->catch_line;
		$query['section'] = $this->code->section_number;
		$query['text'] = $this->code->text;
		if (!empty($this->code->order_by))
		{
			$query['order_by'] = $this->code->order_by;
		}
		if (isset($this->code->history))
		{
			$query['history'] = $this->code->history;
		}

		/*
		 * Create the beginning of the insertion statement.
		 */
		$sql = 'INSERT INTO laws
				SET date_created=now()';
		$sql_args = array();
		$query['edition_id'] = $this->edition_id;

		/*
		 * Iterate through the array and turn it into SQL.
		 */
		foreach ($query as $name => $value)
		{
			$sql .= ', ' . $name . ' = :' . $name;
			$sql_args[':' . $name] = $value;
		}

		$statement = $this->db->prepare($sql);
		$result = $statement->execute($sql_args);

		if ($result === FALSE)
		{
			echo '<p>Failure: ' . $sql . '</p>';
			var_dump($sql_args);
		}

		/*
		 * Preserve the insert ID from this law, since we'll need it below.
		 */
		$law_id = $this->db->lastInsertID();

		/*
		 * This second section inserts the textual portions of the law.
		 */

		/*
		 * Pull out any mentions of other sections of the code that are found within its text and
		 * save a record of those, for crossreferencing purposes.
		 */
		$references = new Parser(
			array(
				'db' => $this->db,
				'logger' => $this->logger,
				'edition_id' => $this->edition_id,
				'structure_labels' => $this->structure_labels
			)
		);
		$references->text = $this->code->text;
		$sections = $references->extract_references();
		if ( ($sections !== FALSE) && (count($sections) > 0) )
		{
			$references->section_id = $law_id;
			$references->sections = $sections;
			$success = $references->store_references();
			if ($success === FALSE)
			{
				echo '<p>References for section ID '.$law_id.' were found, but could not be
					stored.</p>';
			}
		}

		/*
		 * Store any metadata.
		 */
		if (isset($this->code->metadata))
		{

			/*
			 * Step through every metadata field and add it.
			 */
			$sql = 'INSERT INTO laws_meta
					SET law_id = :law_id,
					meta_key = :meta_key,
					meta_value = :meta_value';
			$statement = $this->db->prepare($sql);

			foreach ($this->code->metadata as $key => $value)
			{
				$sql_args = array(
					':law_id' => $law_id,
					':meta_key' => $key,
					':meta_value' => $value
				);
				$result = $statement->execute($sql_args);

				if ($result === FALSE)
				{
					echo '<p>Failure: '.$sql.'</p>';
				}
			}

		}

		/*
		 * Store any tags associated with this law.
		 */
		if (isset($this->code->tags))
		{
			$sql = 'INSERT INTO tags
					SET law_id = :law_id,
					section_number = :section_number,
					text = :tag';
			$statement = $this->db->prepare($sql);

			foreach ($this->code->tags as $tag)
			{
				$sql_args = array(
					':law_id' => $law_id,
					':section_number' => $this->code->section_number,
					':tag' => $tag
				);
				$result = $statement->execute($sql_args);

				if ($result === FALSE)
				{
					echo '<p>Failure: '.$sql.'</p>';
					var_dump($sql_args);
				}
			}
		}

		/*
		 * Step through each section.
		 */
		$i=1;
		foreach ($this->code->section as $section)
		{

			/*
			 * If no section type has been specified, make it your basic section.
			 */
			if (empty($section->type))
			{
				$section->type = 'section';
			}

			/*
			 * Insert this subsection into the text table.
			 */
			$sql = 'INSERT INTO text
					SET law_id = :law_id,
					sequence = :sequence,
					type = :type,
					date_created=now()';
			$sql_args = array(
				':law_id' => $law_id,
				':sequence' => $i,
				':type' => $section->type
			);
			if (!empty($section->text))
			{
				$sql .= ', text = :text';
				$sql_args[':text'] = $section->text;
			}

			$statement = $this->db->prepare($sql);
			$result = $statement->execute($sql_args);

			if ($result === FALSE)
			{
				echo '<p>Failure: '.$sql.'</p>';
			}

			/*
			 * Preserve the insert ID from this section of text, since we'll need it below.
			 */
			$text_id = $this->db->lastInsertID();

			/*
			 * Start a new counter. We'll use it to track the sequence of subsections.
			 */
			$j = 1;

			/*
			 * Step through every portion of the prefix (i.e. A4b is three portions) and insert
			 * each.
			 */
			if (isset($section->prefix_hierarchy))
			{

				foreach ($section->prefix_hierarchy as $prefix)
				{
					$sql = 'INSERT INTO text_sections
							SET text_id = :text_id,
							identifier = :identifier,
							sequence = :sequence,
							date_created=now()';
					$sql_args = array(
						':text_id' => $text_id,
						':identifier' => $prefix,
						':sequence' => $j
					);

					$statement = $this->db->prepare($sql);
					$result = $statement->execute($sql_args);

					if ($result === FALSE)
					{
						echo '<p>Failure: ' . $sql . '</p>';
					}

					$j++;
				}

			}

			$i++;
		}


		/*
		 * Trawl through the text for definitions.
		 */
		$dictionary = new Parser(
			array(
				'db' => $this->db,
				'logger' => $this->logger,
				'edition_id' => $this->edition_id,
				'structure_labels' => $this->structure_labels
			)
		);

		/*
		 * Pass this section of text to $dictionary.
		 */
		$dictionary->text = $this->code->text;

		/*
		 * Get a normalized listing of definitions.
		 */
		$definitions = $dictionary->extract_definitions();

		/*
		 * Check to see if this section or its containing structural unit were specified in the
		 * config file as a container for global definitions. If it was, then we override the
		 * presumed scope and provide a global scope.
		 */
		$ancestry = array();
		if (isset($this->code->structure))
		{
			foreach ($this->code->structure as $struct)
			{
				$ancestry[] = $struct->identifier;
			}
		}
		$ancestry = implode(',', $ancestry);
		$ancestry_section = $ancestry . ','.$this->code->section_number;
		if 	(
				(GLOBAL_DEFINITIONS === $ancestry)
				||
				(GLOBAL_DEFINITIONS === $ancestry_section)
			)
		{
			$definitions->scope = 'global';
		}
		unset($ancestry);
		unset($ancestry_section);

		/*
		 * If any definitions were found in this text, store them.
		 */
		if ($definitions !== FALSE)
		{

			/*
			 * Populate the appropriate variables.
			 */
			$dictionary->terms = $definitions->terms;
			$dictionary->law_id = $law_id;
			$dictionary->scope = $definitions->scope;
			$dictionary->structure_id = $this->code->structure_id;

			/*
			 * If the scope of this definition isn't section-specific, and isn't global, then
			 * find the ID of the structural unit that is the limit of its scope.
			 */
			if ( ($dictionary->scope != 'section') && ($dictionary->scope != 'global') )
			{
				$find_scope = new Parser(
					array(
						'db' => $this->db,
						'logger' => $this->logger,
						'edition_id' => $this->edition_id,
						'structure_labels' => $this->structure_labels
					)
				);
				$find_scope->label = $dictionary->scope;
				$find_scope->structure_id = $dictionary->structure_id;

				if($dictionary->structure_id)
				{
					$dictionary->structure_id = $find_scope->find_structure_parent();
					if ($dictionary->structure_id == FALSE)
					{
						unset($dictionary->structure_id);
					}
				}
			}

			/*
			 * If the scope isn't a structural unit, then delete it, so that we don't store it
			 * and inadvertently limit the scope.
			 */
			else
			{
				unset($dictionary->structure_id);
			}

			/*
			 * Determine the position of this structural unit.
			 */
			$structure = array_reverse($this->structure_labels);
			array_push($structure, 'global');

			/*
			 * Find and return the position of this structural unit in the hierarchical stack.
			 */
			$dictionary->scope_specificity = array_search($dictionary->scope, $structure);

			/*
			 * Store these definitions in the database.
			 */
			$dictionary->store_definitions();

		}

		/*
		 * Memory management.
		 */
		unset($references);
		unset($dictionary);
		unset($definitions);
		unset($chapter);
		unset($sections);
		unset($query);
	}


	/**
	 * When provided with a structural identifier, verifies whether that structural unit exists.
	 * Returns the structural database ID if it exists; otherwise, returns false.
	 */
	public function structure_exists()
	{

		if (!isset($this->identifier))
		{
			return FALSE;
		}

		/*
		 * Assemble the query.
		 */
		$sql = 'SELECT id
				FROM structure
				WHERE identifier = :identifier
				AND edition_id = :edition_id';
		$sql_args = array(
			':identifier' => $this->identifier,
			':edition_id' => $this->edition_id
		);

		/*
		 * If a parent ID is present (that is, if this structural unit isn't a top-level unit), then
		 * include that in our query.
		 */
		if ( !empty($this->parent_id) )
		{
			$sql .= ' AND parent_id = :parent_id';
			$sql_args[':parent_id'] = $this->parent_id;
		}
		else
		{
			$sql .= ' AND parent_id IS NULL';
		}

		$statement = $this->db->prepare($sql);
		$result = $statement->execute($sql_args);

		if ( ($result === FALSE) || ($statement->rowCount() === 0) )
		{
			return FALSE;
		}

		$structure = $statement->fetch(PDO::FETCH_OBJ);
		return $structure->id;
	}


	/**
	 * When provided with a structural unit identifier and type, it creates a record for that
	 * structural unit. Save for top-level structural units (e.g., titles), it should always be
	 * provided with a $parent_id, which is the ID of the parent structural unit. Most structural
	 * units will have a name, but not all.
	 */
	function create_structure()
	{

		/*
		 * Sometimes the code contains references to no-longer-existent chapters and even whole
		 * titles of the code. These are void of necessary information. We want to ignore these
		 * silently. Though you'd think we should require a chapter name, we actually shouldn't,
		 * because sometimes chapters don't have names. In the Virginia Code, for instance, titles
		 * 8.5A, 8.6A, 8.10, and 8.11 all have just one chapter ("part"), and none of them have a
		 * name.
		 *
		 * Because a valid structural identifier can be "0" we can't simply use empty(), but must
		 * also verify that the string is longer than zero characters. We do both because empty()
		 * will valuate faster than strlen(), and because these two strings will almost never be
		 * empty.
		 */
		if (
				( empty($this->identifier) && (strlen($this->identifier) === 0) )
				||
				( empty($this->label) )
			)
		{
			$this->logger->message('Can\'t create structure "' . $this->name . '"', 5);
			return FALSE;
		}

		/*
		 * Begin by seeing if this structural unit already exists. If it does, return its ID.
		 */
		$structure_id = $this->structure_exists();
		if ($structure_id !== FALSE)
		{
			$this->logger->message('Structure_exists "' . $this->name . '"', 1);
			return $structure_id;
		}

		/* Now we know that this structural unit does not exist, so Insert this structural record
		 * into the database. It's tempting to use ON DUPLICATE KEY here, and eliminate the use of
		 * structure_exists(), but then MDB2's lastInsertID() becomes unreliable. That means we need
		 * a second query to determine the ID of this structural unit. Better to check if it exists
		 * first and insert it if it doesn't than to insert it every time and then query its ID
		 * every time, since the former approach will require many less queries than the latter.
		 */
		$sql = 'INSERT INTO structure
				SET identifier = :identifier';
		$sql_args = array(
			':identifier' => $this->identifier
		);
		if (!empty($this->name))
		{
			$sql .= ', name = :name';
			$sql_args[':name'] = $this->name;
		}
		$sql .= ', label = :label, edition_id = :edition_id';
		$sql .= ', depth = :depth, order_by = :order_by';
		$sql .= ', date_created=now()';
		$sql_args[':label'] = $this->label;
		$sql_args[':edition_id'] = $this->edition_id;
		$sql_args[':depth'] = $this->level;
		$sql_args[':order_by'] = $this->order_by;
		if (isset($this->parent_id))
		{
			$sql .= ', parent_id = :parent_id';
			$sql_args[':parent_id'] = $this->parent_id;

		}
		if(isset($this->metadata))
		{
			$sql .= ', metadata = :metadata';
			$sql_args[':metadata'] = serialize($this->metadata);
		}

		$this->logger->message('Structure created: "' . $this->name . '"', 1);

		$statement = $this->db->prepare($sql);
		$result = $statement->execute($sql_args);

		if ($result === FALSE)
		{
			echo '<p>Failure: '.$sql.'</p>';
			var_dump($sql_args);
			return FALSE;
		}

		return $this->db->lastInsertID();

	}


	/**
	 * When provided with a structural unit ID and a label, this function will iteratively search
	 * through that structural unit's ancestry until it finds a structural unit with that label.
	 * This is meant for use while identifying definitions, within the store() method, specifically
	 * to set the scope of applicability of a definition.
	 */
	function find_structure_parent()
	{

		/*
		 * We require a beginning structure ID and the label of the structural unit that's sought.
		 */
		if ( !isset($this->structure_id) || !isset($this->label) )
		{
			return FALSE;
		}

		/*
		 * Make the sought parent ID available as a local variable, which we'll repopulate with each
		 * loop through the below while() structure.
		 */
		$parent_id = $this->structure_id;

		/*
		 * Establish a blank variable.
		 */
		$returned_id = '';

		/*
		 * Loop through a query for parent IDs until we find the one we're looking for.
		 */
		while ($returned_id == '')
		{

			$sql = 'SELECT id, parent_id, label
					FROM structure
					WHERE id = :id';
			$sql_args = array(
				':id' => $parent_id
			);

			$statement = $this->db->prepare($sql);
			$result = $statement->execute($sql_args);

			if ( ($result === FALSE) || ($statement->rowCount() == 0) )
			{
				echo '<p>Query failed: '.$sql.'</p>';
				var_dump($sql_args);
				return FALSE;
			}

			/*
			 * Return the result as an object.
			 */
			$structure = $statement->fetch(PDO::FETCH_OBJ);

			/*
			 * If the label of this structural unit matches the label that we're looking for, return
			 * its ID.
			 */
			if ($structure->label == $this->label)
			{
				return $structure->id;
			}

			/*
			 * Else if this structural unit has no parent ID, then our effort has failed.
			 */
			elseif (empty($structure->parent_id))
			{
				return FALSE;
			}

			/*
			 * If all else fails, then loop through again, searching one level farther up.
			 */
			else
			{
				$parent_id = $structure->parent_id;
			}
		}
	}


	/**
	 * When fed a section of the code that contains definitions, extracts the definitions from that
	 * section and returns them as an object. Requires only a block of text.
	 */
	function extract_definitions()
	{

		if (!isset($this->text))
		{
			return FALSE;
		}

		/*
		 * The candidate phrases that indicate that the scope of one or more definitions are about
		 * to be provided. Some phrases are left-padded with a space if they would never occur
		 * without being preceded by a space; this is to prevent over-broad matches.
		 */
		$scope_indicators = array(	' are used in this ',
									'when used in this ',
									'for purposes of this ',
									'for the purposes of this ',
									'for the purpose of this ',
									'in this ',
								);

		/*
		 * Create a list of every phrase that can be used to link a term to its defintion, e.g.,
		 * "'People' has the same meaning as 'persons.'" When appropriate, pad these terms with
		 * spaces, to avoid erroneously matching fragments of other terms.
		 */
		$linking_phrases = array(	' mean ',
									' means ',
									' shall include ',
									' includes ',
									' has the same meaning as ',
									' shall be construed ',
									' shall also be construed to mean ',
								);

		/* Measure whether there are more straight quotes or directional quotes in this passage
		 * of text, to determine which type are used in these definitions. We double the count of
		 * directional quotes since we're only counting one of the two directions.
		 */
		if ( substr_count($this->text, '"') > (substr_count($this->text, '”') * 2) )
		{
			$quote_type = 'straight';
			$quote_sample = '"';
		}
		else
		{
			$quote_type = 'directional';
			$quote_sample = '”';
		}

		/*
		 * Break up this section into paragraphs. If HTML paragraph tags are present, break it up
		 * with those. If they're not, break it up with carriage returns.
		 */
		if (strpos($this->text, '<p>') !== FALSE)
		{
			$paragraphs = explode('<p>', $this->text);
		}
		else
		{
			$this->text = str_replace("\n", "\r", $this->text);
			$paragraphs = explode("\r", $this->text);
		}

		/*
		 * Create the empty array that we'll build up with the definitions found in this section.
		 */
		$definitions = array();

		/*
		 * Step through each paragraph and determine which contain definitions.
		 */
		foreach ($paragraphs as &$paragraph)
		{

			/*
			 * Any remaining paired paragraph tags are within an individual, multi-part definition,
			 * and can be turned into spaces.
			 */
			$paragraph = str_replace('</p><p>', ' ', $paragraph);

			/*
			 * Strip out any remaining HTML.
			 */
			$paragraph = strip_tags($paragraph);

			/*
			 * Calculate the scope of these definitions using the first line.
			 */
			if (reset($paragraphs) == $paragraph)
			{

				/*
				 * Gather up a list of structural labels and determine the length of the longest
				 * one, which we'll use to narrow the scope of our search for the use of structural
				 * labels within the text.
				 */
				$structure_labels = $this->structure_labels;

				usort($structure_labels, 'sort_by_length');
				$longest_label = strlen(current($structure_labels));

				/*
				 * Iterate through every scope indicator.
				 */
				foreach ($scope_indicators as $scope_indicator)
				{

					/*
					 * See if the scope indicator is present in this paragraph.
					 */
					$pos = stripos($paragraph, $scope_indicator);

					/*
					 * The term was found.
					 */
					if ($pos !== FALSE)
					{

						/*
						 * Now figure out the specified scope by examining the text that appears
						 * immediately after the scope indicator. Pull out as many characters as the
						 * length of the longest structural label.
						 */
						$phrase = substr( $paragraph, ($pos + strlen($scope_indicator)), $longest_label );

						/*
						 * Iterate through the structural labels and check each one to see if it's
						 * present in the phrase that we're examining.
						 */
						foreach ($structure_labels as $structure_label)
						{

							if (stripos($phrase, $structure_label) !== FALSE)
							{

								/*
								 * We've made a match -- we've successfully identified the scope of
								 * these definitions.
								 */
								$scope = $structure_label;

								/*
								 * Now that we have a match, we can break out of both the containing
								 * foreach() and its parent foreach().
								 */
								break(2);

							}

							/*
							 * If we can't calculate scope, then let’s assume that it's specific to
							 * the most basic structural unit -- the individual law -- for the sake
							 * of caution. We pull that off of the end of the structure labels array
							 */
							$scope = end($structure_labels);

						}

					}

				}

				/*
				 * That's all we're going to get out of this paragraph, so move onto the next one.
				 */
				continue;

			}

			/*
			 * All defined terms are surrounded by quotation marks, so let's use that as a criteria
			 * to round down our candidate paragraphs.
			 */
			if (strpos($paragraph, $quote_sample) !== FALSE)
			{

				/*
				 * Iterate through every linking phrase and see if it's present in this paragraph.
				 * We need to find the right one that will allow us to connect a term to its
				 * definition.
				 */
				foreach ($linking_phrases as $linking_phrase)
				{

					if (strpos($paragraph, $linking_phrase) !== FALSE)
					{

						/*
						 * Extract every word in quotation marks in this paragraph as a term that's
						 * being defined here. Most definitions will have just one term being
						 * defined, but some will have two or more.
						 */
						preg_match_all('/("|“)([A-Za-z]{1})([A-Za-z,\'\s-]*)([A-Za-z]{1})("|”)/', $paragraph, $terms);

						/*
						 * If we've made any matches.
						 */
						if ( ($terms !== FALSE) && (count($terms) > 0) )
						{

							/*
							 * We only need the first element in this multi-dimensional array, which
							 * has the actual matched term. It includes the quotation marks in which
							 * the term is enclosed, so we strip those out.
							 */
							if ($quote_type == 'straight')
							{
								$terms = str_replace('"', '', $terms[0]);
							}
							elseif ($quote_type == 'directional')
							{
								$terms = str_replace('“', '', $terms[0]);
								$terms = str_replace('”', '', $terms);
							}

							/*
							 * Eliminate whitespace.
							 */
							$terms = array_map('trim', $terms);

							/* Lowercase most (but not necessarily all) terms. Any term that
							 * contains any lowercase characters will be made entirely lowercase.
							 * But any term that is in all caps is surely an acronym, and should be
							 * stored in its original case so that we don't end up with overzealous
							 * matches. For example, a two-letter acronym like "CA" is a valid
							 * (real-world) definition, and we don't want to match every time "ca"
							 * appears within a word. (Though note that we only match terms
							 * surrounded by word boundaries.)
							 */
							foreach ($terms as &$term)
							{
								/*
								 * Drop noise words that occur in lists of words.
								 */
								if (($term == 'and') || ($term == 'or'))
								{
									unset($term);
									continue;
								}

								/*
								 * Step through each character in this word.
								 */
								for ($i=0; $i<strlen($term); $i++)
								{
									/*
									 * If there are any lowercase characters, then make the whole
									 * thing lowercase.
									 */
									if ( (ord($term{$i}) >= 97) && (ord($term{$i}) <= 122) )
									{
										$term = strtolower($term);
										break;
									}
								}
							}

							/*
							 * This is absolutely necessary. Without it, the following foreach()
							 * loop will simply use $term as-is through each loop, rather than
							 * spawning new instances based on $terms. This is presumably a bug in
							 * the current version of PHP (5.2), because it surely doesn't make any
							 * sense.
							 */
							unset($term);

							/*
							 * Step through all of our matches and save them as discrete
							 * definitions.
							 */
							foreach ($terms as $term)
							{

								/*
								 * It's possible for a definition to be preceded by a subsection
								 * number. We want to pare down our definition down to the minimum,
								 * which means excluding that. Solution: Start definitions at the
								 * first quotation mark.
								 */
								if ($quote_type == 'straight')
								{
									$paragraph = substr($paragraph, strpos($paragraph, '"'));
								}
								elseif ($quote_type == 'directional')
								{
									$paragraph = substr($paragraph, strpos($paragraph, '“'));
								}

								/*
								 * Comma-separated lists of multiple words being defined need to
								 * have the trailing commas removed.
								 */
								if (substr($term, -1) == ',')
								{
									$term = substr($term, 0, -1);
								}

								/*
								 * If we don't yet have a record of this term.
								 */
								if (!isset($definitions[$term]))
								{
									/*
									 * Append this definition to our list of definitions.
									 */
									$definitions[$term] = $paragraph;
								}

								/* If we already have a record of this term. This is for when a word
								 * is defined twice, once to indicate what it means, and one to list
								 * what it doesn't mean. This is actually pretty common.
								 */
								else
								{
									/*
									 * Make sure that they're not identical -- this can happen if
									 * the defined term is repeated, in quotation marks, in the body
									 * of the definition.
									 */
									if ( trim($definitions[$term]) != trim($paragraph) )
									{
										/*
										 * Append this definition to our list of definitions.
										 */
										$definitions[$term] .= ' '.$paragraph;
									}
								}
							} // end iterating through matches
						} // end dealing with matches

						/*
						 * Because we have identified the linking phrase for this paragraph, we no
						 * longer need to continue to iterate through linking phrases.
						 */
						break;

					} // end matched linking phrase
				} // end iterating through linking phrases
			} // end this candidate paragraph

			/*
			 * We don't want to accidentally use this the next time we loop through.
			 */
			unset($terms);
		}

		if (count($definitions) == 0)
		{
			return FALSE;
		}

		/*
		 * Make the list of definitions a subset of a larger variable, so that we can store things
		 * other than terms.
		 */
		$tmp = array();
		$tmp['terms'] = $definitions;
		$tmp['scope'] = $scope;
		$definitions = $tmp;
		unset($tmp);

		/*
		 * Return our list of definitions, converted from an array to an object.
		 */
		return (object) $definitions;

	} // end extract_definitions()


	/**
	 * When provided with an object containing a list of terms, their definitions, their scope,
	 * and their section number, this will store them in the database.
	 */
	function store_definitions()
	{

		if ( !isset($this->terms) || !isset($this->law_id) || !isset($this->scope) )
		{
			return FALSE;
		}

		/*
		 * If we have no structure ID, just substitute NULL, to avoid creating blank entries in the
		 * structure_id column.
		 */
		if (!isset($this->structure_id))
		{
			$this->structure_id = 'NULL';
		}

		/*
		 * Iterate through our definitions to build up our SQL.
		 */

		/*
		 * Start assembling our SQL string.
		 */
		$sql = 'INSERT INTO dictionary (law_id, term, definition, scope, scope_specificity,
				structure_id, date_created)
				VALUES (:law_id, :term, :definition, :scope, :scope_specificity,
				:structure_id, now())';
		$statement = $this->db->prepare($sql);

		foreach ($this->terms as $term => $definition)
		{

			$sql_args = array(
				':law_id' => $this->law_id,
				':term' => $term,
				':definition' => $definition,
				':scope' => $this->scope,
				':scope_specificity' => $this->scope_specificity,
				':structure_id' => $this->structure_id
			);
			$result = $statement->execute($sql_args);

		}


		/*
		 * Memory management.
		 */
		unset($this);

		return $result;

	} // end store_definitions()


	function query($sql)
	{
		$result = $this->db->exec($sql);
		if ($result === FALSE)
		{
			return $this->db->errorInfo();
		}
		else
		{
			return TRUE;
		}
	}

	/**
	 * Find mentions of other sections within a section and return them as an array.
	 */
	function extract_references()
	{

		/*
		 * If we don't have any text to analyze, then there's nothing more to do be done.
		 */
		if (!isset($this->text))
		{
			return FALSE;
		}

		/*
		 * Find every string that fits the acceptable format for a state code citation.
		 */
		preg_match_all(SECTION_REGEX, $this->text, $matches);

		/*
		 * We don't need all of the matches data -- just the first set. (The others are arrays of
		 * subset matches.)
		 */
		$matches = $matches[0];

		/*
		 * We assign the count to a variable because otherwise we're constantly diminishing the
		 * count, meaning that we don't process the entire array.
		 */
		$total_matches = count($matches);
		for ($j=0; $j<$total_matches; $j++)
		{

			$matches[$j] = trim($matches[$j]);

			/*
			 * Lop off trailing periods, colons, and hyphens.
			 */
			if ( (substr($matches[$j], -1) == '.') || (substr($matches[$j], -1) == ':')
				|| (substr($matches[$j], -1) == '-') )
			{
				$matches[$j] = substr($matches[$j], 0, -1);
			}

		}

		/*
		 * Make unique, but with counts.
		 */
		$sections = array_count_values($matches);
		unset($matches);

		return $sections;

	} // end extract_references()


	/**
	 * Take an array of references to other sections contained within a section of text and store
	 * them in the database.
	 */
	function store_references()
	{

		/*
		 * If we don't have any section numbers or a section number to tie them to, then we can't
		 * do anything at all.
		 */
		if ( (!isset($this->sections)) || (!isset($this->section_id)) )
		{
			return FALSE;
		}

		/*
		 * Start creating our insertion query.
		 */
		$sql = 'INSERT INTO laws_references
				(law_id, target_section_number, mentions, date_created)
				VALUES (:law_id, :section_number, :mentions, now())
				ON DUPLICATE KEY UPDATE mentions=mentions';
				$statement = $this->db->prepare($sql);
		$i=0;
		foreach ($this->sections as $section => $mentions)
		{
			$sql_args = array(
				':law_id' => $this->section_id,
				':section_number' => $section,
				':mentions' => $mentions
			);

			$result = $statement->execute($sql_args);

			if ($result === FALSE)
			{
				echo '<p>Failed: '.$sql.'</p>';
				return FALSE;
			}
		}

		return TRUE;

	} // end store_references()


	/**
	 * Turn the history sections into atomic data.
	 */
	function extract_history()
	{

		/*
		 * If we have no history text, then we're done here.
		 */
		if (!isset($this->history))
		{
			return FALSE;
		}

		/*
		 * The list is separated by semicolons and spaces.
		 */
		$updates = explode('; ', $this->history);

		$i=0;
		foreach ($updates as &$update)
		{

			/*
			 * Match lines of the format "2010, c. 402, § 1-15.1"
			 */
			$pcre = '/([0-9]{4}), c\. ([0-9]+)(.*)/';

			/*
			 * First check for single matches.
			 */
			$result = preg_match($pcre, $update, $matches);
			if ( ($result !== FALSE) && ($result !== 0) )
			{

				if (!empty($matches[1]))
				{
					$final->{$i}->year = $matches[1];
				}
				if (!empty($matches[2]))
				{
					$final->{$i}->chapter = trim($matches[2]);
				}
				if (!empty($matches[3]))
				{
					$result = preg_match(SECTION_REGEX, $update, $matches[3]);
					if ( ($result !== FALSE) && ($result !== 0) )
					{
						$final->{$i}->section = $matches[0];
					}
				}

			}

			/*
			 * Then check for multiple matches.
			 */
			else
			{

				/*
				 * Match lines of the format "2009, cc. 401,, 518, 726, § 2.1-350.2"
				 */
				$pcre = '/([0-9]{2,4}), cc\. ([0-9,\s]+)/';
				$result = preg_match_all($pcre, $update, $matches);

				if ( ($result !== FALSE) && ($result !== 0) )
				{

					/*
					 * Save the year.
					 */
					$final->{$i}->year = $matches[1][0];

					/*
					 * Save the chapter listing. We eliminate any trailing slash and space to avoid
					 * saving empty array elements.
					 */
					$chapters = rtrim(trim($matches[2][0]), ',');

					/*
					 * We explode on a comma, rather than a comma and a space, because of occasional
					 * typographical errors in histories.
					 */
					$chapters = explode(',', $chapters);

					/*
					 * Step through each of these chapter references and trim down the leading
					 * spaces (a result of creating the array based on commas rather than commas and
					 * spaces) and eliminate any that are blank.
					 */
					$chapter_count = count($chapters);

					for ($j=0; $j<$chapter_count; $j++)
					{
						$chapters[$j] = trim($chapters[$j]);
						if (empty($chapters[$j]))
						{
							unset($chapters[$j]);
						}
					}

					$final->{$i}->chapter = $chapters;

					/*
					 * Locate any section identifier.
					 */
					$result = preg_match(SECTION_REGEX, $update, $matches);
					if ( ($result !== FALSE) && ($result !== 0) )
					{
						$final->{$i}->section = $matches[0];
					}

				}

			}

			$i++;

		}

		if ( isset($final) && is_object($final) )
		{
			return $final;
		}

	} // end extract_history()

	public function get_structure_labels()
	{
		$sql = 'SELECT label FROM structure GROUP BY label ' .
			'ORDER BY depth ASC';
		$statement = $this->db->prepare($sql);
		$result = $statement->execute();


		$structure_labels = array();

		if ( ($result === FALSE) )
		{
			echo '<p>Query failed: '.$sql.'</p>';
			var_dump($sql_args);
			return FALSE;
		}
		else
		{
			if($statement->rowCount() == 0)
			{
				/*
				 * We may not have a structure yet.  That's ok.
				 */
				return null;
			}
			else{
				while($row = $statement->fetch(PDO::FETCH_ASSOC))
				{
					$structure_labels[] = $row['label'];
				}
			}
		}

		/*
		 * Our lowest level, not represented in the structure table, is 'section'
		 */
		$structure_labels[] = 'Section';

		return $structure_labels;
	} // end get_structure_labels()

	/*
	 * Create a url-safe string.
	 */
	public function slugify($value)
	{
		$value = preg_replace('[^a-z0-9-]', '', $value);
		if(substr($value, -1, 1) === '.')
		{
			$value = substr($value, 0, -1);
		}
		return $value;
	}

} // end Parser class
