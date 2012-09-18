<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Command;

use Composer\Json\JsonFile;
use Composer\Factory;
use Composer\Package\BasePackage;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * @author Justin Rainbow <justin.rainbow@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InitCommand extends Command
{
    private $gitConfig;
    private $repos;

    public function parseAuthorString($author)
    {
        if (preg_match('/^(?P<name>[- \.,\w\'’]+) <(?P<email>.+?)>$/u', $author, $match)) {
            if (!function_exists('filter_var') || version_compare(PHP_VERSION, '5.3.3', '<') || $match['email'] === filter_var($match['email'], FILTER_VALIDATE_EMAIL)) {
                return array(
                    'name'  => trim($match['name']),
                    'email' => $match['email']
                );
            }
        }

        throw new \InvalidArgumentException(
            'Invalid author string.  Must be in the format: '.
            'John Smith <john@example.com>'
        );
    }

    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Creates a basic composer.json file in current directory.')
            ->setDefinition(array(
                new InputOption('name', null, InputOption::VALUE_REQUIRED, 'Name of the package'),
                new InputOption('description', null, InputOption::VALUE_REQUIRED, 'Description of package'),
                new InputOption('author', null, InputOption::VALUE_REQUIRED, 'Author name of package'),
                // new InputOption('version', null, InputOption::VALUE_NONE, 'Version of package'),
                new InputOption('homepage', null, InputOption::VALUE_REQUIRED, 'Homepage of package'),
                new InputOption('require', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Package to require with a version constraint, e.g. foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"'),
                new InputOption('require-dev', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Package to require for development with a version constraint, e.g. foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"'),
                new InputOption('minimum-stability', null, InputOption::VALUE_REQUIRED, 'Minimum stability (empty or one of: '.implode(', ', array_keys(BasePackage::$stabilities)).')'),
            ))
            ->setHelp(<<<EOT
The <info>init</info> command creates a basic composer.json file
in the current directory.

<info>php composer.phar init</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getHelperSet()->get('dialog');

        $whitelist = array('name', 'description', 'author', 'homepage', 'require', 'require-dev', 'minimum-stability');

        $options = array_filter(array_intersect_key($input->getOptions(), array_flip($whitelist)));

        if (isset($options['author'])) {
            $options['authors'] = $this->formatAuthors($options['author']);
            unset($options['author']);
        }

        $options['require'] = isset($options['require']) ? $this->formatRequirements($options['require']) : new \stdClass;
        if (array() === $options['require']) {
            $options['require'] = new \stdClass;
        }

        if (isset($options['require-dev'])) {
            $options['require-dev'] = $this->formatRequirements($options['require-dev']) ;
            if (array() === $options['require-dev']) {
                $options['require-dev'] = new \stdClass;
            }
        }

        $file = new JsonFile('composer.json');

        $json = $file->encode($options);

        if ($input->isInteractive()) {
            $output->writeln(array(
                '',
                $json,
                ''
            ));
            if (!$dialog->askConfirmation($output, $dialog->getQuestion('Do you confirm generation', 'yes', '?'), true)) {
                $output->writeln('<error>Command aborted</error>');

                return 1;
            }
        }

        $file->write($options);

        if ($input->isInteractive()) {
            $ignoreFile = realpath('.gitignore');

            if (false === $ignoreFile) {
                $ignoreFile = realpath('.') . '/.gitignore';
            }

            if (!$this->hasVendorIgnore($ignoreFile)) {
                $question = 'Would you like the <info>vendor</info> directory added to your <info>.gitignore</info> [<comment>yes</comment>]?';

                if ($dialog->askConfirmation($output, $question, true)) {
                    $this->addVendorIgnore($ignoreFile);
                }
            }
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $git = $this->getGitConfig();

        $dialog = $this->getHelperSet()->get('dialog');
        $formatter = $this->getHelperSet()->get('formatter');
        $output->writeln(array(
            '',
            $formatter->formatBlock('Welcome to the Composer config generator', 'bg=blue;fg=white', true),
            ''
        ));

        // namespace
        $output->writeln(array(
            '',
            'This command will guide you through creating your composer.json config.',
            '',
        ));

        $cwd = realpath(".");

        if (!$name = $input->getOption('name')) {
            $name = basename($cwd);
            if (isset($git['github.user'])) {
                $name = $git['github.user'] . '/' . $name;
            } elseif (!empty($_SERVER['USERNAME'])) {
                $name = $_SERVER['USERNAME'] . '/' . $name;
            } elseif (get_current_user()) {
                $name = get_current_user() . '/' . $name;
            } else {
                // package names must be in the format foo/bar
                $name = $name . '/' . $name;
            }
        }

        $name = $dialog->askAndValidate(
            $output,
            $dialog->getQuestion('Package name (<vendor>/<name>)', $name),
            function ($value) use ($name) {
                if (null === $value) {
                    return $name;
                }

                if (!preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}i', $value)) {
                    throw new \InvalidArgumentException(
                        'The package name '.$value.' is invalid, it should have a vendor name, a forward slash, and a package name, matching: [a-z0-9_.-]+/[a-z0-9_.-]+'
                    );
                }

                return $value;
            }
        );
        $input->setOption('name', $name);

        $description = $input->getOption('description') ?: false;
        $description = $dialog->ask(
            $output,
            $dialog->getQuestion('Description', $description)
        );
        $input->setOption('description', $description);

        if (null === $author = $input->getOption('author')) {
            if (isset($git['user.name']) && isset($git['user.email'])) {
                $author = sprintf('%s <%s>', $git['user.name'], $git['user.email']);
            }
        }

        $self = $this;
        $author = $dialog->askAndValidate(
            $output,
            $dialog->getQuestion('Author', $author),
            function ($value) use ($self, $author) {
                if (null === $value) {
                    return $author;
                }

                $author = $self->parseAuthorString($value);

                return sprintf('%s <%s>', $author['name'], $author['email']);
            }
        );
        $input->setOption('author', $author);

        $minimumStability = $input->getOption('minimum-stability') ?: '';
        $minimumStability = $dialog->askAndValidate(
            $output,
            $dialog->getQuestion('Minimum Stability', $minimumStability),
            function ($value) use ($self, $minimumStability) {
                if (null === $value) {
                    return $minimumStability;
                }

                if (!isset(BasePackage::$stabilities[$value])) {
                    throw new \InvalidArgumentException(
                        'Invalid minimum stability "'.$value.'". Must be empty or one of: '.
                        implode(', ', array_keys(BasePackage::$stabilities))
                    );
                }

                return $value;
            }
        );
        $input->setOption('minimum-stability', $minimumStability);

        $output->writeln(array(
            '',
            'Define your dependencies.',
            ''
        ));

        $requirements = array();
        if ($dialog->askConfirmation($output, $dialog->getQuestion('Would you like to define your dependencies (require) interactively', 'yes', '?'), true)) {
            $requirements = $this->determineRequirements($input, $output, $input->getOption('require'));
        }
        $input->setOption('require', $requirements);
        $devRequirements = array();
        if ($dialog->askConfirmation($output, $dialog->getQuestion('Would you like to define your dev dependencies (require-dev) interactively', 'yes', '?'), true)) {
            $devRequirements = $this->determineRequirements($input, $output, $input->getOption('require-dev'));
        }
        $input->setOption('require-dev', $devRequirements);
    }

    protected function findPackages($name)
    {
        $packages = array();

        // init repos
        if (!$this->repos) {
            $this->repos = new CompositeRepository(array_merge(
                array(new PlatformRepository),
                Factory::createDefaultRepositories($this->getIO())
            ));
        }

        $token = strtolower($name);

        $this->repos->filterPackages(function ($package) use ($token, &$packages) {
            if (false !== strpos($package->getName(), $token)) {
                $packages[] = $package;
            }
        });

        return $packages;
    }

    protected function determineRequirements(InputInterface $input, OutputInterface $output, $requires = array())
    {
        $dialog = $this->getHelperSet()->get('dialog');
        $prompt = $dialog->getQuestion('Search for a package', false, ':');

        if ($requires) {
            foreach ($requires as $key => $requirement) {
                $requires[$key] = $this->normalizeRequirement($requirement);
                if (false === strpos($requires[$key], ' ') && $input->isInteractive()) {
                    $question = $dialog->getQuestion('Please provide a version constraint for the '.$requirement.' requirement');
                    if ($constraint = $dialog->ask($output, $question)) {
                        $requires[$key] .= ' ' . $constraint;
                    }
                }
                if (false === strpos($requires[$key], ' ')) {
                    throw new \InvalidArgumentException('The requirement '.$requirement.' must contain a version constraint');
                }
            }

            return $requires;
        }

        while (null !== $package = $dialog->ask($output, $prompt)) {
            $matches = $this->findPackages($package);

            if (count($matches)) {
                $output->writeln(array(
                    '',
                    sprintf('Found <info>%s</info> packages matching <info>%s</info>', count($matches), $package),
                    ''
                ));

                foreach ($matches as $position => $package) {
                    $output->writeln(sprintf(' <info>%5s</info> %s <comment>%s</comment>', "[$position]", $package->getPrettyName(), $package->getPrettyVersion()));
                }

                $output->writeln('');

                $validator = function ($selection) use ($matches) {
                    if ('' === $selection) {
                        return false;
                    }

                    if (!is_numeric($selection) && preg_match('{^\s*(\S+) +(\S.*)\s*}', $selection, $matches)) {
                        return $matches[1].' '.$matches[2];
                    }

                    if (!isset($matches[(int) $selection])) {
                        throw new \Exception('Not a valid selection');
                    }

                    $package = $matches[(int) $selection];

                    return sprintf('%s %s', $package->getName(), $package->getPrettyVersion());
                };

                $package = $dialog->askAndValidate($output, $dialog->getQuestion('Enter package # to add, or a "[package] [version]" couple if it is not listed', false, ':'), $validator, 3);

                if (false !== $package) {
                    $requires[] = $package;
                }
            }
        }

        return $requires;
    }

    protected function formatAuthors($author)
    {
        return array($this->parseAuthorString($author));
    }

    protected function formatRequirements(array $requirements)
    {
        $requires = array();
        foreach ($requirements as $requirement) {
            $requirement = $this->normalizeRequirement($requirement);
            list($packageName, $packageVersion) = explode(" ", $requirement, 2);

            $requires[$packageName] = $packageVersion;
        }

        return $requires;
    }

    protected function normalizeRequirement($requirement)
    {
        return preg_replace('{^([^=: ]+)[=: ](.*)$}', '$1 $2', $requirement);
    }

    protected function getGitConfig()
    {
        if (null !== $this->gitConfig) {
            return $this->gitConfig;
        }

        $finder = new ExecutableFinder();
        $gitBin = $finder->find('git');

        $cmd = new Process(sprintf('%s config -l', escapeshellarg($gitBin)));
        $cmd->run();

        if ($cmd->isSuccessful()) {
            $this->gitConfig = array();
            preg_match_all('{^([^=]+)=(.*)$}m', $cmd->getOutput(), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $this->gitConfig[$match[1]] = $match[2];
            }

            return $this->gitConfig;
        }

        return $this->gitConfig = array();
    }

    /**
     * Checks the local .gitignore file for the Composer vendor directory.
     *
     * Tested patterns include:
     *  "/$vendor"
     *  "$vendor"
     *  "$vendor/"
     *  "/$vendor/"
     *  "/$vendor/*"
     *  "$vendor/*"
     *
     * @param string $ignoreFile
     * @param string $vendor
     *
     * @return bool
     */
    protected function hasVendorIgnore($ignoreFile, $vendor = 'vendor')
    {
        if (!file_exists($ignoreFile)) {
            return false;
        }

        $pattern = sprintf(
            '~^/?%s(/|/\*)?$~',
            preg_quote($vendor, '~')
        );

        $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    protected function addVendorIgnore($ignoreFile, $vendor = 'vendor')
    {
        $contents = "";
        if (file_exists($ignoreFile)) {
            $contents = file_get_contents($ignoreFile);

            if ("\n" !== substr($contents, 0, -1)) {
                $contents .= "\n";
            }
        }

        file_put_contents($ignoreFile, $contents . $vendor. "\n");
    }
}
