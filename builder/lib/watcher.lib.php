<?php

/*!
 * Pattern Lab Watcher Class - v0.3.0
 *
 * Copyright (c) 2013 Dave Olsen, http://dmolsen.com
 * Licensed under the MIT license
 *
 * Watches the source/ dir for any changes so those changes can be automagically
 * moved to the public/ dir. Watches static files, patterns, and data files
 *
 * This is not the most efficient implementation of a directory watch but I hope
 * it's the most platform agnostic.
 *
 */

class Watcher extends Builder {
	
	/**
	* Use the Builder __construct to gather the config variables
	*/
	public function __construct() {
		
		// construct the parent
		parent::__construct();
		
	}
	
	/**
	* Watch the source/ directory for any changes to existing files. Will run forever if given the chance.
	*/
	public function watch() {
		
		$c  = false;          // track that one loop through the pattern file listing has completed
		$o  = new stdClass(); // create an object to hold the properties
		$cp = new StdClass(); // create an object to hold a clone of $o
		
		$o->patterns = new stdClass();
		
		// run forever
		while (true) {
			
			// clone the patterns so they can be checked in case something gets deleted
			$cp = clone $o->patterns;
			
			// iterate over the patterns & related data and regenerate the entire site if they've changed
			$patternObjects  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__."/../../source/_patterns/"), RecursiveIteratorIterator::SELF_FIRST);
			
			// make sure dots are skipped
			$patternObjects->setFlags(FilesystemIterator::SKIP_DOTS);
			
			foreach($patternObjects as $name => $object) {
					
				// clean-up the file name and make sure it's not one of the pattern lab files or to be ignored
				$fileName      = str_replace(__DIR__."/../../source/_patterns".DIRECTORY_SEPARATOR,"",$name);
				$fileNameClean = str_replace(DIRECTORY_SEPARATOR."_",DIRECTORY_SEPARATOR,$fileName);
				
				if ($object->isFile() && (($object->getExtension() == "mustache") || ($object->getExtension() == "json"))) {
					
					// make sure this isn't a hidden pattern
					$patternParts = explode(DIRECTORY_SEPARATOR,$fileName);
					$pattern      = isset($patternParts[2]) ? $patternParts[2] : $patternParts[1];
					if ($pattern[0] != "_") {
						
						// make sure the pattern still exists in source just in case it's been deleted during the iteration
						if (file_exists($name)) {
							
							$mt = $object->getMTime();
							if (isset($o->patterns->$fileName) && ($o->patterns->$fileName != $mt)) {
								$o->patterns->$fileName = $mt;
								$this->updateSite($fileName,"changed");
							} else if (!isset($o->patterns->$fileName) && $c) {
								$o->patterns->$fileName = $mt;
								$this->updateSite($fileName,"added");
								if ($object->getExtension() == "mustache") {
									$this->patternPaths[$patternParts[0]][$pattern] = str_replace(".mustache","",$fileName);
								}
							} else if (!isset($o->patterns->$fileName)) {
								$o->patterns->$fileName = $mt;
							}
							
							if ($c && isset($o->patterns->$fileName)) {
								unset($cp->$fileName);
							}
							
						} else {
							
							// the file was removed during the iteration so remove references to the item
							unset($o->patterns->$fileName);
							unset($cp->$fileName);
							unset($this->patternPaths[$patternParts[0]][$pattern]);
							$this->updateSite($fileName,"removed");
							
						}
						
					} elseif (isset($o->patterns->$fileNameClean)) {
						
						// the file was hidden so remove references to the item
						$patternParts = explode(DIRECTORY_SEPARATOR,$fileNameClean);
						$pattern      = isset($patternParts[2]) ? $patternParts[2] : $patternParts[1];
						
						unset($o->patterns->$fileNameClean);
						unset($cp->$fileNameClean);
						unset($this->patternPaths[$patternParts[0]][$pattern]);
						$this->updateSite($fileNameClean,"hidden");
						
					}
					
				}
				
			}
			
