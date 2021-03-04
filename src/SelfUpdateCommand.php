<?php

namespace SelfUpdate;

use Composer\Semver\VersionParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem as sfFilesystem;

/**
 * Update the robo.phar from the latest github release
 *
 * @author Alexander Menk <alex.menk@gmail.com>
 */
class SelfUpdateCommand extends Command
{
    const SELF_UPDATE_COMMAND_NAME = 'self:update';

    protected $gitHubRepository;

    protected $currentVersion;

    protected $applicationName;

    public function __construct($applicationName = null, $currentVersion = null, $gitHubRepository = null)
    {
        $this->applicationName = $applicationName;
        $this->currentVersion = $currentVersion;
        $this->gitHubRepository = $gitHubRepository;

        parent::__construct(self::SELF_UPDATE_COMMAND_NAME);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $app = $this->applicationName;

        // Follow Composer's pattern of command and channel names.
        $this
            ->setAliases(array('update', 'self-update'))
            ->setDescription("Updates $app to the latest version.")
            ->addOption('stable', NULL, InputOption::VALUE_NONE, 'Use stable releases (default)')
            ->addOption('preview', NULL, InputOption::VALUE_NONE, 'Preview unstable (e.g., alpha, beta, etc.) releases')
            ->setHelp(
                <<<EOT
The <info>self-update</info> command checks github for newer
versions of $app and if found, installs the latest.
EOT
            );
    }

  /**
   * Get all releases, or only the latest.
   *
   * The "latest" release according to Github is the latest stable release based
   * on commit date.
   *
   * This method does no filtering, sorting, or logic beyond calling the GH API.
   *
   * @see https://docs.github.com/en/rest/reference/repos#get-the-latest-release
   *
   * @param false $latest_only
   *
   * @return mixed
   * @throws \Exception
   */
    protected function getReleasesFromGithub($latest_only = false)
    {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: ' . $this->applicationName  . ' (' . $this->gitHubRepository . ')' . ' Self-Update (PHP)'
                ]
            ]
        ];

        $context = stream_context_create($opts);

        $url = 'https://api.github.com/repos/' . $this->gitHubRepository . '/releases';
        $url .= $latest_only ? '/latest' : '';
        $releases = file_get_contents($url, false, $context);
        $releases = json_decode($releases);

        if (! isset($releases[0])) {
            throw new \Exception('API error - no release found at GitHub repository ' . $this->gitHubRepository);
        }
        return $releases;
    }

    protected function getLatestReleaseFromGithub()
    {
        $releases = $this->getReleasesFromGithub();
        return $this->findLatestRelease($releases, true);
    }

  /**
   * Get the latest stable release.
   *
   * This function is greedy and tries to get the latest release as determined
   * by the GitHub API. This will fail after new releases if the phar hasn't yet
   * built, in which case we fall back to checking all releases.
   *
   * @return array
   * @throws \Exception
   */
    protected function getLatestStableReleaseFromGithub()
    {
        // Try latest release first
        $release = $this->getReleasesFromGithub(true);
        if (count($release->assets)) {
          return [ $release->tag_name, $release->assets[0]->browser_download_url ];
        }

        // Check all releases.
        $releases = $this->getReleasesFromGithub();
        return $this->findLatestRelease($releases);

    }

  /**
   * Find the latest release in an array of releases, as returned by GH API.
   *
   * Be aware that you and Github might have different definitions of "latest".
   * We just take the first valid object returned by the releases API, but
   * because this API does not sort releases deterministically it can produce
   * unexpected results.
   *
   * @see https://github.com/consolidation/self-update/issues/9
   *
   * @param $releases
   * @param false $preview
   *
   * @return array
   * @throws \Exception
   */
    protected function findLatestRelease($releases, $preview = false) {
      foreach ($releases as $release) {
        $version = $release->tag_name;
        $url = $release->assets[0]->browser_download_url;
        if (count($release->assets) && ($preview || VersionParser::parseStability($version) === 'stable')) {
          return [$version, $url];
        }
      }

      throw new \Exception('API error - no release found at GitHub repository ' . $this->gitHubRepository);
    }

    public function getLatest($preview): array {
        if ($preview !== FALSE) {
            list($latest, $downloadUrl) = $this->getLatestReleaseFromGithub();
        }
        else {
            list($latest, $downloadUrl) = $this->getLatestStableReleaseFromGithub();
        }
        return [$latest, $downloadUrl];
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (empty(\Phar::running())) {
            throw new \Exception(self::SELF_UPDATE_COMMAND_NAME . ' only works when running the phar version of ' . $this->applicationName . '.');
        }

        $localFilename = realpath($_SERVER['argv'][0]) ?: $_SERVER['argv'][0];
        $programName   = basename($localFilename);
        $tempFilename  = dirname($localFilename) . '/' . basename($localFilename, '.phar') . '-temp.phar';

        // check for permissions in local filesystem before start connection process
        if (! is_writable($tempDirectory = dirname($tempFilename))) {
            throw new \Exception(
                $programName . ' update failed: the "' . $tempDirectory .
                '" directory used to download the temp file could not be written'
            );
        }

        if (! is_writable($localFilename)) {
            throw new \Exception(
                $programName . ' update failed: the "' . $localFilename . '" file could not be written (execute with sudo)'
            );
        }

        list($latest, $downloadUrl) = $this->getLatest($input->getOption('preview'));

        if ($this->currentVersion == $latest) {
            $output->writeln('No update available');
            return 0;
        }

        $fs = new sfFilesystem();

        $output->writeln('Downloading ' . $this->applicationName . ' (' . $this->gitHubRepository . ') ' . $latest);

        $fs->copy($downloadUrl, $tempFilename);

        $output->writeln('Download finished');

        try {
            \error_reporting(E_ALL); // supress notices

            @chmod($tempFilename, 0777 & ~umask());
            // test the phar validity
            $phar = new \Phar($tempFilename);
            // free the variable to unlock the file
            unset($phar);
            @rename($tempFilename, $localFilename);
            $output->writeln('<info>Successfully updated ' . $programName . '</info>');
            $this->_exit();
        } catch (\Exception $e) {
            @unlink($tempFilename);
            if (! $e instanceof \UnexpectedValueException && ! $e instanceof \PharException) {
                throw $e;
            }
            $output->writeln('<error>The download is corrupted (' . $e->getMessage() . ').</error>');
            $output->writeln('<error>Please re-run the self-update command to try again.</error>');
            return 1;
        }
    }

    /**
     * Stop execution
     *
     * This is a workaround to prevent warning of dispatcher after replacing
     * the phar file.
     *
     * @return void
     */
    protected function _exit()
    {
        exit;
    }
}
