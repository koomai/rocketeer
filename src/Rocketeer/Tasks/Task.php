<?php
namespace Rocketeer\Tasks;

use Illuminate\Console\Command;
use Illuminate\Remote\Connection;
use Rocketeer\DeploymentsManager;
use Rocketeer\ReleasesManager;
use Rocketeer\Rocketeer;

/**
 * A Task to execute on the remote servers
 */
abstract class Task
{

	/**
	 * The Releases Manager instance
	 *
	 * @var ReleasesManager
	 */
	public $releasesManager;

	/**
	 * The Deployments Manager instance
	 *
	 * @var DeploymentsManager
	 */
	public $deploymentsManager;

	/**
	 * The Rocketeer instance
	 *
	 * @var Rocketeer
	 */
	public $rocketeer;

	/**
	 * The Remote instance
	 *
	 * @var Connection
	 */
	public $remote;

	/**
	 * The Command instance
	 *
	 * @var Command
	 */
	public $command;

	/**
	 * Build a new Task
	 *
	 * @param Rocketeer          $rocketeer
	 * @param ReleasesManager    $releasesManager
	 * @param DeploymentsManager $deploymentsManager
	 * @param Connection         $remote
	 * @param Command|null       $command
	 */
	public function __construct(Rocketeer $rocketeer, ReleasesManager $releasesManager, DeploymentsManager $deploymentsManager, Connection $remote, $command)
	{
		$this->releasesManager    = $releasesManager;
		$this->deploymentsManager = $deploymentsManager;
		$this->rocketeer          = $rocketeer;
		$this->remote             = $remote;
		$this->command            = $command;
	}

	/**
	 * Get the basic name of the Task
	 *
	 * @return string
	 */
	public function getSlug()
	{
		$name = get_class($this);
		$name = str_replace('\\', '/', $name);
		$name = basename($name);

		return strtolower($name);
	}

	/**
	 * Run the Task
	 *
	 * @return  void
	 */
	abstract public function execute();

	////////////////////////////////////////////////////////////////////
	/////////////////////////////// HELPERS ////////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Run actions on the remote server and gather the ouput
	 *
	 * @param  string|array $tasks One or more tasks
	 *
	 * @return string
	 */
	public function run($tasks)
	{
		$output = null;
		$tasks  = (array) $tasks;

		// Log the commands for pretend
		if ($this->command->option('pretend')) {
			return $this->command->line(implode(PHP_EOL, $tasks));
		}

		// Run tasks
		$this->remote->run($tasks, function($results) use (&$output) {
			$output .= $results;
		});

		// Print output
		$output = trim($output);
		if ($this->command->option('verbose')) {
			print $output;
		}

		return $output;
	}

	/**
	 * Run actions in a folder
	 *
	 * @param  string        $folder
	 * @param  string|array  $tasks
	 *
	 * @return string
	 */
	public function runInFolder($folder = null, $tasks = array())
	{
		$tasks = (array) $tasks;
		array_unshift($tasks, 'cd '.$this->rocketeer->getFolder($folder));

		return $this->run($tasks);
	}

	/**
	 * Run actions in the current release's folder
	 *
	 * @param  string|array $tasks One or more tasks
	 *
	 * @return string
	 */
	public function runForCurrentRelease($tasks)
	{
		return $this->runInFolder($this->releasesManager->getCurrentReleasePath(), $tasks);
	}

	/**
	 * Execute a Task
	 *
	 * @param  string $task
	 *
	 * @return string The Task's output
	 */
	public function executeTask($task)
	{
		$task = new $task($this->rocketeer, $this->releasesManager, $this->deploymentsManager, $this->remote, $this->command);

		return $task->execute();
	}

	/**
	 * Get a binary
	 *
	 * @param  string $binary       The name of the binary
	 * @param  string $fallback     A fallback location
	 *
	 * @return string
	 */
	public function which($binary, $fallback = null)
	{
		$location = $this->run('which '.$binary);
		if (!$location or $location == $binary. ' not found') {
			if (!is_null($fallback) and $this->run('which ' .$fallback) != $fallback. ' not found') {
				return $fallback;
			}

			return false;
		}

		return $location;
	}

	////////////////////////////////////////////////////////////////////
	//////////////////////////////// TASKS /////////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Run the application's tests
	 *
	 * @param string $arguments Additional arguments to pass to PHPUnit
	 *
	 * @return boolean
	 */
	public function runTests($arguments = null)
	{
		// Look for PHPUnit
		$phpunit = $this->which('phpunit', $this->releasesManager->getCurrentReleasePath().'/vendor/bin/phpunit');
		if (!$phpunit) return true;

		// Run PHPUnit
		$this->command->info('Running tests...');
		$output = $this->runForCurrentRelease(array(
			$phpunit. ' --stop-on-failure '.$arguments,
		));

		$testsSucceeded = str_contains($output, 'OK') or str_contains($output, 'No tests executed');
		if ($testsSucceeded) {
			$this->command->info('Tests ran with success');
		} else {
			print $output;
		}

		return $testsSucceeded;
	}

	/**
	 * Clone the repo into a release folder
	 *
	 * @return string
	 */
	public function cloneRelease()
	{
		$branch      = $this->rocketeer->getGitBranch();
		$repository  = $this->rocketeer->getGitRepository();
		$releasePath = $this->releasesManager->getCurrentReleasePath();

		$this->command->info('Cloning repository in "' .$releasePath. '"');

		return $this->run(sprintf('git clone -b %s %s %s', $branch, $repository, $releasePath));
	}

	/**
	 * Update the current symlink
	 *
	 * @param integer $release A release to mark as current
	 *
	 * @return string
	 */
	public function updateSymlink($release = null)
	{
		// If the release is specified, update to make it the current one
		if ($release) {
			$this->releasesManager->updateCurrentRelease($release);
		}

		$currentReleasePath = $this->releasesManager->getCurrentReleasePath();
		$currentFolder      = $this->rocketeer->getFolder('current');

		return $this->run(sprintf('ln -s %s %s', $currentReleasePath, $currentFolder));
	}

	/**
	 * Set a folder as web-writable
	 *
	 * @param string $folder
	 *
	 * @return  string
	 */
	public function setPermissions($folder)
	{
		$folder = $this->releasesManager->getCurrentReleasePath().'/'.$folder;
		$this->command->comment('Setting permissions for '.$folder);

		$output  = $this->run(array(
			'chmod -R +x ' .$folder,
			'chown -R www-data:www-data ' .$folder,
		));

		return $output;
	}

	////////////////////////////////////////////////////////////////////
	//////////////////////////////// VENDOR ////////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Run Composer on the folder
	 *
	 * @return string
	 */
	public function runComposer()
	{
		$this->command->comment('Installing Composer dependencies');

		return $this->runForCurrentRelease('composer install');
	}

	////////////////////////////////////////////////////////////////////
	/////////////////////////////// FOLDERS ////////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Create a folder in the application's folder
	 *
	 * @param  string $folder       The folder to create
	 *
	 * @return string The task
	 */
	public function createFolder($folder = null)
	{
		return $this->run('mkdir '.$this->rocketeer->getFolder($folder));
	}

	/**
	 * Remove a folder in the application's folder
	 *
	 * @param  string $folder       The folder to remove
	 *
	 * @return string The task
	 */
	public function removeFolder($folder = null)
	{
		return $this->run('rm -rf '.$this->rocketeer->getFolder($folder));
	}

}