			// make sure old entries are deleted
			// will throw "pattern not found" errors if an entire directory is removed at once but that shouldn't be a big deal
			if ($c) {
				
				foreach($cp as $fileName => $mt) {
					
					unset($o->patterns->$fileName);
					$patternParts = explode(DIRECTORY_SEPARATOR,$fileName);
					$pattern = isset($patternParts[2]) ? $patternParts[2] : $patternParts[1];
					
					unset($this->patternPaths[$patternParts[0]][$pattern]);
					$this->updateSite($fileName,"removed");
					
				}
				
			}
			
			// iterate over the data files and regenerate the entire site if they've changed
			$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__."/../../source/_data/"), RecursiveIteratorIterator::SELF_FIRST);
			
			// make sure dots are skipped
			$objects->setFlags(FilesystemIterator::SKIP_DOTS);
			
			foreach($objects as $name => $object) {
				
				$fileName = str_replace(__DIR__."/../../source/_data".DIRECTORY_SEPARATOR,"",$name);
				$mt = $object->getMTime();
				
				if (!isset($o->$fileName)) {
					$o->$fileName = $mt;
				} else if ($o->$fileName != $mt) {
					$o->$fileName = $mt;
					$this->updateSite($fileName,"changed");
				}
				
			}
			
			// iterate over all of the other files in the source directory and move them if their modified time has changed
			$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__."/../../source/"), RecursiveIteratorIterator::SELF_FIRST);
			
			// make sure dots are skipped
			$objects->setFlags(FilesystemIterator::SKIP_DOTS);
			
			foreach($objects as $name => $object) {
				
				// clean-up the file name and make sure it's not one of the pattern lab files or to be ignored
				$fileName = str_replace(__DIR__."/../../source".DIRECTORY_SEPARATOR,"",$name);
				if (($fileName[0] != "_") && (!in_array($object->getExtension(),$this->ie)) && (!in_array($object->getFilename(),$this->id))) {
					
					// catch directories that have the ignored dir in their path
					$ignoreDir = $this->ignoreDir($fileName);
					
					// check to see if it's a new directory
					if (!$ignoreDir && $object->isDir() && !isset($o->$fileName) && !is_dir(__DIR__."/../../public/".$fileName)) {
						mkdir(__DIR__."/../../public/".$fileName);
						$o->$fileName = "dir created"; // placeholder
						print $fileName."/ directory was created...\n";
					}
					
					// check to see if it's a new file or a file that has changed
					if (file_exists($name)) {
						
						$mt = $object->getMTime();
						if (!$ignoreDir && $object->isFile() && !isset($o->$fileName) && !file_exists(__DIR__."/../../public/".$fileName)) {
							$o->$fileName = $mt;
							$this->moveStaticFile($fileName,"added");
						} else if (!$ignoreDir && $object->isFile() && isset($o->$fileName) && ($o->$fileName != $mt)) {
							$o->$fileName = $mt;
							$this->moveStaticFile($fileName,"changed");
						} else if (!isset($o->fileName)) {
							$o->$fileName = $mt;
						}
						
					} else {
						unset($o->$fileName);
					}
					
				}
				
			}
			
			$c = true;
			
			// taking out the garbage. basically killing mustache after each run.
			unset($this->mpl);
			unset($this->msf);
			if (gc_enabled()) gc_collect_cycles();
			
		}
		
	}
	
	/**
	* Updates the Pattern Lab Website and prints the appropriate message
	* @param  {String}       file name to included in the message
	* @param  {String}       a switch for decided which message isn't printed
	*
	* @return {String}       the final message
	*/
	private function updateSite($fileName,$message) {
		$this->gatherData();
		$this->gatherPatternPaths();
		$this->gatherNavItems();
		$this->generatePatterns();
		$this->generateViewAllPages();
		$this->updateChangeTime();
		$this->generateMainPages();
		if ($message == "added") {
			print $fileName." was added to Pattern Lab. Reload the website to see this change in the navigation...\n";
		} elseif ($message == "removed") {
			print $fileName." was removed from Pattern Lab. Reload the website to see this change reflected in the navigation...\n";
		} elseif ($message == "hidden") {
			print $fileName." was hidden from Pattern Lab. Reload the website to see this change reflected in the navigation...\n";
		} else {
			print $fileName." changed...\n";
		}
	}
	
}
