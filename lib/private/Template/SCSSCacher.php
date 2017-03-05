<?php
/**
 * @copyright Copyright (c) 2016, John Molakvoæ (skjnldsv@protonmail.com)
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Template;

use Leafo\ScssPhp\Compiler;
use Leafo\ScssPhp\Exception\ParserException;
use Leafo\ScssPhp\Formatter\Crunched;
use Leafo\ScssPhp\Formatter\Expanded;
use OC\SystemConfig;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IURLGenerator;

class SCSSCacher {

	/** @var ILogger */
	protected $logger;

	/** @var IAppData */
	protected $appData;

	/** @var IURLGenerator */
	protected $urlGenerator;

	/** @var IConfig */
	protected $config;

	/** @var string */
	protected $serverRoot;

	/**
	 * @param ILogger $logger
	 * @param IAppData $appData
	 * @param IURLGenerator $urlGenerator
	 * @param SystemConfig $systemConfig
	 * @param string $serverRoot
	 */
	public function __construct(ILogger $logger,
								IAppData $appData,
								IURLGenerator $urlGenerator,
								IConfig $config,
								$serverRoot) {
		$this->logger = $logger;
		$this->appData = $appData;
		$this->urlGenerator = $urlGenerator;
		$this->config = $config;
		$this->serverRoot = $serverRoot;
	}

	/**
	 * Process the caching process if needed
	 * @param string $root Root path to the nextcloud installation
	 * @param string $file
	 * @param string $app The app name
	 * @return boolean
	 */
	public function process($root, $file, $app) {
		$path = explode('/', $root . '/' . $file);

		$fileNameSCSS = array_pop($path);
		$fileNameCSS = str_replace('.scss', '.css', $fileNameSCSS);

		$path = implode('/', $path);

		$webDir = substr($path, strlen($this->serverRoot)+1);

		try {
			$folder = $this->appData->getFolder($app);
		} catch(NotFoundException $e) {
			// creating css appdata folder
			$folder = $this->appData->newFolder($app);
		}

		if($this->isCached($fileNameCSS, $fileNameSCSS, $folder, $path) && !$this->variablesChanged($fileNameCSS, $folder)) {
			return true;
		}
		return $this->cache($path, $fileNameCSS, $fileNameSCSS, $folder, $webDir);
	}

	/**
	 * Check if the file is cached or not
	 * @param string $fileNameCSS
	 * @param string $fileNameSCSS
	 * @param ISimpleFolder $folder
	 * @param string $path
	 * @return boolean
	 */
	private function isCached($fileNameCSS, $fileNameSCSS, ISimpleFolder $folder, $path) {
		try {
			$cachedFile = $folder->getFile($fileNameCSS);
			if ($cachedFile->getSize() > 0) {
				$depFile = $folder->getFile($fileNameCSS . '.deps');
				$deps = json_decode($depFile->getContent(), true);

				foreach ($deps as $file=>$mtime) {
					if (!file_exists($file) || filemtime($file) > $mtime) {
						return false;
					}
				}
			}
			return true;
		} catch(NotFoundException $e) {
			return false;
		}
		return false;
	}

	/**
	 * Check if the variables file has changed
	 * @param string $fileNameCSS
	 * @param ISimpleFolder $folder
	 * @return bool
	 */
	private function variablesChanged($fileNameCSS, ISimpleFolder $folder) {
		$variablesFile = \OC::$SERVERROOT . '/core/css/variables.scss';
		try {
			$cachedFile = $folder->getFile($fileNameCSS);
			if ($cachedFile->getMTime() < filemtime($variablesFile)
				|| $cachedFile->getSize() === 0
			) {
				return true;
			}
		} catch (NotFoundException $e) {
			return true;
		}
		return false;
	}

	/**
	 * Cache the file with AppData
	 * @param string $path
	 * @param string $fileNameCSS
	 * @param string $fileNameSCSS
	 * @param ISimpleFolder $folder
	 * @param string $webDir
	 * @return boolean
	 */
	private function cache($path, $fileNameCSS, $fileNameSCSS, ISimpleFolder $folder, $webDir) {
		$scss = new Compiler();
		$scss->setImportPaths([
			$path,
			\OC::$SERVERROOT . '/core/css/',
		]);
		if($this->config->getSystemValue('debug')) {
			// Debug mode
			$scss->setFormatter(Expanded::class);
			$scss->setLineNumberStyle(Compiler::LINE_COMMENTS);
		} else {
			// Compression
			$scss->setFormatter(Crunched::class);
		}

		try {
			$cachedfile = $folder->getFile($fileNameCSS);
		} catch(NotFoundException $e) {
			$cachedfile = $folder->newFile($fileNameCSS);
		}

		$depFileName = $fileNameCSS . '.deps';
		try {
			$depFile = $folder->getFile($depFileName);
		} catch (NotFoundException $e) {
			$depFile = $folder->newFile($depFileName);
		}

		// Compile
		try {
			$compiledScss = $scss->compile(
				'@import "variables.scss";' .
				'@import "'.$fileNameSCSS.'";');
		} catch(ParserException $e) {
			$this->logger->error($e, ['app' => 'core']);
			return false;
		}

		try {
			$cachedfile->putContent($this->rebaseUrls($compiledScss, $webDir));
			$depFile->putContent(json_encode($scss->getParsedFiles()));
			$this->logger->debug($webDir.'/'.$fileNameSCSS.' compiled and successfully cached', ['app' => 'core']);
			return true;
		} catch(NotFoundException $e) {
			return false;
		}
	}

	/**
	 * Add the correct uri prefix to make uri valid again
	 * @param string $css
	 * @param string $webDir
	 * @return string
	 */
	private function rebaseUrls($css, $webDir) {
		$re = '/url\([\'"]([\.\w?=\/-]*)[\'"]\)/x';
		// OC\Route\Router:75
		if(($this->config->getSystemValue('htaccess.IgnoreFrontController', false) === true || getenv('front_controller_active') === 'true')) {
			$subst = 'url(\'../../'.$webDir.'/$1\')';	
		} else {
			$subst = 'url(\'../../../'.$webDir.'/$1\')';
		}
		return preg_replace($re, $subst, $css);
	}

	/**
	 * Return the cached css file uri
	 * @param string $appName the app name
	 * @param string $fileName
	 * @return string
	 */
	public function getCachedSCSS($appName, $fileName) {
		$tmpfileLoc = explode('/', $fileName);
		$fileName = array_pop($tmpfileLoc);
		$fileName = str_replace('.scss', '.css', $fileName);

		return substr($this->urlGenerator->linkToRoute('core.Css.getCss', array('fileName' => $fileName, 'appName' => $appName)), strlen(\OC::$WEBROOT) + 1);
	}
}
