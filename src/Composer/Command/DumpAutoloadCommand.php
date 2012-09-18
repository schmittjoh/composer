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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Composer\Repository\CompositeRepository;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Autoload\AutoloadGenerator;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DumpAutoloadCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('dump-autoload')
            ->setAliases(array('dumpautoload'))
            ->setDescription('dumps the autoloader')
            ->setDefinition(array(
                new InputOption('optimize', 'o', InputOption::VALUE_NONE, 'Optimizes PSR0 packages to be loaded with classmaps too, good for production.'),
            ))
            ->setHelp(<<<EOT
<info>php composer.phar dump-autoload</info>
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>Generating autoload files</info>');

        $composer = $this->getComposer();
        $installationManager = $composer->getInstallationManager();
        $localRepos = new CompositeRepository($composer->getRepositoryManager()->getLocalRepositories());
        $package = $composer->getPackage();
        $config = $composer->getConfig();

        $generator = new AutoloadGenerator();
        $generator->dump($config, $localRepos, $package, $installationManager, 'composer', $input->getOption('optimize'));
    }
}
